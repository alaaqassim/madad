import { ApiError } from './http';

/*
| In-memory mock of the contestant API.
|
| It implements the SAME contract as the real routes — same keys, same
| `reason` codes, same nesting, same omissions (no correct_option, ever). It
| exists so the exam screens can be developed and demonstrated without the
| backend running; it is not a second contract, and it is never the default.
|
| Enable with VITE_MADAD_MOCK_API=true.
*/

const SECONDS_PER_QUESTION = 40;
const TOTAL_QUESTIONS = 8;

function iso(date) {
    return date.toISOString();
}

function buildPaper() {
    return Array.from({ length: TOTAL_QUESTIONS }, (_, index) => ({
        question_id: 100 + index,
        sequence: index + 1,
        question_text: `نصّ السؤال التجريبي رقم ${index + 1} حول كتاب «الإمام علي صوت العدالة الإنسانية».`,
        options: {
            A: `الخيار الأول للسؤال ${index + 1}`,
            B: `الخيار الثاني للسؤال ${index + 1}`,
            C: `الخيار الثالث للسؤال ${index + 1}`,
            D: `الخيار الرابع للسؤال ${index + 1}`,
        },
        opened_at: null,
        expires_at: null,
        answered: false,
    }));
}

export function createMockApi(overrides = {}) {
    const state = {
        competitionStatus: 'open',
        showResult: true,
        authenticated: false,
        examStatus: 'not_started',
        startedAt: null,
        paper: buildPaper(),
        correct: 0,
        ...overrides,
    };

    const requireAuth = () => {
        if (!state.authenticated) {
            throw new ApiError('unauthenticated', { status: 401 });
        }
    };

    const requireOpen = () => {
        if (state.competitionStatus === 'closed') {
            throw new ApiError('competition_closed', { status: 403 });
        }

        if (state.competitionStatus !== 'open') {
            throw new ApiError('competition_not_open', { status: 403 });
        }
    };

    /** Mirrors the server sweep: expired questions go terminal before serving. */
    const liveRow = () => {
        const now = Date.now();

        for (const row of state.paper) {
            if (row.answered || row.timedOut) {
                continue;
            }

            if (row.opened_at === null) {
                const openedAt = new Date(now);
                row.opened_at = iso(openedAt);
                row.expires_at = iso(new Date(now + SECONDS_PER_QUESTION * 1000));

                return row;
            }

            if (now <= Date.parse(row.expires_at)) {
                return row;
            }

            row.timedOut = true;
        }

        return null;
    };

    const envelope = () => {
        const row = state.examStatus === 'completed' ? null : liveRow();

        if (row === null) {
            state.examStatus = 'completed';
            state.completedAt = state.completedAt ?? iso(new Date());
        }

        return {
            exam_status: state.examStatus,
            started_at: state.startedAt,
            question:
                row === null
                    ? null
                    : {
                          question_id: row.question_id,
                          question_text: row.question_text,
                          options: row.options,
                          sequence: row.sequence,
                          total_questions: TOTAL_QUESTIONS,
                          opened_at: row.opened_at,
                          expires_at: row.expires_at,
                          server_time: iso(new Date()),
                          seconds_remaining: Math.max(0, (Date.parse(row.expires_at) - Date.now()) / 1000),
                      },
        };
    };

    return {
        __state: state,

        async login({ email, password }) {
            if (!email || !password) {
                throw new ApiError('validation_error', {
                    status: 422,
                    fields: { email: ['مطلوب'] },
                });
            }

            if (password !== 'secret-password') {
                throw new ApiError('invalid_credentials', {
                    status: 422,
                    fields: { email: ['These credentials do not match our records.'] },
                });
            }

            state.authenticated = true;

            return { user: { id: 1, name: 'متسابق تجريبي', email } };
        },

        async logout() {
            state.authenticated = false;

            return { message: 'Logged out.' };
        },

        async status() {
            const open = state.competitionStatus === 'open';

            return {
                competition: 'المسابقة الطلابيّة',
                status: state.competitionStatus,
                open,
                reason: open ? null : state.competitionStatus === 'closed' ? 'competition_closed' : 'competition_not_open',
                total_questions: TOTAL_QUESTIONS,
                seconds_per_question: SECONDS_PER_QUESTION,
                show_result: state.showResult,
                server_time: iso(new Date()),
                ...(state.authenticated
                    ? { participation: { exam_status: state.examStatus, account_status: 'created' } }
                    : {}),
            };
        },

        async start() {
            requireAuth();
            requireOpen();

            if (state.examStatus === 'not_started') {
                state.examStatus = 'in_progress';
                state.startedAt = iso(new Date());
            }

            return envelope();
        },

        async current() {
            requireAuth();
            requireOpen();

            return envelope();
        },

        async answer(questionId, selectedOption) {
            requireAuth();
            requireOpen();

            if (state.examStatus === 'completed') {
                throw new ApiError('exam_completed', { status: 409 });
            }

            const row = liveRow();

            if (row === null || row.question_id !== questionId) {
                throw new ApiError('question_not_available', { status: 422 });
            }

            if (Date.now() > Date.parse(row.expires_at)) {
                row.timedOut = true;

                throw new ApiError('question_expired', { status: 422 });
            }

            row.answered = true;
            row.selected = selectedOption;

            // Deliberately never returned to the client — parity with the server.
            if (selectedOption === 'A') {
                state.correct += 1;
            }

            const next = envelope();

            return {
                accepted: true,
                sequence: row.sequence,
                exam_status: next.exam_status,
                next_question: next.question,
            };
        },

        async result() {
            requireAuth();

            const payload = {
                exam_status: state.examStatus,
                completed_at: state.completedAt ?? null,
                show_result: state.showResult,
            };

            if (!state.showResult || state.examStatus !== 'completed') {
                return payload;
            }

            return {
                ...payload,
                correct_answers: state.correct,
                answered_questions: state.paper.filter((row) => row.answered).length,
                total_questions: TOTAL_QUESTIONS,
            };
        },
    };
}
