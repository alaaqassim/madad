import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { effectScope, nextTick } from 'vue';
import { useCompetitionExam, SCREEN } from '../composables/useCompetitionExam';
import { ApiError } from '../api/http';

/*
| The state machine, driven by a stub API.
|
| Every stub response below is shaped exactly like the real backend's
| (routes/web.php + CompetitionExamService): the question nested under
| `question`, the answer response carrying `next_question`, and never a
| correct_option or an is_correct anywhere.
*/

function question(sequence, { seconds = 40, total = 3 } = {}) {
    return {
        question_id: 100 + sequence,
        question_text: `سؤال ${sequence}`,
        options: { A: 'أ', B: 'ب', C: 'ج', D: 'د' },
        sequence,
        total_questions: total,
        opened_at: '2026-08-26T10:00:00+00:00',
        expires_at: '2026-08-26T10:00:40+00:00',
        server_time: '2026-08-26T10:00:00+00:00',
        seconds_remaining: seconds,
    };
}

function statusBody(overrides = {}) {
    return {
        competition: 'المسابقة الطلابيّة',
        status: 'open',
        open: true,
        reason: null,
        total_questions: 3,
        seconds_per_question: 40,
        show_result: false,
        server_time: '2026-08-26T10:00:00+00:00',
        ...overrides,
    };
}

/** Authenticated status responses carry the `participation` key; public ones do not. */
function authedStatus(examStatus = 'not_started', overrides = {}) {
    return statusBody({
        participation: { exam_status: examStatus, account_status: 'created' },
        ...overrides,
    });
}

function stubApi(overrides = {}) {
    return {
        login: vi.fn().mockResolvedValue({ user: { id: 1 } }),
        logout: vi.fn().mockResolvedValue({}),
        status: vi.fn().mockResolvedValue(authedStatus()),
        start: vi.fn().mockResolvedValue({ exam_status: 'in_progress', started_at: null, question: question(1) }),
        current: vi.fn().mockResolvedValue({ exam_status: 'in_progress', started_at: null, question: question(1) }),
        answer: vi.fn().mockResolvedValue({ accepted: true, sequence: 1, exam_status: 'in_progress', next_question: question(2) }),
        result: vi.fn().mockResolvedValue({ exam_status: 'completed', completed_at: null, show_result: false }),
        ...overrides,
    };
}

function build(api) {
    const scope = effectScope();

    return { exam: scope.run(() => useCompetitionExam(api)), dispose: () => scope.stop() };
}

describe('useCompetitionExam — competition status', () => {
    it('sends an unauthenticated visitor to the login screen', async () => {
        const api = stubApi({ status: vi.fn().mockResolvedValue(statusBody()) });
        const { exam } = build(api);

        await exam.boot();

        expect(exam.screen.value).toBe(SCREEN.LOGIN);
        expect(exam.authenticated.value).toBe(false);
    });

    it('shows the waiting gate when the portal is not yet open', async () => {
        const api = stubApi({
            status: vi.fn().mockResolvedValue(statusBody({ status: 'ready', open: false, reason: 'competition_not_open' })),
        });
        const { exam } = build(api);

        await exam.boot();

        expect(exam.screen.value).toBe(SCREEN.GATE);
        expect(exam.fatalReason.value).toBe('competition_not_open');
    });

    it('shows the terminal gate when the competition has closed', async () => {
        const api = stubApi({
            status: vi.fn().mockResolvedValue(statusBody({ status: 'closed', open: false, reason: 'competition_closed' })),
        });
        const { exam } = build(api);

        await exam.boot();

        expect(exam.screen.value).toBe(SCREEN.GATE);
        expect(exam.fatalReason.value).toBe('competition_closed');
    });

    it('gates an authenticated user who is not on the roster', async () => {
        const api = stubApi({ status: vi.fn().mockResolvedValue(statusBody({ participation: null })) });
        const { exam } = build(api);

        await exam.boot();

        expect(exam.screen.value).toBe(SCREEN.GATE);
        expect(exam.fatalReason.value).toBe('not_a_contestant');
    });

    it('gates a contestant whose account was never provisioned', async () => {
        const api = stubApi({
            status: vi.fn().mockResolvedValue(
                statusBody({ participation: { exam_status: 'not_started', account_status: 'pending' } }),
            ),
        });
        const { exam } = build(api);

        await exam.boot();

        expect(exam.fatalReason.value).toBe('account_not_provisioned');
    });

    it('lands an eligible contestant on the ready screen', async () => {
        const { exam } = build(stubApi());

        await exam.boot();

        expect(exam.screen.value).toBe(SCREEN.READY);
        expect(exam.competition.secondsPerQuestion).toBe(40);
    });

    it('sends a contestant who already finished straight to the result screen', async () => {
        const api = stubApi({
            status: vi.fn().mockResolvedValue(authedStatus('completed')),
            result: vi.fn().mockResolvedValue({ exam_status: 'completed', completed_at: 'x', show_result: false }),
        });
        const { exam } = build(api);

        await exam.boot();

        expect(exam.screen.value).toBe(SCREEN.COMPLETED);
        expect(api.result).toHaveBeenCalled();
        expect(api.start).not.toHaveBeenCalled();
    });
});

