import { ApiError } from './http';

/*
| In-memory mock of the contestant API.
|
| It implements the SAME contract as the real routes — same keys, same
| `reason` codes, same nesting, same omissions (no correct_option, ever). It
| exists so the exam screens can be developed, previewed and demonstrated
| without the backend running; it is not a second contract, and it is never the
| default.
|
| Reached two ways, both opt-in:
|   * VITE_MADAD_MOCK_API=true — the whole app runs on the mock.
|   * ?preview=<scenario> in a dev build — see SCENARIOS below.
*/

const SECONDS_PER_QUESTION = 40;

function iso(date) {
    return date.toISOString();
}

/*
| Realistic paper content: the Phase 1 competition is on جورج جرداق's
| «الإمام علي صوت العدالة الإنسانية», so the preview asks about that book
| rather than showing "Question 1 / Option A" placeholders.
*/
const PAPER = [
    {
        question_text: 'مَن مؤلِّف كتاب «الإمام علي صوت العدالة الإنسانية»؟',
        options: {
            A: 'جورج جرداق',
            B: 'طه حسين',
            C: 'عباس محمود العقّاد',
            D: 'ميخائيل نعيمة',
        },
    },
    {
        question_text: 'في أيّ عام صدرت الطبعة الأولى من الكتاب؟',
        options: { A: '١٩٣٦م', B: '١٩٥٦م', C: '١٩٦٨م', D: '١٩٧٤م' },
    },
    {
        question_text: 'أيّ فصلٍ من الكتاب يعالج مسألة المساواة أمام القانون بوصفها أساسًا للحكم؟',
        options: {
            A: 'الفصل الأول: النشأة والبيئة',
            B: 'الفصل الثالث: عليّ وحقوق الإنسان',
            C: 'الفصل الخامس: عليّ وسقراط',
            D: 'الفصل السابع: مقتل الإمام',
        },
    },
    {
        question_text: 'ما الوثيقة التي يستشهد بها المؤلِّف كثيرًا في حديثه عن العدل في الولاية؟',
        options: {
            A: 'رسالة الحقوق',
            B: 'عهد الإمام عليّ إلى مالك الأشتر',
            C: 'الصحيفة السجّاديّة',
            D: 'كتاب الخراج لأبي يوسف',
        },
    },
    {
        question_text: 'أيّ شخصيّة غربيّة يعقد جرداق بينها وبين الإمام عليّ مقارنةً في باب الفلسفة والأخلاق؟',
        options: { A: 'أفلاطون', B: 'سقراط', C: 'أرسطو', D: 'مونتسكيو' },
    },
    {
        question_text: 'يرى المؤلِّف أنّ جوهر خطاب الإمام عليّ في الحكم يقوم على…',
        options: {
            A: 'توسيع الفتوحات وتثبيت السلطان',
            B: 'العدل ورفع الظلم عن الناس',
            C: 'تفضيل القرابة في الولاية',
            D: 'مهادنة الوجهاء وأصحاب المال',
        },
    },
    {
        question_text: 'ما المدينة التي اتّخذها الإمام عليّ عاصمةً لخلافته؟',
        options: { A: 'المدينة المنوّرة', B: 'دمشق', C: 'الكوفة', D: 'البصرة' },
    },
    {
        question_text: 'أيّ المعاني الآتية يجعله الكتاب عنوانًا جامعًا لسيرة الإمام عليّ؟',
        options: {
            A: 'صوت العدالة الإنسانيّة',
            B: 'حكمة الشرق القديم',
            C: 'فارس الفتوحات',
            D: 'شاعر العرب الأكبر',
        },
    },
];

/*
| The real Phase 1 paper is 75 questions, and the progress line and the result
| only look right at that size. The eight authored questions are cycled to fill
| it rather than padded with "سؤال رقم N" placeholders.
*/
const TOTAL_QUESTIONS = 75;

function buildPaper() {
    return Array.from({ length: TOTAL_QUESTIONS }, (_, index) => ({
        ...PAPER[index % PAPER.length],
        question_id: 1000 + index,
        sequence: index + 1,
        opened_at: null,
        expires_at: null,
        answered: false,
        timedOut: false,
    }));
}

/**
 * @param {object} options
 * @param {string} options.competitionStatus  draft | ready | open | closed
 * @param {boolean} options.showResult
 * @param {boolean} options.authenticated
 * @param {string} options.examStatus         not_started | in_progress | completed
 * @param {?number} options.firstWindow       seconds left on the first served
 *                                            question, for previewing the
 *                                            warning band and the timeout
 * @param {?string} options.failLogin         a reason code to reject login with
 * @param {?string} options.failAnswer        a reason code to reject answers with
 * @param {?string} options.failCurrent       a reason code, or 'pending' to
 *                                            leave /exam/current unresolved
 */
