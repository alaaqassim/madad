import { ApiError } from '../api/http';

/*
| DEVELOPMENT ONLY — the mock backend for the contestant journey.
|
| This module is reached exclusively through a dynamic import that sits behind
| `if (import.meta.env.DEV)`, so a production build never emits it: no mock
| credentials, no question text, no fast timer, no storage engine.
|
| It plays the SERVER, not the UI. It emulates the seven routes with the exact
| frozen contract — same keys, same nesting, same `reason` codes — and it holds
| the same invariants CompetitionExamService holds:
|
|   * The clock is the server's. opened_at / expires_at are written once, on
|     first service of a question, and never extended.
|   * The current question is DERIVED as the lowest non-terminal sequence.
|     There is no stored pointer to drift.
|   * The answer key never leaves this file. `correct` is read here to grade
|     and is absent from every payload.
|
| Because it holds those invariants, the Vue app runs its real state machine
| against it and will switch to the real backend with no UI change.
*/

const STORAGE_KEY = 'madad.dev.journey';

/** The one account that works in the mock journey. */
export const MOCK_CONTESTANT = {
    email: 'contestant0001@madad.test',
    password: 'Madad@123456',
    name: 'أحمد كاظم الموسوي',
};

const SECONDS_NORMAL = 40;
const SECONDS_FAST = 8;

/*
| A plausible round trip.
|
| An instantaneous mock is a misleading one: the submit button's loading state
| never appears, and the frozen "asking the server" moment after a timeout is
| over within a frame. Every route below waits this long, so what a reviewer
| sees is what a contestant on a decent connection would see.
*/
const LATENCY_MS = 220;

/*
| A ten-question paper on جورج جرداق's «الإمام علي صوت العدالة الإنسانية»,
| the Phase 1 competition text. Ten rather than seventy-five so a reviewer can
| walk the whole journey; the payload shape is unchanged, and `question_count`
| is reported as ten so progress and the final result stay consistent.
*/
const BANK = [
    {
        question_text: 'مَن مؤلِّف كتاب «الإمام علي صوت العدالة الإنسانية»؟',
        options: { A: 'جورج جرداق', B: 'طه حسين', C: 'عباس محمود العقّاد', D: 'ميخائيل نعيمة' },
        correct: 'A',
    },
    {
        question_text: 'إلى أيّ بلدٍ ينتمي مؤلِّف الكتاب؟',
        options: { A: 'مصر', B: 'لبنان', C: 'العراق', D: 'سوريا' },
        correct: 'B',
    },
    {
        question_text: 'أيّ وثيقةٍ يتّخذها المؤلِّف مرجعًا أساسيًّا في حديثه عن العدل في الولاية؟',
        options: {
            A: 'رسالة الحقوق',
            B: 'الصحيفة السجّاديّة',
            C: 'عهد الإمام عليّ إلى مالك الأشتر',
            D: 'كتاب الخراج لأبي يوسف',
        },
        correct: 'C',
    },
    {
        question_text: 'أيّ شخصيّةٍ يونانيّةٍ يعقد جرداق بينها وبين الإمام عليّ مقارنةً في الفلسفة والأخلاق؟',
        options: { A: 'أفلاطون', B: 'أرسطو', C: 'أبيقور', D: 'سقراط' },
        correct: 'D',
    },
    {
        question_text: 'ما المدينة التي اتّخذها الإمام عليّ عاصمةً لخلافته؟',
        options: { A: 'المدينة المنوّرة', B: 'دمشق', C: 'الكوفة', D: 'البصرة' },
        correct: 'C',
    },
    {
        question_text: 'يرى المؤلِّف أنّ جوهر خطاب الإمام عليّ في الحكم يقوم على…',
        options: {
            A: 'العدل ورفع الظلم عن الناس',
            B: 'توسيع الفتوحات وتثبيت السلطان',
            C: 'تفضيل القرابة في الولاية',
            D: 'مهادنة الوجهاء وأصحاب المال',
        },
        correct: 'A',
    },
    {
        question_text: 'أيّ معنًى يجعله عنوان الكتاب جامعًا لسيرة الإمام عليّ؟',
        options: {
            A: 'حكمة الشرق القديم',
            B: 'صوت العدالة الإنسانيّة',
            C: 'فارس الفتوحات',
            D: 'شاعر العرب الأكبر',
        },
        correct: 'B',
    },
    {
        question_text: 'ما الكتاب الذي جُمعت فيه خُطب الإمام عليّ ورسائله وحِكمه؟',
        options: { A: 'الأمالي', B: 'الغارات', C: 'نهج البلاغة', D: 'الإرشاد' },
        correct: 'C',
    },
    {
        question_text: 'أيّ قيمةٍ يعدّها جرداق أبرز ما قدّمه الإمام عليّ للفكر الإنسانيّ؟',
        options: {
            A: 'الفصاحة والبيان',
            B: 'الزهد والانقطاع',
            C: 'الحِنكة السياسيّة',
            D: 'المساواة بين الناس',
        },
        correct: 'D',
    },
    {
        question_text: 'ممّن يستمدّ الكتاب شواهده حين يتحدّث عن سياسة الإمام عليّ في المال العامّ؟',
        options: {
            A: 'من عهوده ورسائله إلى ولاته',
            B: 'من أشعار المتنبّي',
            C: 'من كتب الرحّالة الأوروبيّين',
            D: 'من دواوين الخراج العبّاسيّة',
        },
        correct: 'A',
    },
];

