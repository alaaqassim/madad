/*
| Arabic UI copy.
|
| Every string a contestant can see lives here. In particular, backend failures
| are translated FROM their machine-readable `reason` code — the server's own
| English message is never rendered, so no exception wording, validation text
| or stack detail can reach the screen.
*/

export const copy = {
    brand: 'مداد الغدير',
    competitionFallback: 'المسابقة الطلابيّة',

    login: {
        eyebrow: 'الدخول',
        title: 'ادخل إلى منصّة المسابقة',
        subtitle: 'أدخل معلومات التسجيل المرسلة بالبريد الإلكتروني.',
        section: 'بيانات الدخول',
        email: 'البريد الإلكتروني',
        emailPlaceholder: 'name@example.com',
        password: 'كلمة المرور',
        passwordPlaceholder: 'كلمة المرور المرسلة إليك',
        submit: 'دخول',
        submitting: 'جارٍ التحقق…',
        required: 'هذا الحقل مطلوب.',
        showPassword: 'إظهار كلمة المرور',
        hidePassword: 'إخفاء كلمة المرور',
    },

    status: {
        eyebrow: 'المسابقة',
        checking: 'جارٍ التحقق من حالة المسابقة…',
        notOpenTitle: 'المسابقة لم تبدأ بعد',
        notOpenBody: 'سيُفتح باب المشاركة في الموعد المعلن. يمكنك العودة إلى هذه الصفحة لاحقًا.',
        closedTitle: 'انتهت المسابقة',
        closedBody: 'أُغلق باب المشاركة ولا يمكن الدخول إلى الأسئلة. شكرًا لمشاركتك.',
        noneTitle: 'لا توجد مسابقة متاحة حاليًا',
        noneBody: 'لم تُفتح أي مسابقة على المنصّة في الوقت الحالي.',
        readyTitle: 'أنت على وشك البدء',
        readyBodyFresh: 'ستُعرض عليك الأسئلة واحدًا تلو الآخر، ولكل سؤال وقت محدّد يبدأ فور ظهوره.',
        readyBodyResume: 'لديك محاولة قائمة. سنعيدك إلى السؤال الذي توقّفت عنده تمامًا.',
        rules: 'ملاحظات قبل البدء',
        ruleQuestions: (total) => `عدد الأسئلة: ${total}`,
        ruleSeconds: (seconds) => `الوقت لكل سؤال: ${seconds} ثانية`,
        ruleNoBack: 'لا يمكن الرجوع إلى سؤال سابق بعد تجاوزه.',
        ruleFixedSchedule: 'لكل سؤال وقته المحدّد منذ لحظة البدء، ولا يتغيّر بالإجابة المبكرة.',
        ruleDuration: (minutes) => `المدّة الإجماليّة للمحاولة: ${minutes} دقيقة`,
        // Shown only when the remaining competition window is shorter than the
        // personal allowance: a late starter must know before they begin.
        ruleShortWindow: (minutes) =>
            `تنبيه: المتبقّي من وقت المسابقة ${minutes} دقيقة فقط، وستنتهي محاولتك بانتهائه.`,
        start: 'ابدأ المسابقة',
        resume: 'أكمل المسابقة',
        starting: 'جارٍ التحضير…',
        refresh: 'تحديث الحالة',
        signOut: 'تسجيل الخروج',
        loginCta: 'تسجيل الدخول',
    },

    exam: {
        eyebrow: 'الأسئلة',
        progress: (current, total) => `السؤال ${current} من ${total}`,
        progressLabel: 'تقدّمك في المسابقة',
        timerLabel: 'الوقت المتبقّي للسؤال',
        timerRemaining: (seconds) => `متبقٍ ${seconds} ثانية`,
        timerWarning: 'الوقت يوشك على الانتهاء',
        optionsLabel: 'اختر إجابة واحدة',
        submit: 'تأكيد الإجابة',
        submitting: 'جارٍ الإرسال…',
        chooseFirst: 'اختر إجابة أولًا',
        loadingQuestion: 'جارٍ تحميل السؤال…',
        timeoutTitle: 'انتهى وقت هذا السؤال',
        timeoutBody: 'لم يعد بالإمكان الإجابة عليه. جارٍ الانتقال إلى الحالة التالية…',
        syncing: 'جارٍ التحقق من حالتك لدى الخادم…',
        leaveWarning: 'الوقت مستمر. الخروج الآن لن يوقف مؤقّت السؤال.',

        // The transition between one fixed slot and the next.
    },

    completed: {
        eyebrow: 'انتهت المحاولة',
        title: 'أكملت المسابقة',
        bodyHidden: 'شكرًا لمشاركتك. سُجّلت إجاباتك بنجاح، وستُعلن النتائج لاحقًا عبر القنوات الرسمية.',
        bodyVisible: 'شكرًا لمشاركتك. هذه نتيجتك النهائية كما احتسبها الخادم.',
        scoreLabel: 'النتيجة',
        score: (correct, total) => `${correct} من ${total}`,
        answeredLabel: 'الأسئلة المُجابة',
        answered: (answered, total) => `${answered} من ${total}`,
        completedAt: 'وقت الانتهاء',
        signOut: 'تسجيل الخروج',
    },

    errors: {
        // ── login ────────────────────────────────────────────────────────────
        invalid_credentials: 'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
        too_many_attempts: 'محاولات كثيرة خلال وقت قصير. انتظر قليلًا ثم أعد المحاولة.',
        validation_error: 'تحقّق من البيانات المُدخلة ثم أعد المحاولة.',
        unauthenticated: 'انتهت جلستك. يرجى تسجيل الدخول مرّة أخرى.',

        // ── portal gate ──────────────────────────────────────────────────────
        competition_not_open: 'المسابقة غير مفتوحة حاليًا.',
        competition_closed: 'انتهت المسابقة ولم يعد الدخول متاحًا.',
        no_competition: 'لا توجد مسابقة متاحة حاليًا.',
        not_a_contestant: 'حسابك غير مسجَّل في هذه المسابقة.',
        account_not_provisioned: 'لم يُفعَّل اشتراكك في المسابقة بعد.',

        // ── exam ─────────────────────────────────────────────────────────────
        exam_completed: 'أكملت المسابقة بالفعل.',
        no_current_question: 'لا يوجد سؤال بانتظار إجابتك.',
        question_not_available: 'هذا السؤال لم يعد متاحًا.',
        question_expired: 'انتهى وقت هذا السؤال.',
        paper_not_ready: 'أسئلة المسابقة غير جاهزة بعد. حاول لاحقًا.',

        /*
         * Client-side only: the submission left the browser but its outcome was
         * never confirmed. The server has since told us the question is still
         * awaiting an answer, so the honest instruction is "choose again".
         */
        answer_unconfirmed:
            'لم نتمكّن من تأكيد وصول إجابتك، والسؤال ما زال مفتوحًا. اختر إجابتك وأكّدها مرّة أخرى.',

        // ── transport ────────────────────────────────────────────────────────
        network_error: 'تعذّر الاتصال بالخادم. تحقّق من اتصالك بالإنترنت.',
        server_error: 'حدث خطأ في الخادم. أعد المحاولة بعد قليل.',
        not_found: 'الصفحة المطلوبة غير موجودة.',
        unknown: 'حدث خطأ غير متوقَّع. أعد المحاولة.',
    },

    actions: {
        retry: 'إعادة المحاولة',
        dismiss: 'إغلاق',
    },
};

/** The Arabic sentence for a backend `reason`, never the backend's own text. */
export function messageForReason(reason) {
    return copy.errors[reason] ?? copy.errors.unknown;
}