export function createMockApi(options = {}) {
    const state = {
        competitionStatus: 'open',
        showResult: false,
        authenticated: false,
        examStatus: 'not_started',
        startedAt: null,
        completedAt: null,
        paper: buildPaper(),
        correct: 0,
        firstWindow: null,
        failLogin: null,
        failAnswer: null,
        failCurrent: null,
        ...options,
    };

    const total = state.paper.length;

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
                // The first question served may be given a short window so the
                // warning band and the timeout can be previewed without waiting.
                const window =
                    state.firstWindow !== null && !state.paper.some((r) => r.opened_at !== null)
                        ? state.firstWindow
                        : SECONDS_PER_QUESTION;

                row.opened_at = iso(new Date(now));
                row.expires_at = iso(new Date(now + window * 1000));

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
                          total_questions: total,
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
            if (state.failLogin) {
                throw new ApiError(state.failLogin, {
                    status: state.failLogin === 'too_many_attempts' ? 429 : 422,
                    fields: { email: ['These credentials do not match our records.'] },
                });
            }

            if (!email || !password) {
                throw new ApiError('validation_error', { status: 422, fields: { email: ['مطلوب'] } });
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
                reason: open
                    ? null
                    : state.competitionStatus === 'closed'
                      ? 'competition_closed'
                      : 'competition_not_open',
                total_questions: total,
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

        current() {
            // 'pending' never settles — the honest shape of "we asked the server
            // and are still waiting", which is what a timeout looks like.
            if (state.failCurrent === 'pending') {
                return new Promise(() => {});
            }

            return (async () => {
                requireAuth();
                requireOpen();

                if (state.failCurrent) {
                    throw new ApiError(state.failCurrent, {
                        status: state.failCurrent === 'network_error' ? 0 : 409,
                    });
                }

                return envelope();
            })();
        },

        async answer(questionId, selectedOption) {
            requireAuth();
            requireOpen();

            if (state.failAnswer) {
                throw new ApiError(state.failAnswer, {
                    status: state.failAnswer === 'network_error' ? 0 : 422,
                });
            }

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
                answered_questions: state.answered ?? state.paper.filter((row) => row.answered).length,
                total_questions: total,
            };
        },
    };
}

/*
| Preview scenarios.
|
| Each one is just a starting configuration for the mock above; the screens are
| reached through the real state machine, not by forcing a component to render.
| `drive` is executed by resources/js/dev/preview.js after boot.
*/
export const SCENARIOS = {
    login: {
        label: 'الدخول',
        note: 'Login form, competition open, nobody signed in.',
        mock: { authenticated: false },
    },
    'login-error': {
        label: 'خطأ في الدخول',
        note: 'Login refused — invalid credentials banner and both fields marked.',
        mock: { authenticated: false, failLogin: 'invalid_credentials' },
        drive: 'login',
    },
    'login-throttled': {
        label: 'حظر مؤقّت',
        note: 'Login rate-limited (429).',
        mock: { authenticated: false, failLogin: 'too_many_attempts' },
        drive: 'login',
    },
    waiting: {
        label: 'لم تبدأ',
        note: 'Competition not open yet — waiting gate with a refresh.',
        mock: { competitionStatus: 'ready' },
    },
    closed: {
        label: 'انتهت',
        note: 'Competition closed — terminal gate, deliberately no retry.',
        mock: { competitionStatus: 'closed' },
    },
    intro: {
        label: 'قبل البدء',
        note: 'Signed in, competition open, exam not started.',
        mock: { authenticated: true },
    },
    resume: {
        label: 'استئناف',
        note: 'Signed in with an exam already in progress — the CTA reads "أكمل".',
        mock: { authenticated: true, examStatus: 'in_progress' },
    },
    question: {
        label: 'السؤال',
        note: 'A live question with a full 40s window.',
        mock: { authenticated: true },
        drive: 'start',
    },
    selected: {
        label: 'إجابة مختارة',
        note: 'A live question with option B chosen.',
        mock: { authenticated: true },
        drive: 'select',
    },
    warning: {
        label: 'قرب الانتهاء',
        note: 'The timer inside the last 10 seconds — warning band.',
        mock: { authenticated: true, firstWindow: 9 },
        drive: 'start',
    },
    timeout: {
        label: 'انتهى الوقت',
        note: 'Countdown reached zero: interaction frozen, asking the server.',
        mock: { authenticated: true, firstWindow: 2, failCurrent: 'pending' },
        drive: 'start',
    },
    'network-retry': {
        label: 'فشل الشبكة',
        note: 'Submission and recovery both failed — frozen with a retry.',
        mock: { authenticated: true, failAnswer: 'network_error', failCurrent: 'network_error' },
        drive: 'submit',
    },
    unconfirmed: {
        label: 'إجابة غير مؤكّدة',
        note: 'Submission unconfirmed, server says the question is still open.',
        mock: { authenticated: true, failAnswer: 'network_error' },
        drive: 'submit',
    },
    'completed-hidden': {
        label: 'انتهت — بلا نتيجة',
        note: 'Exam complete, show_result=false — no score anywhere.',
        mock: { authenticated: true, examStatus: 'completed', showResult: false },
    },
    'completed-visible': {
        label: 'انتهت — مع النتيجة',
        note: 'Exam complete, show_result=true — score printed from the response.',
        mock: {
            authenticated: true,
            examStatus: 'completed',
            showResult: true,
            correct: 62,
            answered: 71,
        },
    },
};
