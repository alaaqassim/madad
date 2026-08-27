import { describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { SCREEN, useCompetitionExam } from '../composables/useCompetitionExam';
import { ApiError } from '../api/http';

/*
| The client half of immediate advance.
|
| The server rule is that answering opens the next question at once, with a
| window of its own. What the client owes that rule is: render the question the
| answer came back with, re-anchor the countdown to ITS remaining time rather
| than carrying the previous count over, and never show an in-between screen —
| there is no longer such a state, and inventing one would freeze a contestant
| out of seconds that are running.
*/

const question = (sequence, secondsRemaining = 40) => ({
    question_id: 100 + sequence,
    question_text: `سؤال ${sequence}`,
    options: { A: 'أ', B: 'ب', C: 'ج', D: 'د' },
    sequence,
    total_questions: 5,
    opened_at: null,
    expires_at: null,
    server_time: null,
    seconds_remaining: secondsRemaining,
});

const status = {
    competition: 'مسابقة',
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
    server_time: 'x',
    participation: { exam_status: 'not_started', account_status: 'created' },
};

function build(overrides = {}) {
    const api = {
        login: vi.fn(),
        logout: vi.fn().mockResolvedValue({}),
        status: vi.fn().mockResolvedValue(status),
        start: vi.fn().mockResolvedValue({ exam_status: 'in_progress', question: question(1) }),
        current: vi.fn().mockResolvedValue({ exam_status: 'in_progress', question: question(1) }),
        answer: vi.fn(),
        result: vi.fn().mockResolvedValue({ exam_status: 'completed', show_result: false }),
        ...overrides,
    };

    return { api, exam: effectScope().run(() => useCompetitionExam(api)) };
}

describe('immediate advance', () => {
    it('has no waiting screen at all', () => {
        expect(SCREEN.WAITING).toBeUndefined();
        expect(Object.values(SCREEN)).not.toContain('waiting');
    });

    it('renders the next question straight from the answer response', async () => {
        const { exam, api } = build({
            answer: vi.fn().mockResolvedValue({
                accepted: true,
                sequence: 1,
                exam_status: 'in_progress',
                next_question: question(2),
            }),
        });

        await exam.boot();
        await exam.start();
        expect(exam.question.value.sequence).toBe(1);

        exam.select('A');
        await exam.submitAnswer();

        expect(exam.screen.value).toBe(SCREEN.EXAM);
        expect(exam.question.value.sequence).toBe(2);
        // No follow-up read: the answer already carried the next state.
        expect(api.current).not.toHaveBeenCalled();
    });

    it('clears the previous selection so the new question starts unanswered', async () => {
        const { exam } = build({
            answer: vi.fn().mockResolvedValue({
                accepted: true,
                sequence: 1,
                exam_status: 'in_progress',
                next_question: question(2),
            }),
        });

        await exam.boot();
        await exam.start();
        exam.select('C');
        await exam.submitAnswer();

        expect(exam.selected.value).toBeNull();
        expect(exam.canAnswer.value).toBe(true);
    });

    it('re-anchors the countdown to the new question rather than continuing the old count', async () => {
        const { exam } = build({
            // The server may hand back less than a full window — a question
            // trimmed by the availability window, for instance.
            answer: vi.fn().mockResolvedValue({
                accepted: true,
                sequence: 1,
                exam_status: 'in_progress',
                next_question: question(2, 12),
            }),
        });

        await exam.boot();
        await exam.start();
        expect(exam.timer.seconds.value).toBe(40);

        exam.select('A');
        await exam.submitAnswer();

        expect(exam.timer.seconds.value).toBe(12);
        expect(exam.timer.isExpired.value).toBe(false);
    });

    it('completes when the answer returns no next question', async () => {
        const { exam } = build({
            answer: vi.fn().mockResolvedValue({
                accepted: true,
                sequence: 5,
                exam_status: 'completed',
                next_question: null,
            }),
        });

        await exam.boot();
        await exam.start();
        exam.select('A');
        await exam.submitAnswer();

        expect(exam.screen.value).toBe(SCREEN.COMPLETED);
        expect(exam.question.value).toBeNull();
    });

    it('walks a whole paper without ever leaving the question screen', async () => {
        let sequence = 1;

        const { exam } = build({
            answer: vi.fn().mockImplementation(async () => {
                sequence += 1;

                return sequence > 5
                    ? { accepted: true, sequence: 5, exam_status: 'completed', next_question: null }
                    : {
                        accepted: true,
                        sequence: sequence - 1,
                        exam_status: 'in_progress',
                        next_question: question(sequence),
                    };
            }),
        });

        await exam.boot();
        await exam.start();

        const seen = [];

        while (exam.screen.value === SCREEN.EXAM) {
            seen.push(exam.question.value.sequence);
            exam.select('A');
            await exam.submitAnswer();
        }

        // Every position, in order, with no intermediate screen between them.
        expect(seen).toEqual([1, 2, 3, 4, 5]);
        expect(exam.screen.value).toBe(SCREEN.COMPLETED);
    });

    it('re-reads rather than guessing when the answer is refused as expired', async () => {
        const { api, exam } = build({
            answer: vi.fn().mockRejectedValue(new ApiError('question_expired', { status: 422 })),
        });

        // The window closed under the contestant while they were choosing. The
        // client must not decide what replaced it — under immediate advance the
        // server has already opened the next question, several positions on.
        api.current = vi.fn().mockResolvedValue({ exam_status: 'in_progress', question: question(3) });

        await exam.boot();
        await exam.start();
        exam.select('A');
        await exam.submitAnswer();

        expect(api.current).toHaveBeenCalledOnce();
        expect(exam.question.value.sequence).toBe(3);
        expect(exam.screen.value).toBe(SCREEN.EXAM);
    });
});
