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
|   * The clock is the server's. Two anchors are persisted and no expiry ever
|     is: `startedAt` bounds the attempt, `questionStartedAt` is when the live
|     question became live. Every deadline is arithmetic on the pair.
|   * The current question is an INDEX into the persisted order, reconciled
|     forward against elapsed time on every request. Time never pauses.
|   * Answering early ADVANCES IMMEDIATELY: the next question is served at once
|     with its own window of up to `secondsPerQuestion`.
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

/** The contestant's personal allowance, matching competition_settings. */
const EXAM_DURATION_MINUTES = 60;

/** The `answers` placeholder for a position with no recorded option. */
const NO_ANSWER = '-';

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
        /** A ZERO-BASED INDEX into `order`, never a question id. */
        currentQuestion: null,
        /**
         * When the LIVE question became live. Persisted because under immediate
         * advance it cannot be derived: it depends on when the previous answer
         * landed, which is exactly the thing a refresh must not be able to move.
         */
        questionStartedAt: null,
        /** One character per position, over A|B|C|D|-. */
        answers: null,
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

    const buildPaper = () => {
        state.order = BANK.map((_, index) => 5000 + index);
        state.currentQuestion = 0;
        state.answers = NO_ANSWER.repeat(TOTAL_QUESTIONS);
    };

    const bankIndexOf = (questionId) => questionId - 5000;
    const questionIdAt = (index) => state.order?.[index] ?? null;
    const answerAt = (index) => {
        const mark = state.answers?.[index] ?? NO_ANSWER;

        return mark === NO_ANSWER ? null : mark;
    };

    const writeAnswer = (index, option) => {
        const marks = state.answers.split('');
        marks[index] = option ?? NO_ANSWER;
        state.answers = marks.join('');
    };

    // ── the timeline: two anchors, no stored expiry ─────────────────────────

    const startedMs = () => Date.parse(state.startedAt);
    const windowMs = secondsPerQuestion * 1000;

    /** min(personal allowance, competition window). The mock has no window. */
    const effectiveEnd = () => startedMs() + EXAM_DURATION_MINUTES * 60 * 1000;

    /** When the live question became live. Read, never derived. */
    const openedAt = () => Date.parse(state.questionStartedAt ?? state.startedAt);

    /** Its own window, unless the attempt ends first. */
    const closesAt = () => Math.min(openedAt() + windowMs, effectiveEnd());

    /** Whole question windows that have elapsed since the live one opened. */
    const windowsElapsed = () => Math.floor(Math.max(0, now() - openedAt()) / windowMs);

    /** Recomputed from the answer string, never from a running counter. */
    const finalize = () => {
        state.answers = (state.answers ?? '').padEnd(TOTAL_QUESTIONS, NO_ANSWER).slice(0, TOTAL_QUESTIONS);
        state.currentQuestion = TOTAL_QUESTIONS;

        state.correctAnswers = state.order.filter(
            (questionId, position) => answerAt(position) === BANK[bankIndexOf(questionId)].correct,
        ).length;
        state.answeredQuestions = state.order.filter((_, position) => answerAt(position) !== null).length;

        state.examStatus = 'completed';
        state.questionStartedAt = null;
        state.completedAt = state.completedAt ?? iso(now());
    };

    /**
     * Consume every question window that has fully elapsed, and end the exam if
     * the time is up. The anchor moves forward by exactly one window per
     * position consumed — NOT to `now`, which would hand back the seconds the
     * contestant was away. Positions passed over keep their '-' forever.
     */
    const reconcile = () => {
        if (state.examStatus !== 'in_progress') {
            return;
        }

        if (now() >= effectiveEnd() || state.currentQuestion >= TOTAL_QUESTIONS) {
            finalize();
            persist();

            return;
        }

        const windows = windowsElapsed();

        if (windows <= 0) {
            return;
        }

        const target = Math.min(TOTAL_QUESTIONS, state.currentQuestion + windows);

        for (let position = state.currentQuestion; position < target; position += 1) {
            writeAnswer(position, null);
        }

        state.questionStartedAt = iso(openedAt() + (target - state.currentQuestion) * windowMs);
        state.currentQuestion = target;

        if (target >= TOTAL_QUESTIONS) {
            finalize();
        }

        persist();
    };

    /** Record the answer and open the next question IMMEDIATELY. */
    const advance = (option) => {
        writeAnswer(state.currentQuestion, option);
        state.currentQuestion += 1;
        state.questionStartedAt = iso(now());

        if (state.currentQuestion >= TOTAL_QUESTIONS) {
            finalize();
        }

        persist();
    };

    /** A window that closed unanswered: one window forward, never to `now`. */
    const expireCurrent = () => {
        writeAnswer(state.currentQuestion, null);
        state.currentQuestion += 1;
        state.questionStartedAt = iso(openedAt() + windowMs);

        if (state.currentQuestion >= TOTAL_QUESTIONS) {
            finalize();
        }

        persist();
    };

    // ── payloads ────────────────────────────────────────────────────────────

    const payload = (index) => {
        const questionId = questionIdAt(index);
        const expiresAt = closesAt();

        return {
            question_id: questionId,
            question_text: BANK[bankIndexOf(questionId)].question_text,
            options: BANK[bankIndexOf(questionId)].options,
            sequence: index + 1,
            total_questions: TOTAL_QUESTIONS,
            // Derived here, never stored: a refresh recomputes the same values.
            opened_at: iso(openedAt()),
            expires_at: iso(expiresAt),
            server_time: iso(now()),
            seconds_remaining: Math.min(
                secondsPerQuestion,
                Math.max(0, (expiresAt - now()) / 1000),
            ),
        };
    };

    const envelope = () => {
        reconcile();

        const base = { exam_status: state.examStatus, started_at: state.startedAt };

        // An in-progress contestant always has a live question: reconcile() has
        // just moved them to the window that contains `now`.
        return state.examStatus === 'in_progress'
            ? { ...base, question: payload(state.currentQuestion) }
            : { ...base, question: null };
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
                exam_duration_minutes: EXAM_DURATION_MINUTES,
                // No availability window in the mock, so the allowance is all
                // a contestant beginning now would get.
                seconds_available: EXAM_DURATION_MINUTES * 60,
                starts_at: null,
                ends_at: null,
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

            // A first start is `not_started` AND no startedAt. Index 0 alone
            // does not mean fresh — a contestant who has begun and not yet
            // answered sits at index 0 with the clock already running.
            if (state.examStatus === 'not_started' && state.startedAt === null) {
                buildPaper();
                state.examStatus = 'in_progress';
                // Both anchors start together at index 0.
                state.startedAt = iso(now());
                state.questionStartedAt = state.startedAt;
                persist();
            }

            // Resume is the identical call: the paper is reused and startedAt
            // is never moved, so no window is reopened.
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

            reconcile();

            if (state.examStatus === 'completed') {
                throw new ApiError('exam_completed', { status: 409 });
            }

            const index = state.currentQuestion;
            const expectedId = questionIdAt(index);

            // Not the live question, already answered, or not on this paper —
            // all indistinguishable to the caller, deliberately.
            if (questionId != null && questionId !== expectedId) {
                const position = state.order.indexOf(questionId);
                const lost = position !== -1 && position < index && answerAt(position) === null;

                throw new ApiError(lost ? 'question_expired' : 'question_not_available', { status: 422 });
            }

            if (now() > closesAt()) {
                expireCurrent();

                throw new ApiError('question_expired', { status: 422 });
            }

            advance(selectedOption);

            const next = envelope();

            return {
                accepted: true,
                sequence: index + 1,
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

export { TOTAL_QUESTIONS, SECONDS_NORMAL, SECONDS_FAST, EXAM_DURATION_MINUTES, STORAGE_KEY };
