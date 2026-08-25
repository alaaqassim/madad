import { describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { useCompetitionExam } from '../composables/useCompetitionExam';
import { ApiError } from '../api/http';

/*
| Follow-ups to the visual pass: an unconfirmed submission that recovers onto
| the SAME question must say so, and one that recovers onto the NEXT question
| must not.
*/

const question = (sequence) => ({
    question_id: 100 + sequence,
    question_text: `سؤال ${sequence}`,
    options: { A: 'أ', B: 'ب', C: 'ج', D: 'د' },
    sequence,
    total_questions: 3,
    opened_at: null,
    expires_at: null,
    server_time: null,
    seconds_remaining: 40,
});

const status = {
    competition: 'م', status: 'open', open: true, reason: null,
    total_questions: 3, seconds_per_question: 40, show_result: false,
    server_time: 'x', participation: { exam_status: 'not_started', account_status: 'created' },
};

function build(currentAfterFailure) {
    const api = {
        login: vi.fn(),
        logout: vi.fn(),
        status: vi.fn().mockResolvedValue(status),
        start: vi.fn().mockResolvedValue({ exam_status: 'in_progress', question: question(1) }),
        current: vi.fn().mockResolvedValue({ exam_status: 'in_progress', question: currentAfterFailure }),
        answer: vi.fn().mockRejectedValue(new ApiError('server_error', { status: 500 })),
        result: vi.fn(),
    };

    return { api, exam: effectScope().run(() => useCompetitionExam(api)) };
}

describe('unconfirmed submissions', () => {
    it('tells the contestant to choose again when the same question is still open', async () => {
        const { exam } = build(question(1));

        await exam.boot();
        await exam.start();
        exam.select('A');
        await exam.submitAnswer();

        expect(exam.question.value.sequence).toBe(1);
        expect(exam.selected.value).toBeNull();
        expect(exam.error.value).toBe('answer_unconfirmed');
    });

    it('stays quiet when the answer did land and the paper moved on', async () => {
        const { exam } = build(question(2));

        await exam.boot();
        await exam.start();
        exam.select('A');
        await exam.submitAnswer();

        expect(exam.question.value.sequence).toBe(2);
        expect(exam.error.value).toBeNull();
    });

    it('does not raise it for a plain expiry, which is not ambiguous', async () => {
        const api = {
            login: vi.fn(),
            logout: vi.fn(),
            status: vi.fn().mockResolvedValue(status),
            start: vi.fn().mockResolvedValue({ exam_status: 'in_progress', question: question(1) }),
            current: vi.fn().mockResolvedValue({ exam_status: 'in_progress', question: question(1) }),
            answer: vi.fn().mockRejectedValue(new ApiError('question_expired', { status: 422 })),
            result: vi.fn(),
        };
        const exam = effectScope().run(() => useCompetitionExam(api));

        await exam.boot();
        await exam.start();
        exam.select('A');
        await exam.submitAnswer();

        expect(exam.error.value).toBeNull();
    });
});
