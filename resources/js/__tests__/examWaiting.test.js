import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { effectScope, nextTick } from 'vue';
import { useCompetitionExam, SCREEN } from '../composables/useCompetitionExam';

/*
| The transition between two fixed slots.
|
| Under the fixed timeline a contestant who answers early does not get the next
| question early — the server hands back `question: null` with a `waiting`
| payload, and the client has to render that as a wait rather than as the end
| of the exam. Getting this wrong drops a contestant on the results screen with
| seventy questions unanswered, which is why it is tested on its own.
|
| The countdown here is the server's, exactly as on the question screen: it is
| anchored to `seconds_remaining`, it survives a remount, and reaching zero
| ASKS the server rather than concluding anything.
*/

const IN_PROGRESS = 'in_progress';

function statusPayload(overrides = {}) {
    return {
        competition: 'المسابقة الطلابيّة',
        status: 'open',
        open: true,
        reason: null,
        total_questions: 5,
        seconds_per_question: 40,
        show_result: false,
        exam_duration_minutes: 60,
        seconds_available: 3600,
        starts_at: null,
        ends_at: null,
        server_time: new Date().toISOString(),
        participation: { exam_status: IN_PROGRESS, account_status: 'created' },
        ...overrides,
    };
}

function questionPayload(sequence = 1) {
    return {
        question_id: 5000 + sequence,
        question_text: `سؤال ${sequence}`,
        options: { A: 'أ', B: 'ب', C: 'ج', D: 'د' },
        sequence,
        total_questions: 5,
        opened_at: new Date().toISOString(),
        expires_at: new Date().toISOString(),
        server_time: new Date().toISOString(),
        seconds_remaining: 40,
    };
}

function waitingPayload(sequence = 2, secondsRemaining = 35) {
    return {
        sequence,
        total_questions: 5,
        opens_at: new Date().toISOString(),
        server_time: new Date().toISOString(),
        seconds_remaining: secondsRemaining,
    };
}

/** An API double whose exam state the test drives directly. */
function fakeApi(initial) {
    const state = { ...initial };

    return {
        state,
        status: vi.fn(async () => statusPayload()),
        login: vi.fn(async () => ({ user: { id: 1 } })),
        logout: vi.fn(async () => ({ message: 'Logged out.' })),
        start: vi.fn(async () => ({ exam_status: IN_PROGRESS, started_at: 'x', ...state })),
        current: vi.fn(async () => ({ exam_status: IN_PROGRESS, started_at: 'x', ...state })),
        answer: vi.fn(async () => ({
            accepted: true,
            sequence: 1,
            exam_status: state.exam_status ?? IN_PROGRESS,
            next_question: state.question,
            waiting: state.waiting,
        })),
        result: vi.fn(async () => ({ exam_status: 'completed', show_result: false })),
    };
}

function build(api) {
    const scope = effectScope();

    return { exam: scope.run(() => useCompetitionExam(api)), stop: () => scope.stop() };
}

describe('the waiting state between two fixed slots', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders a wait, not a completion, when in progress with no question', async () => {
        const api = fakeApi({ question: null, waiting: waitingPayload(2, 35) });
        const { exam } = build(api);

        await exam.boot();
        await exam.start();

        expect(exam.screen.value).toBe(SCREEN.WAITING);
        expect(exam.question.value).toBeNull();
        expect(exam.waiting.value.sequence).toBe(2);
        // The failure this guards: a waiting contestant shown the result screen.
        expect(exam.screen.value).not.toBe(SCREEN.COMPLETED);
        expect(api.result).not.toHaveBeenCalled();
    });

    it('anchors the countdown to the server seconds, not to a fresh window', async () => {
        const api = fakeApi({ question: null, waiting: waitingPayload(2, 35) });
        const { exam } = build(api);

        await exam.boot();
        await exam.start();

        expect(exam.timer.seconds.value).toBe(35);

        vi.advanceTimersByTime(10_000);
        await nextTick();

        expect(exam.timer.seconds.value).toBeLessThanOrEqual(25);
    });

    it('asks the server when the wait reaches zero rather than advancing itself', async () => {
        const api = fakeApi({ question: null, waiting: waitingPayload(2, 1) });
        const { exam } = build(api);

        await exam.boot();
        await exam.start();

        expect(exam.screen.value).toBe(SCREEN.WAITING);

        // The slot opens on the server while the client is waiting.
        api.state.question = questionPayload(2);
        api.state.waiting = null;

        const callsBefore = api.current.mock.calls.length;

        vi.advanceTimersByTime(1_500);
        await vi.runOnlyPendingTimersAsync();
        await nextTick();

        expect(api.current.mock.calls.length).toBeGreaterThan(callsBefore);
        expect(exam.screen.value).toBe(SCREEN.EXAM);
        expect(exam.question.value.sequence).toBe(2);
    });

    it('moves to the question screen once the slot opens', async () => {
        const api = fakeApi({ question: null, waiting: waitingPayload(2, 35) });
        const { exam } = build(api);

        await exam.boot();
        await exam.start();
        expect(exam.screen.value).toBe(SCREEN.WAITING);

        api.state.question = questionPayload(2);
        api.state.waiting = null;

        await exam.refreshCurrent();

        expect(exam.screen.value).toBe(SCREEN.EXAM);
        expect(exam.waiting.value).toBeNull();
        expect(exam.question.value.sequence).toBe(2);
        expect(exam.timer.seconds.value).toBe(40);
    });

    it('goes to the waiting screen straight from a submitted answer', async () => {
        const api = fakeApi({ question: questionPayload(1), waiting: null });
        const { exam } = build(api);

        await exam.boot();
        await exam.start();
        expect(exam.screen.value).toBe(SCREEN.EXAM);

        // The answer lands and the next slot is not open yet.
        api.state.question = null;
        api.state.waiting = waitingPayload(2, 38);

        exam.select('A');
        await exam.submitAnswer();

        expect(exam.screen.value).toBe(SCREEN.WAITING);
        expect(exam.waiting.value.sequence).toBe(2);
        expect(exam.timer.seconds.value).toBe(38);
    });

    it('still completes when in progress with neither a question nor a wait', async () => {
        const api = fakeApi({ question: null, waiting: null });
        const { exam } = build(api);

        await exam.boot();
        await exam.start();

        expect(exam.screen.value).toBe(SCREEN.COMPLETED);
        expect(api.result).toHaveBeenCalled();
    });

    it('completes when the server says completed, whatever else it sends', async () => {
        const api = fakeApi({ question: null, waiting: waitingPayload(2, 35) });
        api.current = vi.fn(async () => ({
            exam_status: 'completed',
            started_at: 'x',
            question: null,
            waiting: waitingPayload(2, 35),
        }));
        const { exam } = build(api);

        await exam.boot();
        await exam.refreshCurrent();

        expect(exam.screen.value).toBe(SCREEN.COMPLETED);
    });

    it('clears the wait on logout', async () => {
        const api = fakeApi({ question: null, waiting: waitingPayload(2, 35) });
        const { exam } = build(api);

        await exam.boot();
        await exam.start();
        expect(exam.waiting.value).not.toBeNull();

        await exam.logout();

        expect(exam.waiting.value).toBeNull();
        expect(exam.screen.value).toBe(SCREEN.LOGIN);
        expect(exam.timer.seconds.value).toBeNull();
    });
});