const TOTAL_QUESTIONS = BANK.length;

const iso = (ms) => new Date(ms).toISOString();

/** Development flags, read once from the URL. All are opt-in. */
export function readFlags(search = window.location.search) {
    const params = new URLSearchParams(search);

    return {
        fast: params.get('fast') === '1',
        showResult: params.get('showResult') === '1',
        closed: params.get('closed') === '1',
    };
}

function blankState() {
    return {
        authenticated: false,
        email: null,
        name: null,
        examStatus: 'not_started',
        startedAt: null,
        completedAt: null,
        correctAnswers: null,
        answeredQuestions: null,
        /*
         * The paper order, persisted. It is written once when the exam starts
         * and read back verbatim afterwards, so a refresh cannot reshuffle the
         * questions even if the bank order were ever randomised.
         */
        order: null,
        rows: [],
    };
}

/*
| Mock-only persistence.
|
| sessionStorage, not localStorage: the journey is meant to end when the tab
| does. This is the MOCK SERVER's database — it is emphatically not a
| client-side source of truth for the real application, which keeps all of this
| in MySQL and hands the browser nothing it could edit.
*/
function loadState(storage) {
    try {
        const raw = storage.getItem(STORAGE_KEY);

        return raw ? { ...blankState(), ...JSON.parse(raw) } : blankState();
    } catch {
        return blankState();
    }
}