describe('useCompetitionExam — login', () => {
    it('re-reads status after a successful login', async () => {
        const api = stubApi({ status: vi.fn().mockResolvedValueOnce(statusBody()).mockResolvedValue(authedStatus()) });
        const { exam } = build(api);

        await exam.boot();
        expect(exam.screen.value).toBe(SCREEN.LOGIN);

        await exam.login({ email: 'a@b.test', password: 'x' });

        expect(exam.screen.value).toBe(SCREEN.READY);
    });

    it('keeps the contestant on the login screen and records the reason on bad credentials', async () => {
        const api = stubApi({
            status: vi.fn().mockResolvedValue(statusBody()),
            login: vi.fn().mockRejectedValue(new ApiError('invalid_credentials', { status: 422, fields: { email: ['x'] } })),
        });
        const { exam } = build(api);

        await exam.boot();
        const ok = await exam.login({ email: 'a@b.test', password: 'wrong' });

        expect(ok).toBe(false);
        expect(exam.screen.value).toBe(SCREEN.LOGIN);
        expect(exam.error.value).toBe('invalid_credentials');
    });

    it('surfaces a rate-limit refusal as its own reason', async () => {
        const api = stubApi({
            status: vi.fn().mockResolvedValue(statusBody()),
            login: vi.fn().mockRejectedValue(new ApiError('too_many_attempts', { status: 429 })),
        });
        const { exam } = build(api);

        await exam.boot();
        await exam.login({ email: 'a@b.test', password: 'x' });

        expect(exam.error.value).toBe('too_many_attempts');
    });
});

describe('useCompetitionExam — the exam', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('starts and renders the first question', async () => {
        const api = stubApi();
        const { exam } = build(api);

        await exam.boot();
        await exam.start();

        expect(exam.screen.value).toBe(SCREEN.EXAM);
        expect(exam.question.value.sequence).toBe(1);
        expect(Object.keys(exam.question.value.options)).toEqual(['A', 'B', 'C', 'D']);
    });

    it('never receives or exposes correctness during the exam', async () => {
        const api = stubApi();
        const { exam } = build(api);

        await exam.boot();
        await exam.start();
        exam.select('A');
        await exam.submitAnswer();

        const serialised = JSON.stringify(exam.question.value);
        expect(serialised).not.toContain('correct');
        expect(serialised).not.toContain('is_correct');
    });

    it('advances to the next question from the answer response', async () => {
        const api = stubApi();
        const { exam } = build(api);

        await exam.boot();
        await exam.start();
        exam.select('B');
        await exam.submitAnswer();

        expect(api.answer).toHaveBeenCalledWith(101, 'B');
        expect(exam.question.value.sequence).toBe(2);
        expect(exam.selected.value).toBeNull();
    });

    it('refuses to submit with nothing selected', async () => {
        const api = stubApi();
        const { exam } = build(api);

        await exam.boot();
        await exam.start();
        await exam.submitAnswer();

        expect(api.answer).not.toHaveBeenCalled();
    });

    it('completes when the answer response carries no next question', async () => {
        const api = stubApi({
            answer: vi.fn().mockResolvedValue({ accepted: true, sequence: 3, exam_status: 'completed', next_question: null }),
            result: vi.fn().mockResolvedValue({ exam_status: 'completed', completed_at: 'x', show_result: true, correct_answers: 2, answered_questions: 3, total_questions: 3 }),
        });
        const { exam } = build(api);

        await exam.boot();
        await exam.start();
        exam.select('C');
        await exam.submitAnswer();

        expect(exam.screen.value).toBe(SCREEN.COMPLETED);
        expect(exam.result.value.correct_answers).toBe(2);
    });

    it('re-anchors the timer to the server value on every question', async () => {
        const api = stubApi({
            start: vi.fn().mockResolvedValue({ exam_status: 'in_progress', question: question(1, { seconds: 12 }) }),
        });
        const { exam } = build(api);

        await exam.boot();
        await exam.start();

        expect(exam.timer.seconds.value).toBe(12);
    });

    it('resuming renders the server state without restarting the clock', async () => {
        // The backend cannot tell a start from a resume, so the client asks the
        // same question and simply renders the 9 seconds it is told are left.
        const api = stubApi({
            status: vi.fn().mockResolvedValue(authedStatus('in_progress')),
            start: vi.fn().mockResolvedValue({ exam_status: 'in_progress', question: question(2, { seconds: 9 }) }),
        });
        const { exam } = build(api);

        await exam.boot();
        expect(exam.participation.value.exam_status).toBe('in_progress');

        await exam.start();

        expect(exam.question.value.sequence).toBe(2);
        expect(exam.timer.seconds.value).toBe(9);
    });

    it('freezes interaction and asks the server when the visible timer hits zero', async () => {
        const api = stubApi({
            start: vi.fn().mockResolvedValue({ exam_status: 'in_progress', question: question(1, { seconds: 2 }) }),
            current: vi.fn().mockResolvedValue({ exam_status: 'in_progress', question: question(2) }),
        });
        const { exam } = build(api);

        await exam.boot();
        await exam.start();
        expect(exam.canAnswer.value).toBe(true);

        vi.advanceTimersByTime(2500);
        expect(exam.canAnswer.value).toBe(false); // dead immediately, before any round trip

        await vi.waitFor(() => expect(api.current).toHaveBeenCalled());
        await nextTick();

        // The NEXT question came from the server; the client did not pick it.
        expect(exam.question.value.sequence).toBe(2);
    });

    it('re-reads state instead of guessing when the server rejects an expired answer', async () => {
        const api = stubApi({
            answer: vi.fn().mockRejectedValue(new ApiError('question_expired', { status: 422 })),
            current: vi.fn().mockResolvedValue({ exam_status: 'in_progress', question: question(2) }),
        });
        const { exam } = build(api);

        await exam.boot();
        await exam.start();
        exam.select('A');
        await exam.submitAnswer();

        expect(api.current).toHaveBeenCalledTimes(1);
        expect(exam.question.value.sequence).toBe(2);
    });

    it('gates mid-exam if the competition is closed under the contestant', async () => {
        const api = stubApi({
            answer: vi.fn().mockRejectedValue(new ApiError('competition_closed', { status: 403 })),
        });
        const { exam } = build(api);

        await exam.boot();
        await exam.start();
        exam.select('A');
        await exam.submitAnswer();

        expect(exam.screen.value).toBe(SCREEN.GATE);
        expect(exam.fatalReason.value).toBe('competition_closed');
    });

    it('returns to login when the session has expired', async () => {
        const api = stubApi({ current: vi.fn().mockRejectedValue(new ApiError('unauthenticated', { status: 401 })) });
        const { exam } = build(api);

        await exam.boot();
        await exam.start();
        await exam.refreshCurrent();

        expect(exam.screen.value).toBe(SCREEN.LOGIN);
        expect(exam.authenticated.value).toBe(false);
    });
});

