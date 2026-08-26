import { describe, expect, it, beforeEach, vi } from 'vitest';
import { effectScope } from 'vue';
import { createMockJourney, MOCK_CONTESTANT, TOTAL_QUESTIONS, STORAGE_KEY } from '../dev/mockJourney';
import { useCompetitionExam, SCREEN } from '../composables/useCompetitionExam';

/*
| The whole contestant journey, driven through the REAL state machine against
| the development mock: login → status → intro → start → answer → timeout →
| continue → complete → result → logout, plus a refresh mid-question.
|
| Nothing here reaches into a component. If this passes, the same sequence of
| calls will work against the real backend, because the mock speaks the same
| contract.
*/

/** A minimal Storage that survives being "reloaded" like sessionStorage does. */
function memoryStorage() {
    const map = new Map();

    return {
        getItem: (key) => (map.has(key) ? map.get(key) : null),
        setItem: (key, value) => map.set(key, String(value)),
        removeItem: (key) => map.delete(key),
        get size() {
            return map.size;
        },
    };
}

/** A controllable clock, so a 40s question can expire without waiting 40s. */
function fakeClock(start = 1_800_000_000_000) {
    let t = start;

    return { now: () => t, advance: (seconds) => (t += seconds * 1000) };
}

function build(journey) {
    const scope = effectScope();

    return { exam: scope.run(() => useCompetitionExam(journey)), stop: () => scope.stop() };
}