function saveState(storage, state) {
    try {
        storage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch {
        // A private-mode tab with storage disabled still gets a working
        // journey; it simply restarts on refresh.
    }
}

/**
 * @param {object} [options]
 * @param {{fast:boolean, showResult:boolean, closed:boolean}} [options.flags]
 * @param {Storage} [options.storage]
 * @param {() => number} [options.now]  injectable clock, for tests
 * @param {number} [options.latency]     simulated round trip; 0 in tests
 */
export function createMockJourney({
    flags = {},
    storage = window.sessionStorage,
    now = Date.now,
    latency = LATENCY_MS,
} = {}) {
    const { fast = false, showResult = false, closed = false } = flags;
    const secondsPerQuestion = fast ? SECONDS_FAST : SECONDS_NORMAL;
    const roundTrip = () => (latency > 0 ? new Promise((resolve) => setTimeout(resolve, latency)) : Promise.resolve());

    let state = loadState(storage);
    const persist = () => saveState(storage, state);

    const requireAuth = () => {
        if (!state.authenticated) {
            throw new ApiError('unauthenticated', { status: 401 });
        }
    };

    /*
     * ?closed=1 shuts the portal for a signed-in contestant, which is the
     * terminal case: no start, no resume, no further answers.
     */
    const isClosed = () => closed && state.authenticated;

    const requireOpen = () => {
        if (isClosed()) {
            throw new ApiError('competition_closed', { status: 403 });
        }
    };

    const buildRows = () => {
        state.order = BANK.map((_, index) => index);
        state.rows = state.order.map((bankIndex, position) => ({
            question_id: 5000 + bankIndex,
            bankIndex,
            sequence: position + 1,
            opened_at: null,
            expires_at: null,
            answered_at: null,
            selected_option: null,
            timed_out: false,
        }));
    };

    const isTerminal = (row) => row.answered_at !== null || row.timed_out;

    /** Recomputed from the rows, never from a running counter. */
    const finalize = () => {
        state.correctAnswers = state.rows.filter(
            (row) => row.selected_option !== null && BANK[row.bankIndex].correct === row.selected_option,
        ).length;
        state.answeredQuestions = state.rows.filter((row) => row.selected_option !== null).length;
        state.examStatus = 'completed';
        state.completedAt = state.completedAt ?? iso(now());
    };

    const finalizeIfComplete = () => {
        if (!state.rows.some((row) => !isTerminal(row))) {
            finalize();
        }
    };

    /**
     * The question awaiting an answer, sweeping expired ones to terminal on
     * the way — the same walk advanceToLiveQuestion() does on the server.
     * Serving is a state change: an unopened question has its deadline written
     * here, once.
     */
    const liveRow = () => {
        for (let guard = 0; guard <= TOTAL_QUESTIONS; guard++) {
            const row = state.rows.filter((candidate) => !isTerminal(candidate)).sort((a, b) => a.sequence - b.sequence)[0];

            if (!row) {
                return null;
            }

            if (row.opened_at === null) {
                const openedAt = now();

                row.opened_at = iso(openedAt);
                row.expires_at = iso(openedAt + secondsPerQuestion * 1000);
                persist();

                return row;
            }

            if (now() <= Date.parse(row.expires_at)) {
                return row;
            }

            // Walked away, or the tab was closed past the deadline.
            row.timed_out = true;
            row.selected_option = null;
            row.answered_at = null;
            persist();
        }

        return null;
    };

    const payload = (row) => ({
        question_id: row.question_id,
        question_text: BANK[row.bankIndex].question_text,
        options: BANK[row.bankIndex].options,
        sequence: row.sequence,
        total_questions: TOTAL_QUESTIONS,
        opened_at: row.opened_at,
        expires_at: row.expires_at,
        server_time: iso(now()),
        // Survives refresh by construction: the deadline is absolute and was
        // written once, so what is left is simply what is left.
        seconds_remaining: Math.max(0, (Date.parse(row.expires_at) - now()) / 1000),
    });

    const envelope = () => {
        const row = state.examStatus === 'completed' ? null : liveRow();

        if (row === null) {
            finalize();
            persist();
        }

        return {
            exam_status: state.examStatus,
            started_at: state.startedAt,
            question: row === null ? null : payload(row),
        };
    };

    return {
        /** Test seam. Never read by the app. */
        __state: () => state,

        async login({ email, password } = {}) {
            await roundTrip();

            if (!email || !password) {
                throw new ApiError('validation_error', {
                    status: 422,
                    fields: { email: ['The email field is required.'] },
                });
            }

            if (
                email.trim().toLowerCase() !== MOCK_CONTESTANT.email ||
                password !== MOCK_CONTESTANT.password
            ) {
                // One reason for a wrong password and an unknown address alike,
                // exactly as the backend refuses, so the screen cannot be used
                // to discover who is registered.
                throw new ApiError('invalid_credentials', {
                    status: 422,
                    fields: { email: ['These credentials do not match our records.'] },
                });
            }

            state.authenticated = true;
            state.email = MOCK_CONTESTANT.email;
            state.name = MOCK_CONTESTANT.name;
            persist();

            return { user: { id: 1, name: state.name, email: state.email } };
        },

        async logout() {
            await roundTrip();

            // Ends the journey outright: next visit starts from login again.
            state = blankState();

            try {
                storage.removeItem(STORAGE_KEY);
            } catch {
                // Nothing to clear.
            }

            return { message: 'Logged out.' };
        },

        async status() {
            await roundTrip();

            const open = !isClosed();

            return {
                competition: 'المسابقة الطلابيّة',
                status: open ? 'open' : 'closed',
                open,
                reason: open ? null : 'competition_closed',
                total_questions: TOTAL_QUESTIONS,
                seconds_per_question: secondsPerQuestion,
                show_result: showResult,
                server_time: iso(now()),
                // Present only when authenticated — the same tell the real
                // status endpoint gives.
                ...(state.authenticated
                    ? { participation: { exam_status: state.examStatus, account_status: 'created' } }
                    : {}),
            };
        },

        async start() {
            await roundTrip();

            requireAuth();
            requireOpen();

            if (state.examStatus === 'not_started') {
                buildRows();
                state.examStatus = 'in_progress';
                state.startedAt = iso(now());
                persist();
            }

            // Resume is the identical call: a paper that already exists is
            // reused, and the deadline of the live question is untouched.
            return envelope();
        },

        async current() {
            await roundTrip();
            requireAuth();
            requireOpen();

            return envelope();
        },

        async answer(questionId, selectedOption) {
            await roundTrip();

            requireAuth();
            requireOpen();

            if (state.examStatus === 'completed') {
                throw new ApiError('exam_completed', { status: 409 });
            }

            const row = liveRow();

            // Not the live question, already terminal, or never served — all
            // indistinguishable to the caller, deliberately.
            if (row === null || row.question_id !== questionId) {
                throw new ApiError('question_not_available', { status: 422 });
            }

            if (now() > Date.parse(row.expires_at)) {
                row.timed_out = true;
                finalizeIfComplete();
                persist();

                throw new ApiError('question_expired', { status: 422 });
            }

            row.selected_option = selectedOption;
            row.answered_at = iso(now());
            row.timed_out = false;
            finalizeIfComplete();
            persist();

            const next = envelope();

            return {
                accepted: true,
                sequence: row.sequence,
                // Whether the answer was right is deliberately NOT returned.
                exam_status: next.exam_status,
                next_question: next.question,
            };
        },

        async result() {
            await roundTrip();

            requireAuth();

            const base = {
                exam_status: state.examStatus,
                completed_at: state.completedAt,
                show_result: showResult,
            };

            // The score is withheld by the SERVER, not hidden by the UI.
            if (!showResult || state.examStatus !== 'completed') {
                return base;
            }

            return {
                ...base,
                correct_answers: state.correctAnswers ?? 0,
                answered_questions: state.answeredQuestions ?? 0,
                total_questions: TOTAL_QUESTIONS,
            };
        },
    };
}

export { TOTAL_QUESTIONS, SECONDS_NORMAL, SECONDS_FAST, STORAGE_KEY };
