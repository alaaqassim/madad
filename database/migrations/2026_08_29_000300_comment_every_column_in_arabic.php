<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An Arabic comment on every column, written into the schema itself.
 *
 * The reason this is a migration and not a document: a document beside the
 * database is a document that drifts. A COLUMN COMMENT travels with the column,
 * survives a dump and restore, and is the first thing anybody sees in
 * phpMyAdmin or SHOW FULL COLUMNS - including whoever inherits this after us.
 *
 * The definitions are never retyped here. Each column's definition is read back
 * from the server's own SHOW CREATE TABLE and reused verbatim, with only the
 * COMMENT clause replaced. MariaDB requires the whole definition on MODIFY
 * COLUMN, and retyping thirty of them by hand is how a column silently loses a
 * default or its nullability.
 *
 * The two views (madad_results, madad_top100) are absent on purpose: MariaDB
 * has no column comments on views. Their columns carry the meaning of the
 * competition_users columns they select.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const TABLES = [
        'competition_settings' => 'إعدادات المسابقة. صف واحد فقط لأن مداد نظام مسابقة واحدة.',
        'competition_questions' => 'بنك الأسئلة. سؤال واحد في كل صف مع خياراته الأربعة.',
        'competition_users' => 'المتسابق ومحاولته كاملة في صف واحد: سجل الاستيراد، وحالة الحساب، وحالة إرسال بيانات الدخول، وحالة الامتحان، والنتيجة.',
        'users' => 'حسابات الدخول. لكل متسابق حساب هنا، وكلمة المرور مخزنة كبصمة لا كنص.',
        'sessions' => 'جلسات Laravel. مخزنة في قاعدة البيانات حاليا (SESSION_DRIVER=database).',
        'cache' => 'الكاش وعدادات تحديد معدل الطلبات. مخزنة في قاعدة البيانات حاليا (CACHE_STORE=database).',
        'cache_locks' => 'أقفال الكاش، تديرها Laravel.',
        'jobs' => 'طابور المهام. غير مستخدم في مداد: بريد بيانات الدخول يرسل مباشرة لا عبر طابور.',
        'job_batches' => 'دفعات المهام. غير مستخدم في مداد.',
        'failed_jobs' => 'المهام الفاشلة. غير مستخدم في مداد.',
        'password_reset_tokens' => 'رموز إعادة تعيين كلمة المرور. غير مستخدم في المرحلة الأولى: لا يوجد مسار استعادة كلمة مرور.',
        'migrations' => 'سجل الترحيلات المنفذة، تديره Laravel.',
    ];

    /** @var array<string, array<string, string>> */
    private const COLUMNS = [
        'competition_settings' => [
            'id' => 'المفتاح الأساسي. الجدول يحمل صفا واحدا دائما لأن مداد نظام مسابقة واحدة.',
            'name' => 'اسم المسابقة كما يظهر للمتسابق في الواجهة.',
            'status' => 'حالة المسابقة: draft مسودة، ready جاهزة، open مفتوحة للدخول، closed انتهت نهائيا. الدخول مسموح عند open وحدها، و closed تمنع البدء والاستئناف وعرض السؤال وإرسال الإجابة.',
            'show_result' => 'هل يرى المتسابق درجته فور انتهائه؟ 1 نعم، 0 لا.',
            'question_count' => 'عدد الأسئلة التي يجلس لها كل متسابق، تقتطع من بنك الأسئلة بعد الخلط. إن نقص البنك عن هذا العدد رفض النظام بدء الامتحان بدل تسليم ورقة ناقصة.',
            'seconds_per_question' => 'المهلة القصوى للسؤال الواحد بالثواني. تبدأ لحظة ظهور السؤال، لا على شبكة زمنية ثابتة من بداية الامتحان.',
            'exam_duration_minutes' => 'المدة الشخصية للمحاولة بالدقائق، تحسب من لحظة ضغط المتسابق على ابدأ لا من فتح المسابقة.',
            'starts_at' => 'بداية نافذة الإتاحة العامة بتوقيت بغداد. قبلها لا يبدأ أحد.',
            'ends_at' => 'نهاية نافذة الإتاحة العامة بتوقيت بغداد. تقص المدة الشخصية لمن بدأ متأخرا.',
            'created_at' => 'وقت إنشاء الصف، تديره Laravel.',
            'updated_at' => 'وقت آخر تعديل على الصف، تديره Laravel.',
        ],

        'competition_questions' => [
            'id' => 'المفتاح الأساسي، وهو المعرف نفسه المخزن داخل مصفوفة question_order الخاصة بكل متسابق.',
            'question_number' => 'رقم السؤال في بنك الأسئلة كما ورد في الملف الأصلي. يستخدم للترتيب الثابت قبل الخلط، ولا علاقة له بترتيب أي متسابق.',
            'question_text' => 'نص السؤال كما يعرض على المتسابق.',
            'option_a' => 'نص الخيار الأول (أ).',
            'option_b' => 'نص الخيار الثاني (ب).',
            'option_c' => 'نص الخيار الثالث (ج).',
            'option_d' => 'نص الخيار الرابع (د).',
            'correct_option' => 'حرف الإجابة الصحيحة. لا يغادر الخادم إطلاقا ولا يظهر في أي رد من الواجهة البرمجية.',
            'created_at' => 'وقت إنشاء الصف، تديره Laravel.',
            'updated_at' => 'وقت آخر تعديل على الصف، تديره Laravel.',
        ],

        'competition_users' => [
            'id' => 'المفتاح الأساسي لصف المشاركة.',
            'user_id' => 'حساب الدخول المرتبط بالمتسابق. يقبل NULL عمدا لأن صف المشاركة ينشأ قبل الحساب، فتبقى محاولة إنشاء فاشلة صفا ظاهرا قابلا لإعادة المحاولة بدل أن تختفي.',
            'contestant_name' => 'اسم المتسابق كما ورد في قائمة الطلبة. سجل استيراد يبقى حتى لو لم ينشأ الحساب.',
            'contestant_email' => 'بريد المتسابق كما ورد في القائمة. هو معرف الدخول وعنوان إرسال بيانات الدخول معا.',
            'source_reference' => 'مرجع اختياري من مصدر القائمة (رقم تسجيل أو ما يقابله) للمطابقة مع سجلات الجهة المرسلة.',
            'account_status' => 'حالة إنشاء حساب الدخول: pending لم ينشأ بعد، created أنشئ، failed فشل الإنشاء.',
            'credentials_generated_at' => 'وقت توليد كلمة المرور. كلمة المرور نفسها لا تخزن هنا ولا في أي مكان، بل بصمتها في users.password وحدها.',
            'email_attempts' => 'عدد محاولات إرسال بريد بيانات الدخول. تزداد عند النجاح والفشل معا.',
            'credentials_sent_at' => 'وقت آخر إرسال ناجح لبريد بيانات الدخول. تفرغ عند الفشل. حالة الإرسال تشتق من هذا العمود مع email_attempts ولا تخزن كعمود مستقل.',
            'email_last_error' => 'سبب آخر فشل في الإرسال كما ورد من مزود البريد.',
            'exam_status' => 'حالة الامتحان: not_started لم يبدأ، in_progress جار، completed انتهى. بدء المحاولة يعرف من started_at لا من current_question، لأن الموقع صفر يعني أيضا بدأ وهو على السؤال الأول.',
            'started_at' => 'لحظة ضغط المتسابق على ابدأ بتوقيت الخادم. مرساة المحاولة كلها، ولا تتغير أبدا بعد كتابتها مهما تكرر الدخول.',
            'effective_end_at' => 'النهاية الفعلية للمحاولة: الأقل من (started_at زائد المدة الشخصية) ومن نهاية نافذة المسابقة. تكتب لحظة البدء لتتمكن عروض النتائج وهي SQL خالص من رؤية من انتهى وقته ولم يعد أبدا.',
            'completed_at' => 'لحظة الختام الفعلي: إما بإجابة السؤال الأخير، وإما ببلوغ النهاية الفعلية.',
            'question_order' => 'ورقة المتسابق: مصفوفة JSON بمعرفات الأسئلة بعد الخلط. تولد مرة واحدة وتحفظ، ولا يعاد خلطها عند التحديث أو الخروج والدخول أو من جهاز آخر.',
            'current_question' => 'موقع السؤال الحالي داخل question_order ابتداء من صفر. ليس معرف سؤال. السؤال الحي هو question_order[current_question].',
            'current_question_started_at' => 'لحظة صيرورة السؤال الحالي حيا بتوقيت الخادم، وهي لحظة وصول الإجابة السابقة لأن التقدم فوري. منها تحسب مهلة السؤال، ولا تخزن أي نهاية.',
            'answers' => 'إجابات المتسابق، حرف واحد لكل موقع وبنفس ترتيب question_order: A أو B أو C أو D، والشرطة تعني لم يجب أو انتهت مهلة السؤال.',
            'correct_answers' => 'عدد الإجابات الصحيحة. لا تحتسب إلا إجابة وصلت فعلا إلى الخادم.',
            'answered_questions' => 'عدد الأسئلة التي أجاب عنها فعلا، من غير المواقع المتروكة.',
            'created_at' => 'وقت إنشاء الصف، تديره Laravel.',
            'updated_at' => 'وقت آخر تعديل على الصف، تديره Laravel.',
        ],

        'users' => [
            'id' => 'المفتاح الأساسي لحساب الدخول.',
            'name' => 'اسم صاحب الحساب كما يعرض بعد الدخول.',
            'email' => 'بريد الدخول، فريد على مستوى الجدول.',
            'email_verified_at' => 'وقت تأكيد البريد. غير مستخدم في مداد: الحسابات تنشأ مؤكدة من قائمة الطلبة.',
            'password' => 'بصمة كلمة المرور. النص الأصلي لا يخزن ولا يمكن استرجاعه، ويرسل مرة واحدة فقط في بريد بيانات الدخول.',
            'remember_token' => 'رمز تذكرني من Laravel. غير مستخدم في مداد.',
            'created_at' => 'وقت إنشاء الحساب، تديره Laravel.',
            'updated_at' => 'وقت آخر تعديل على الحساب، تديره Laravel.',
        ],

        'sessions' => [
            'id' => 'معرف الجلسة، وهو ما يحمله كوكي المتصفح.',
            'user_id' => 'صاحب الجلسة إن كان مسجل الدخول.',
            'ip_address' => 'عنوان الشبكة الذي جاءت منه الجلسة.',
            'user_agent' => 'بصمة المتصفح كما أرسلها العميل.',
            'payload' => 'محتوى الجلسة. لا يحمل أي حالة امتحان: مصدر التقدم الوحيد هو قاعدة البيانات.',
            'last_activity' => 'وقت آخر نشاط بصيغة طابع زمني رقمي، تستخدمه Laravel لتنظيف الجلسات المنتهية.',
        ],

        'cache' => [
            'key' => 'مفتاح العنصر المخزن، ومنه مفاتيح عدادات تحديد معدل الطلبات.',
            'value' => 'القيمة المخزنة مسلسلة.',
            'expiration' => 'وقت انتهاء الصلاحية بصيغة طابع زمني رقمي.',
        ],

        'cache_locks' => [
            'key' => 'مفتاح القفل.',
            'owner' => 'معرف مالك القفل الحالي.',
            'expiration' => 'وقت انتهاء القفل بصيغة طابع زمني رقمي.',
        ],

        'jobs' => [
            'id' => 'المفتاح الأساسي للمهمة.',
            'queue' => 'اسم الطابور.',
            'payload' => 'محتوى المهمة مسلسلا.',
            'attempts' => 'عدد محاولات التنفيذ.',
            'reserved_at' => 'وقت حجز المهمة للتنفيذ.',
            'available_at' => 'الوقت الذي تصبح فيه المهمة قابلة للتنفيذ.',
            'created_at' => 'وقت إنشاء المهمة.',
        ],

        'job_batches' => [
            'id' => 'معرف الدفعة.',
            'name' => 'اسم الدفعة.',
            'total_jobs' => 'إجمالي عدد المهام في الدفعة.',
            'pending_jobs' => 'عدد المهام التي لم تنفذ بعد.',
            'failed_jobs' => 'عدد المهام الفاشلة.',
            'failed_job_ids' => 'معرفات المهام الفاشلة.',
            'options' => 'خيارات الدفعة.',
            'cancelled_at' => 'وقت إلغاء الدفعة.',
            'created_at' => 'وقت إنشاء الدفعة.',
            'finished_at' => 'وقت انتهاء الدفعة.',
        ],

        'failed_jobs' => [
            'id' => 'المفتاح الأساسي.',
            'uuid' => 'معرف فريد للمهمة الفاشلة.',
            'connection' => 'اتصال الطابور الذي فشلت عليه.',
            'queue' => 'اسم الطابور.',
            'payload' => 'محتوى المهمة مسلسلا.',
            'exception' => 'نص الاستثناء الذي أسقط المهمة.',
            'failed_at' => 'وقت الفشل.',
        ],

        'password_reset_tokens' => [
            'email' => 'بريد طالب إعادة التعيين.',
            'token' => 'بصمة رمز إعادة التعيين.',
            'created_at' => 'وقت إصدار الرمز.',
        ],

        'migrations' => [
            'id' => 'المفتاح الأساسي.',
            'migration' => 'اسم ملف الترحيلة المنفذة.',
            'batch' => 'رقم الدفعة التي نفذت فيها، ويستخدم للتراجع.',
        ],
    ];

    public function up(): void
    {
        $this->apply(fn (string $table, string $column): string => self::COLUMNS[$table][$column] ?? '');

        foreach (self::TABLES as $table => $comment) {
            $this->commentTable($table, $comment);
        }
    }

    public function down(): void
    {
        $this->apply(fn (string $table, string $column): string => '');

        foreach (array_keys(self::TABLES) as $table) {
            $this->commentTable($table, '');
        }
    }

    /**
     * Rewrite every listed column with its own definition plus a new comment.
     *
     * @param  callable(string, string): string  $commentFor
     */
    private function apply(callable $commentFor): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! $this->tableExists($table)) {
                continue;
            }

            $definitions = $this->definitions($table);
            $parts = [];

            foreach (array_keys($columns) as $column) {
                if (! isset($definitions[$column])) {
                    continue;
                }

                $parts[] = sprintf(
                    'MODIFY COLUMN `%s` %s COMMENT %s',
                    $column,
                    $definitions[$column],
                    $this->quote($commentFor($table, $column)),
                );
            }

            if ($parts !== []) {
                DB::statement("ALTER TABLE `{$table}` ".implode(', ', $parts));
            }
        }
    }

    private function commentTable(string $table, string $comment): void
    {
        if ($this->tableExists($table)) {
            DB::statement("ALTER TABLE `{$table}` COMMENT = ".$this->quote($comment));
        }
    }

    /**
     * Each column's definition exactly as the server renders it, minus any
     * comment it already carries.
     *
     * @return array<string, string>
     */
    private function definitions(string $table): array
    {
        $create = (array) DB::selectOne("SHOW CREATE TABLE `{$table}`");
        $sql = (string) ($create['Create Table'] ?? '');

        $definitions = [];

        foreach (explode("\n", $sql) as $line) {
            $line = rtrim(trim($line), ',');

            // Only column lines open with a backtick; keys and constraints open
            // with PRIMARY, UNIQUE, KEY or CONSTRAINT.
            if (preg_match('/^`([^`]+)`\s+(.+)$/', $line, $matches) !== 1) {
                continue;
            }

            $definitions[$matches[1]] = preg_replace(
                '/\s+COMMENT\s+\'(?:[^\']|\'\')*\'\s*$/i',
                '',
                $matches[2],
            );
        }

        return $definitions;
    }

    private function tableExists(string $table): bool
    {
        return DB::selectOne(
            'SELECT 1 AS present FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = ?',
            [$table, 'BASE TABLE'],
        ) !== null;
    }

    private function quote(string $value): string
    {
        return DB::getPdo()->quote($value);
    }
};