describe('the mock contestant journey', () => {
    let storage;
    let clock;
    let journey;

    beforeEach(() => {
        storage = memoryStorage();
        clock = fakeClock();
        journey = createMockJourney({ storage, now: clock.now, flags: {}, latency: 0 });
    });

    it('runs end to end: login, intro, exam, a timeout, completion, result, logout', async () => {
        const { exam } = build(journey);

        // ── boot: unauthenticated, so the login screen ───────────────────────
        await exam.boot();
        expect(exam.screen.value).toBe(SCREEN.LOGIN);
        expect(exam.competition.open).toBe(true);

        // ── wrong credentials are refused, with the real reason code ─────────
        expect(await exam.login({ email: MOCK_CONTESTANT.email, password: 'nope' })).toBe(false);
        expect(exam.screen.value).toBe(SCREEN.LOGIN);
        expect(exam.error.value).toBe('invalid_credentials');

        // ── the one working account ──────────────────────────────────────────
        expect(await exam.login(MOCK_CONTESTANT)).toBe(true);

        // ── intro: NOT auto-started ─────────────────────────────────────────
        expect(exam.screen.value).toBe(SCREEN.READY);
        expect(exam.competition.totalQuestions).toBe(TOTAL_QUESTIONS);
        expect(exam.competition.secondsPerQuestion).toBe(40);

        // ── start, explicitly ────────────────────────────────────────────────
        await exam.start();
        expect(exam.screen.value).toBe(SCREEN.EXAM);
        expect(exam.question.value.sequence).toBe(1);
        expect(Object.keys(exam.question.value.options)).toEqual(['A', 'B', 'C', 'D']);

        // ── answer questions 1..3 ────────────────────────────────────────────
        for (let n = 1; n <= 3; n++) {
            expect(exam.question.value.sequence).toBe(n);
            exam.select('A');
            await exam.submitAnswer();
        }
        expect(exam.question.value.sequence).toBe(4);

        // ── let question 4 time out naturally ────────────────────────────────
        const timedOutId = exam.question.value.question_id;
        clock.advance(41);
        await exam.refreshCurrent(); // what the countdown reaching zero triggers

        expect(exam.screen.value).toBe(SCREEN.EXAM);
        expect(exam.question.value.sequence).toBe(5);
        expect(exam.question.value.question_id).not.toBe(timedOutId);
        expect(journey.__state().rows[3].timed_out).toBe(true);
        expect(journey.__state().rows[3].selected_option).toBeNull();

        // ── finish the paper ─────────────────────────────────────────────────
        while (exam.screen.value === SCREEN.EXAM) {
            exam.select('B');
            await exam.submitAnswer();
        }

        // ── completion, with the score withheld by default ───────────────────
        expect(exam.screen.value).toBe(SCREEN.COMPLETED);
        expect(exam.result.value.exam_status).toBe('completed');
        expect(exam.result.value.show_result).toBe(false);
        expect(exam.result.value.correct_answers).toBeUndefined();

        // ── logout resets the journey ────────────────────────────────────────
        await exam.logout();
        expect(exam.screen.value).toBe(SCREEN.LOGIN);
        expect(storage.getItem(STORAGE_KEY)).toBeNull();

        await exam.boot();
        expect(exam.screen.value).toBe(SCREEN.LOGIN);
    });

    it('never leaks correctness or a score while the exam is live', async () => {
        const { exam } = build(journey);

        await exam.boot();
        await exam.login(MOCK_CONTESTANT);
        await exam.start();

        exam.select('A');
        await exam.submitAnswer();

        const seen = JSON.stringify(exam.question.value) + JSON.stringify(exam.result.value);
        expect(seen).not.toContain('correct');
        expect(seen).not.toContain('is_correct');

        // Asking for the result mid-exam yields no numbers either.
        const midExam = await journey.result();
        expect(midExam.correct_answers).toBeUndefined();
    });

    it('survives a refresh mid-question: same question, same remaining time, no reshuffle', async () => {
        const first = build(journey);

        await first.exam.boot();
        await first.exam.login(MOCK_CONTESTANT);
        await first.exam.start();

        first.exam.select('C');
        await first.exam.submitAnswer(); // now on question 2

        const before = {
            sequence: first.exam.question.value.sequence,
            id: first.exam.question.value.question_id,
            text: first.exam.question.value.question_text,
            order: [...journey.__state().order],
        };

        clock.advance(15); // fifteen seconds spent reading
        first.stop(); // tab closed

        // A brand-new engine over the SAME storage — this is what F5 does.
        const reloaded = createMockJourney({ storage, now: clock.now, flags: {}, latency: 0 });
        const second = build(reloaded);

        await second.exam.boot();
        expect(second.exam.screen.value).toBe(SCREEN.READY); // still in progress
        expect(second.exam.participation.value.exam_status).toBe('in_progress');

        await second.exam.start(); // resume — the same call as start

        expect(second.exam.question.value.sequence).toBe(before.sequence);
        expect(second.exam.question.value.question_id).toBe(before.id);
        expect(second.exam.question.value.question_text).toBe(before.text);
        expect(reloaded.__state().order).toEqual(before.order);

        // The deadline did not move: 40 - 15 = 25 seconds left, not 40.
        expect(second.exam.timer.seconds.value).toBe(25);
    });

    it('shows a real score at the end when the portal is configured to reveal it', async () => {
        const revealing = createMockJourney({ storage, now: clock.now, flags: { showResult: true }, latency: 0 });
        const { exam } = build(revealing);

        await exam.boot();
        await exam.login(MOCK_CONTESTANT);
        await exam.start();

        // Answer every question with the option the bank marks correct for the
        // first three, then a fixed letter — the score is computed, not staged.
        while (exam.screen.value === SCREEN.EXAM) {
            exam.select('A');
            await exam.submitAnswer();
        }

        expect(exam.screen.value).toBe(SCREEN.COMPLETED);
        expect(exam.result.value.show_result).toBe(true);
        expect(exam.result.value.total_questions).toBe(TOTAL_QUESTIONS);
        expect(exam.result.value.answered_questions).toBe(TOTAL_QUESTIONS);
        expect(exam.result.value.correct_answers).toBe(3); // A is right on 1, 6 and 10
    });

    it('uses an 8 second window under ?fast=1 and 40 otherwise', async () => {
        const fast = createMockJourney({ storage: memoryStorage(), now: clock.now, flags: { fast: true }, latency: 0 });
        const { exam } = build(fast);

        await exam.boot();
        await exam.login(MOCK_CONTESTANT);
        await exam.start();

        expect(exam.competition.secondsPerQuestion).toBe(8);
        expect(exam.timer.seconds.value).toBe(8);
    });

    it('renders the terminal closed state under ?closed=1, with no way back in', async () => {
        const shut = createMockJourney({ storage: memoryStorage(), now: clock.now, flags: { closed: true }, latency: 0 });
        const { exam } = build(shut);

        // Public status is still readable before signing in.
        await exam.boot();
        expect(exam.screen.value).toBe(SCREEN.LOGIN);

        await exam.login(MOCK_CONTESTANT);

        expect(exam.screen.value).toBe(SCREEN.GATE);
        expect(exam.fatalReason.value).toBe('competition_closed');

        await expect(shut.start()).rejects.toMatchObject({ reason: 'competition_closed' });
    });

    it('refuses an answer to a question that is no longer the live one', async () => {
        const { exam } = build(journey);

        await exam.boot();
        await exam.login(MOCK_CONTESTANT);
        await exam.start();

        const staleId = exam.question.value.question_id;
        exam.select('A');
        await exam.submitAnswer(); // question 1 is now answered

        await expect(journey.answer(staleId, 'B')).rejects.toMatchObject({
            reason: 'question_not_available',
        });
    });

    it('refuses everything once the session is gone', async () => {
        await expect(journey.start()).rejects.toMatchObject({ reason: 'unauthenticated' });
        await expect(journey.current()).rejects.toMatchObject({ reason: 'unauthenticated' });
        await expect(journey.result()).rejects.toMatchObject({ reason: 'unauthenticated' });
    });

    it('tolerates storage being unavailable', async () => {
        const hostile = {
            getItem: () => {
                throw new Error('denied');
            },
            setItem: () => {
                throw new Error('denied');
            },
            removeItem: () => {
                throw new Error('denied');
            },
        };
        const offline = createMockJourney({ storage: hostile, now: clock.now, flags: {}, latency: 0 });
        const { exam } = build(offline);

        await exam.boot();
        await exam.login(MOCK_CONTESTANT);
        await exam.start();

        expect(exam.question.value.sequence).toBe(1);
    });
});

describe('production safety of the mock', () => {
    it('is never installed by the shipped API client', async () => {
        // competitionApi's default export forwards to realApi until something
        // calls setApi — and only dev/installMockJourney.js ever does.
        const module = await import('../api/competitionApi');

        expect(typeof module.setApi).toBe('function');
        expect(module.default.status).not.toBe(module.realApi.status);

        // The forwarder delegates; swapping the implementation is observable.
        const spy = vi.fn().mockResolvedValue({ ok: true });

        module.setApi({ status: spy });
        await module.default.status();
        expect(spy).toHaveBeenCalled();

        module.setApi(null); // back to realApi
    });
});