describe('useCompetitionExam — network failure', () => {
    it('never advances on an uncertain submission; it re-reads current state', async () => {
        // The submission may or may not have been recorded. Guessing either way
        // risks a duplicate answer or a silently skipped question.
        const api = stubApi({
            answer: vi.fn().mockRejectedValue(new ApiError('network_error', { status: 0 })),
            current: vi.fn().mockResolvedValue({ exam_status: 'in_progress', question: question(1) }),
        });
        const { exam } = build(api);

        await exam.boot();
        await exam.start();
        exam.select('D');
        await exam.submitAnswer();

        expect(api.answer).toHaveBeenCalledTimes(1);
        expect(api.current).toHaveBeenCalledTimes(1);
        // Still on question 1: the server says it is still awaiting an answer.
        expect(exam.question.value.sequence).toBe(1);
    });

    it('treats a 5xx submission as indeterminate too', async () => {
        const api = stubApi({
            answer: vi.fn().mockRejectedValue(new ApiError('server_error', { status: 500 })),
            current: vi.fn().mockResolvedValue({ exam_status: 'in_progress', question: question(2) }),
        });
        const { exam } = build(api);

        await exam.boot();
        await exam.start();
        exam.select('A');
        await exam.submitAnswer();

        expect(api.current).toHaveBeenCalledTimes(1);
        expect(exam.question.value.sequence).toBe(2);
    });

    it('stays frozen and offers a retry when the recovery read also fails', async () => {
        const api = stubApi({
            answer: vi.fn().mockRejectedValue(new ApiError('network_error', { status: 0 })),
            current: vi.fn().mockRejectedValue(new ApiError('network_error', { status: 0 })),
        });
        const { exam } = build(api);

        await exam.boot();
        await exam.start();
        exam.select('A');
        await exam.submitAnswer();

        expect(exam.awaitingServer.value).toBe(true);
        expect(exam.canAnswer.value).toBe(false);
        expect(exam.error.value).toBe('network_error');
        expect(exam.question.value.sequence).toBe(1);
    });

    it('recovers on a manual retry once the connection returns', async () => {
        const api = stubApi({
            answer: vi.fn().mockRejectedValue(new ApiError('network_error', { status: 0 })),
            current: vi
                .fn()
                .mockRejectedValueOnce(new ApiError('network_error', { status: 0 }))
                .mockResolvedValue({ exam_status: 'in_progress', question: question(2) }),
        });
        const { exam } = build(api);

        await exam.boot();
        await exam.start();
        exam.select('A');
        await exam.submitAnswer();
        expect(exam.awaitingServer.value).toBe(true);

        await exam.refreshCurrent();

        expect(exam.question.value.sequence).toBe(2);
        expect(exam.awaitingServer.value).toBe(false);
        expect(exam.error.value).toBeNull();
    });

    it('shows a failure gate when the very first status read cannot complete', async () => {
        const api = stubApi({ status: vi.fn().mockRejectedValue(new ApiError('network_error', { status: 0 })) });
        const { exam } = build(api);

        await exam.boot();

        expect(exam.screen.value).toBe(SCREEN.GATE);
        expect(exam.fatalReason.value).toBe('network_error');
    });
});
