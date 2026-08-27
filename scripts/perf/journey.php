<?php

/**
 * One contestant, start to finish: what the whole exam costs.
 *
 * Opens the page, logs in, begins, answers every question, reads the result,
 * logs out - through the real stack - and reports where the database time
 * went. Run it on the server after deploying and compare with the local
 * numbers; that comparison is the only honest way to know what the hardware
 * was worth.
 *
 * It writes: a contestant really sits the exam. The row is put back to
 * not_started at the end, but do not point this at anything you care about.
 *
 * Usage: php scripts/perf/journey.php madad_dev
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

$questionCount = (int) CompetitionSettings::current()->questionCount();
$steps = [];

/** Runs one step and keeps its cost under a readable label. */
$step = function (string $label, string $method, string $uri, array $body = []) use ($probe, &$steps): array {
    $result = $probe->request($method, $uri, $body);

    $split = array_fill_keys(PerfProbe::groups(), ['n' => 0, 'ms' => 0.0]);

    foreach ($result['queries'] as $query) {
        $group = PerfProbe::group($query['sql']);
        $split[$group]['n']++;
        $split[$group]['ms'] += $query['ms'];
    }

    $steps[] = [
        'label' => $label,
        'status' => $result['status'],
        'n' => count($result['queries']),
        'ms' => array_sum(array_column($result['queries'], 'ms')),
        'wall' => $result['wall'],
        'split' => $split,
    ];

    return $result['body'];
};

$step('1. فتح الصفحة  GET /competition/status', 'GET', '/api/competition/status');

$step('2. تسجيل الدخول  POST /login', 'POST', '/api/login', [
    'email' => $participation->contestant_email,
    'password' => $password,
]);

$step('3. بدء الامتحان  POST /exam/start', 'POST', '/api/exam/start');
$payload = $step('4. أوّل سؤال  GET /exam/current', 'GET', '/api/exam/current');

$answered = 0;

for ($i = 0; $i < $questionCount; $i++) {
    // The answer response carries the next question, so a contestant never
    // needs a follow-up request. Both shapes are accepted because the first
    // question arrives from /current and the rest from /answer.
    $questionId = $payload['next_question']['question_id']
        ?? $payload['question']['question_id']
        ?? null;

    if ($questionId === null) {
        break;
    }

    $payload = $step(
        '5. إجابة رقم '.($i + 1).'  POST /exam/answer',
        'POST',
        '/api/exam/answer',
        ['question_id' => $questionId, 'selected_option' => ['A', 'B', 'C', 'D'][$i % 4]],
    );

    $answered++;
}

$step('6. النتيجة  GET /exam/result', 'GET', '/api/exam/result');
$step('7. الخروج  POST /logout', 'POST', '/api/logout');

// ── The report ───────────────────────────────────────────────────────────────

$grand = array_fill_keys(PerfProbe::groups(), ['n' => 0, 'ms' => 0.0]);
$wallAll = 0.0;
$answers = [];

echo "\nكلّ الأرقام زمن بالمللي ثانية.\n\n";
printf("%-44s %5s %8s %8s %8s %9s\n", 'الخطوة', 'HTTP', 'المسابقة', 'الجلسة', 'الحماية', 'المجموع');
echo str_repeat('-', 92), "\n";

foreach ($steps as $row) {
    foreach (PerfProbe::groups() as $group) {
        $grand[$group]['n'] += $row['split'][$group]['n'];
        $grand[$group]['ms'] += $row['split'][$group]['ms'];
    }

    $wallAll += $row['wall'];

    $isAnswer = str_starts_with($row['label'], '5. إجابة');

    if ($isAnswer) {
        $answers[] = $row;

        // Only the first and the last are printed; 75 identical rows would
        // bury the report.
        if (count($answers) > 1 && count($answers) < $answered) {
            continue;
        }
    }

    printf(
        "%-44s %5d %8.1f %8.1f %8.1f %9.1f\n",
        $row['label'],
        $row['status'],
        $row['split']['بيانات المسابقة']['ms'],
        $row['split']['جلسة الدخول']['ms'],
        $row['split']['فحص الحماية']['ms'],
        $row['ms'],
    );

    if ($isAnswer && count($answers) === 1 && $answered > 2) {
        printf("%-44s\n", '   … ('.($answered - 2).' إجابة مماثلة) …');
    }
}

$answerQueries = array_sum(array_column($answers, 'n'));
$answerMs = array_sum(array_column($answers, 'ms'));
$answerWall = array_sum(array_column($answers, 'wall'));
$allQueries = array_sum(array_column($grand, 'n'));
$allMs = array_sum(array_column($grand, 'ms'));

echo "\n══════════ الرحلة كاملة ══════════\n\n";
printf("  عدد الطلبات               : %d   (%d منها إجابات)\n", count($steps), $answered);
printf("  عدد الاستعلامات           : %d\n", $allQueries);
printf("  زمن قاعدة البيانات        : %.0f ms  (%.2f ثانية)\n", $allMs, $allMs / 1000);
printf("  زمن الطلبات كاملًا PHP+DB  : %.0f ms  (%.2f ثانية)\n\n", $wallAll, $wallAll / 1000);

printf("  %-18s %8s %12s %9s\n", 'الصنف', 'العدد', 'الزمن', 'النسبة');

foreach (PerfProbe::groups() as $group) {
    printf(
        "  %-18s %8d %9.0f ms %8.0f%%\n",
        $group,
        $grand[$group]['n'],
        $grand[$group]['ms'],
        $allMs > 0 ? 100 * $grand[$group]['ms'] / $allMs : 0,
    );
}

if ($answered > 0) {
    echo "\n  ── صفحة الإجابة وحدها ──\n";
    printf(
        "  %d إجابة = %d استعلامًا = %.0f ms قاعدة = %.0f ms كامل\n",
        $answered,
        $answerQueries,
        $answerMs,
        $answerWall,
    );
    printf(
        "  للإجابة الواحدة: %.1f استعلامًا، %.1f ms قاعدة، %.1f ms كامل\n",
        $answerQueries / $answered,
        $answerMs / $answered,
        $answerWall / $answered,
    );
    printf(
        "  نصيبها من الرحلة: %.0f%% من الاستعلامات، %.0f%% من زمن القاعدة\n",
        100 * $answerQueries / $allQueries,
        100 * $answerMs / $allMs,
    );
}

// ── Put the contestant back ─────────────────────────────────────────────────
DB::table('competition_users')->where('id', $participation->id)->update([
    'exam_status' => CompetitionUser::EXAM_NOT_STARTED,
    'started_at' => null,
    'current_question_started_at' => null,
    'completed_at' => null,
    'current_question' => 0,
    'answers' => str_repeat('-', $questionCount),
    'correct_answers' => 0,
    'answered_questions' => 0,
]);

fwrite(STDERR, "\nأُعيد المتسابق {$participation->id} إلى not_started\n");
