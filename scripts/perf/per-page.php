<?php

/**
 * Every query on every page, one line each.
 *
 * journey.php answers "what does an exam cost". This answers "which query,
 * exactly" - the one that finds a slow statement rather than a slow page.
 * Each line is flagged when it reaches 5ms.
 *
 * It writes: one answer is submitted, then the contestant is put back.
 *
 * Usage: php scripts/perf/per-page.php madad_dev
 */

use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use Illuminate\Support\Facades\DB;

/** @var array{0: mixed, 1: PerfProbe} $boot */
$boot = require __DIR__.'/bootstrap.php';
$probe = $boot[1];

$password = getenv('MADAD_PERF_PASSWORD') ?: 'Madad@123456';

$participation = CompetitionUser::query()
    ->where('account_status', 'created')
    ->where('exam_status', 'not_started')
    ->whereNotNull('user_id')
    ->orderBy('id')
    ->first();

if (! $participation) {
    fwrite(STDERR, "لا يوجد متسابق جاهز (account_status=created, exam_status=not_started)\n");
    exit(1);
}

$pages = [];

$pages['status  (حالة المسابقة)'] = $probe->request('GET', '/api/competition/status');
$pages['login   (تسجيل الدخول)'] = $probe->request('POST', '/api/login', [
    'email' => $participation->contestant_email,
    'password' => $password,
]);
$pages['start   (بدء الامتحان)'] = $probe->request('POST', '/api/exam/start');
$pages['current (السؤال الحالي)'] = $probe->request('GET', '/api/exam/current');

$participation->refresh();

$pages['answer  (إرسال إجابة)'] = $probe->request('POST', '/api/exam/answer', [
    'question_id' => $participation->questionIdAt((int) $participation->current_question),
    'selected_option' => 'A',
]);

$pages['result  (النتيجة)'] = $probe->request('GET', '/api/exam/result');

// ── The report ───────────────────────────────────────────────────────────────

$grand = array_fill_keys(PerfProbe::groups(), ['n' => 0, 'ms' => 0.0, 'max' => 0.0]);
$everything = [];

foreach ($pages as $label => $page) {
    $split = array_fill_keys(PerfProbe::groups(), ['n' => 0, 'ms' => 0.0, 'max' => 0.0]);

    printf("\n╔══ %s ══  (HTTP %d)\n", $label, $page['status']);

    foreach ($page['queries'] as $i => $query) {
        $group = PerfProbe::group($query['sql']);

        foreach ([&$split, &$grand] as &$bucket) {
            $bucket[$group]['n']++;
            $bucket[$group]['ms'] += $query['ms'];
            $bucket[$group]['max'] = max($bucket[$group]['max'], $query['ms']);
        }

        unset($bucket);

        $everything[] = $query['ms'];

        printf(
            "║ %2d. %6.2f ms  %-16s %s%s\n",
            $i + 1,
            $query['ms'],
            $group,
            substr($query['sql'], 0, 58),
            $query['ms'] >= 5 ? '   << 5ms فأكثر' : '',
        );
    }

    $total = array_sum(array_column($page['queries'], 'ms'));

    echo "╠── مجموع الصفحة ──\n";

    foreach (PerfProbe::groups() as $group) {
        if ($split[$group]['n'] > 0) {
            printf(
                "║   %-16s %2d استعلامًا  %6.2f ms  الأبطأ %5.2f ms\n",
                $group,
                $split[$group]['n'],
                $split[$group]['ms'],
                $split[$group]['max'],
            );
        }
    }

    printf("║   %-16s %2d استعلامًا  %6.2f ms\n", 'الكلّ', count($page['queries']), $total);
    printf("║   زمن الطلب كاملًا (PHP+DB): %.2f ms\n", $page['wall']);
    echo "╚═══════════════════════════════════════════════════════════\n";
}

sort($everything);
$n = count($everything);
$allMs = array_sum(array_column($grand, 'ms'));

echo "\n\n════════════ المجموع على كلّ الصفحات ════════════\n\n";
printf("  %-18s %8s %11s %10s %10s\n", 'الصنف', 'العدد', 'المجموع', 'المتوسّط', 'الأبطأ');

foreach (PerfProbe::groups() as $group) {
    printf(
        "  %-18s %8d %8.2f ms %7.2f ms %7.2f ms\n",
        $group,
        $grand[$group]['n'],
        $grand[$group]['ms'],
        $grand[$group]['n'] > 0 ? $grand[$group]['ms'] / $grand[$group]['n'] : 0,
        $grand[$group]['max'],
    );
}

printf("  %-18s %8d %8.2f ms\n\n", 'الكلّ', $n, $allMs);
printf("  الوسيط (p50)      : %.2f ms\n", $everything[(int) ($n * 0.5)]);
printf("  p95               : %.2f ms\n", $everything[(int) ($n * 0.95)]);
printf("  استعلامات >= 5ms  : %d من %d\n", count(array_filter($everything, fn ($ms) => $ms >= 5)), $n);

$infra = $grand['جلسة الدخول']['ms'] + $grand['فحص الحماية']['ms'];

printf(
    "\n  الجلسة + الحماية  : %d استعلامًا، %.2f ms  (%.0f%% من زمن القاعدة)\n",
    $grand['جلسة الدخول']['n'] + $grand['فحص الحماية']['n'],
    $infra,
    100 * $infra / $allMs,
);
printf(
    "  بيانات المسابقة   : %d استعلامًا، %.2f ms  (%.0f%%)\n",
    $grand['بيانات المسابقة']['n'],
    $grand['بيانات المسابقة']['ms'],
    100 * $grand['بيانات المسابقة']['ms'] / $allMs,
);

// ── Put the contestant back ─────────────────────────────────────────────────
DB::table('competition_users')->where('id', $participation->id)->update([
    'exam_status' => CompetitionUser::EXAM_NOT_STARTED,
    'started_at' => null,
    'current_question_started_at' => null,
    'completed_at' => null,
    'current_question' => 0,
    'answers' => str_repeat('-', (int) CompetitionSettings::current()->questionCount()),
    'correct_answers' => 0,
    'answered_questions' => 0,
]);

fwrite(STDERR, "\nأُعيد المتسابق {$participation->id} إلى not_started\n");
