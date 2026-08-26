import { computed, reactive, readonly, ref } from 'vue';
import defaultApi from '../api/competitionApi';
import { ApiError } from '../api/http';
import { useCountdown } from './useCountdown';

/*
| The contestant flow, as one explicit state machine.
|
| Screens are chosen by `screen`; there is no router, because the flow is
| linear and gated by the server rather than by URLs — and a URL a contestant
| could type would only invite them to try skipping a gate.
|
| The governing rule of this file: THE SERVER DECIDES. The client never
| concludes that a question timed out, never concludes that an answer landed,
| and never advances on its own. Whenever it does not know, it re-reads
| /exam/current and renders whatever comes back.
*/

export const SCREEN = {
    BOOT: 'boot',
    LOGIN: 'login',
    GATE: 'gate', // not open / closed / no competition / not a contestant
    READY: 'ready', // start or resume
    EXAM: 'exam',
    COMPLETED: 'completed',
};

/** Reasons that end the flow rather than annotate it. */
const GATE_REASONS = [
    'competition_closed',
    'competition_not_open',
    'no_competition',
    'not_a_contestant',
    'account_not_provisioned',
    'paper_not_ready',
];

export function useCompetitionExam(api = defaultApi) {
    const screen = ref(SCREEN.BOOT);

    /** The last non-fatal failure, as a reason code. Never a server string. */
    const error = ref(null);
    /** Set when the whole screen is the failure, rather than a banner on one. */
    const fatalReason = ref(null);

    const busy = reactive({
        boot: false,
        login: false,
        start: false,
        answer: false,
        recover: false,
    });

    const competition = reactive({
        name: null,
        status: null,
        open: false,
        reason: null,
        totalQuestions: null,
        secondsPerQuestion: null,
        showResult: false,
    });

    const participation = ref(null); // {exam_status, account_status} | null
    const authenticated = ref(false);

    const question = ref(null);
    const selected = ref(null);
    const result = ref(null);
    const fieldErrors = ref(null);

    /**
     * True from the moment the visible countdown hits zero, or a submission
     * becomes uncertain, until the server has said what replaced the question.
     * Interaction is dead for as long as it is set.
     */
    const awaitingServer = ref(false);

    const secondsPerQuestion = computed(() => competition.secondsPerQuestion ?? null);

    const countdown = useCountdown({
        warningAt: 10,
        onExpire: () => {
            // NOT a decision that the question expired — only a decision to
            // stop accepting input and go ask the server what is true now.
            awaitingServer.value = true;
            void refreshCurrent();
        },
    });

    const timerFraction = countdown.fractionFor(secondsPerQuestion);

    const canAnswer = computed(
        () =>
            screen.value === SCREEN.EXAM &&
            question.value !== null &&
            !awaitingServer.value &&
            !countdown.isExpired.value &&
            !busy.answer,
    );

    // ────────────────────────────────────────────────────── transitions ────

    function clearErrors() {
        error.value = null;
        fieldErrors.value = null;
    }

    function reasonOf(failure) {
        return failure instanceof ApiError ? failure.reason : 'unknown';
    }

    function toGate(reason) {
        countdown.sync(null);
        question.value = null;
        awaitingServer.value = false;
        fatalReason.value = reason;
        screen.value = SCREEN.GATE;
    }

    function showQuestion(payload) {
        question.value = payload;
        selected.value = null;
        awaitingServer.value = false;
        screen.value = SCREEN.EXAM;
        // Re-anchored from the server on every payload, so a refresh or a
        // remount inherits the remaining time instead of restarting it.
        countdown.sync(payload.seconds_remaining);
    }

    async function toCompleted() {
        countdown.sync(null);
        question.value = null;
        selected.value = null;
        awaitingServer.value = false;
        screen.value = SCREEN.COMPLETED;

        try {
            result.value = await api.result();
        } catch (failure) {
            // The completion screen still stands without the score block.
            error.value = reasonOf(failure);
        }
    }

    /** An envelope from /exam/start, /exam/current, or the tail of /exam/answer. */
    async function applyEnvelope(envelope) {
        // A contestant who has not begun has no question and is not finished.
        // Without this branch an empty envelope would read as "completed".
        if (envelope.exam_status === 'not_started') {
            countdown.sync(null);
            question.value = null;
            selected.value = null;
            awaitingServer.value = false;
            screen.value = SCREEN.READY;

            return;
        }

        if (envelope.exam_status === 'completed' || !envelope.question) {
            await toCompleted();

            return;
        }

        showQuestion(envelope.question);
    }

    /** Failures that mean "this screen is over", wherever they are raised. */
    async function handleTerminal(failure) {
        const reason = reasonOf(failure);

        if (reason === 'unauthenticated') {
            authenticated.value = false;
            countdown.sync(null);
            question.value = null;
            screen.value = SCREEN.LOGIN;

            return true;
        }

        if (GATE_REASONS.includes(reason)) {
            toGate(reason);

            return true;
        }

        if (reason === 'exam_completed') {
            await toCompleted();

            return true;
        }

        return false;
    }

    // ──────────────────────────────────────────────────────── the flow ────

    /** Entry point, and the recovery path for every "I do not know" branch. */
    async function boot() {
        busy.boot = true;
        clearErrors();
        fatalReason.value = null;

        try {
            const status = await api.status();

            competition.name = status.competition;
            competition.status = status.status;
            competition.open = Boolean(status.open);
            competition.reason = status.reason;
            competition.totalQuestions = status.total_questions ?? null;
            competition.secondsPerQuestion = status.seconds_per_question ?? null;
            competition.showResult = Boolean(status.show_result);

            // The key exists only when the request was authenticated, which is
            // how a public status response is told apart from a contestant's.
            authenticated.value = Object.prototype.hasOwnProperty.call(status, 'participation');
            participation.value = status.participation ?? null;

            if (!competition.open) {
                toGate(status.reason ?? 'competition_not_open');

                return;
            }

            if (!authenticated.value) {
                screen.value = SCREEN.LOGIN;

                return;
            }

            if (participation.value === null) {
                toGate('not_a_contestant');

                return;
            }

            if (participation.value.account_status && participation.value.account_status !== 'created') {
                toGate('account_not_provisioned');

                return;
            }

            if (participation.value.exam_status === 'completed') {
                await toCompleted();

                return;
            }

            screen.value = SCREEN.READY;
        } catch (failure) {
            if (!(await handleTerminal(failure))) {
                fatalReason.value = reasonOf(failure);
                screen.value = SCREEN.GATE;
            }
        } finally {
            busy.boot = false;
        }
    }

    async function login(credentials) {
        busy.login = true;
        clearErrors();

        try {
            await api.login(credentials);
            authenticated.value = true;
            await boot();

            return true;
        } catch (failure) {
            error.value = reasonOf(failure);
            fieldErrors.value = failure instanceof ApiError ? failure.fields : null;

            return false;
        } finally {
            busy.login = false;
        }
    }

    async function logout() {
        try {
            await api.logout();
        } catch {
            // A failed logout still ends the client session: the cookie is
            // either gone already, or will be refused on the next call.
        }

        authenticated.value = false;
        participation.value = null;
        result.value = null;
        question.value = null;
        selected.value = null;
        countdown.sync(null);
        clearErrors();
        screen.value = SCREEN.LOGIN;
    }

    /** Start or resume — one call, because the backend does not distinguish them. */
    async function start() {
        busy.start = true;
        clearErrors();

        try {
            await applyEnvelope(await api.start());
        } catch (failure) {
            if (!(await handleTerminal(failure))) {
                error.value = reasonOf(failure);
            }
        } finally {
            busy.start = false;
        }
    }

    /**
     * The question id of a submission whose outcome we never learned. Set
     * before a recovery read so that, once the server answers, we can tell
     * "your answer landed and we moved on" from "it did not, try again".
     */
    let unconfirmedQuestionId = null;

    /** The single source of truth after any uncertainty. */
    async function refreshCurrent() {
        busy.recover = true;

        try {
            const pending = unconfirmedQuestionId;
            unconfirmedQuestionId = null;

            await applyEnvelope(await api.current());
            clearErrors();

            /*
             * The server still has the same question awaiting an answer, so
             * the submission did NOT land. Say so — silently re-rendering the
             * question with the selection cleared would look like a glitch,
             * and the contestant needs to choose again before the clock runs.
             */
            if (pending !== null && question.value?.question_id === pending) {
                error.value = 'answer_unconfirmed';
            }
        } catch (failure) {
            if (!(await handleTerminal(failure))) {
                // Still unknown. The UI stays frozen and offers a retry rather
                // than inventing a next question.
                error.value = reasonOf(failure);
                awaitingServer.value = true;
            }
        } finally {
            busy.recover = false;
        }
    }

    function select(option) {
        if (!canAnswer.value) {
            return;
        }

        selected.value = option;
    }

    async function submitAnswer() {
        if (!canAnswer.value || selected.value === null) {
            return;
        }

        busy.answer = true;
        clearErrors();

        const questionId = question.value.question_id;

        try {
            const outcome = await api.answer(questionId, selected.value);

            await applyEnvelope({
                exam_status: outcome.exam_status,
                question: outcome.next_question ?? null,
            });
        } catch (failure) {
            if (await handleTerminal(failure)) {
                return;
            }

            const reason = reasonOf(failure);
            const uncertain = failure instanceof ApiError && failure.isIndeterminate;

            // question_expired / question_not_available are the server telling
            // us our view is stale. A dropped or 5xx submission may or may not
            // have been recorded. Both are the same problem: we do not know
            // which question is live. Asking is the only way to avoid both a
            // duplicate answer and a silently skipped one.
            if (uncertain || reason === 'question_expired' || reason === 'question_not_available') {
                error.value = reason;
                awaitingServer.value = true;
                unconfirmedQuestionId = uncertain ? questionId : null;
                await refreshCurrent();

                return;
            }

            error.value = reason;
        } finally {
            busy.answer = false;
        }
    }

    return {
        SCREEN,
        screen: readonly(screen),
        competition: readonly(competition),
        participation: readonly(participation),
        authenticated: readonly(authenticated),
        question: readonly(question),
        selected: readonly(selected),
        result: readonly(result),
        error: readonly(error),
        fieldErrors: readonly(fieldErrors),
        fatalReason: readonly(fatalReason),
        busy: readonly(busy),
        awaitingServer: readonly(awaitingServer),
        canAnswer,
        timer: {
            seconds: countdown.seconds,
            isExpired: countdown.isExpired,
            isWarning: countdown.isWarning,
            fraction: timerFraction,
        },
        boot,
        login,
        logout,
        start,
        select,
        submitAnswer,
        refreshCurrent,
        clearErrors,
    };
}
