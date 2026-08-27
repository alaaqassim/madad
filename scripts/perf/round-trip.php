<?php

/**
 * Where a query's time actually goes.
 *
 * The other two scripts say a query costs about a millisecond. This one says
 * why, which is the part that decides what is worth optimising:
 *
 *   1. How much of it is the round trip rather than the work. A SELECT that
 *      reads nothing still costs something; that something is the floor, and
 *      no index will move it.
 *   2. What a row costs on top of that floor.
 *   3. What a commit costs. A write in its own transaction pays a disk sync;
 *      the same write inside a shared transaction does not. If the two differ
 *      wildly, writes are sync-bound and the fix is fewer commits, not faster
 *      queries.
 *
 * It writes: creates and drops a scratch table. Point it at a test database.
 *
 * Usage: php scripts/perf/round-trip.php madad_test
 */

use Illuminate\Support\Facades\DB;

require __DIR__.'/bootstrap.php';

/** Average milliseconds per iteration, after one warm-up. */
$time = function (callable $work, int $times = 200): float {
    $work();

    $started = microtime(true);

    for ($i = 0; $i < $times; $i++) {
        $work();
    }

    return (microtime(true) - $started) * 1000 / $times;
};

echo "\n── 1. الأرضيّة: كم يكلّف استعلام لا يقرأ شيئًا ──\n\n";

$pdo = DB::connection()->getPdo();
$prepared = $pdo->prepare('SELECT 1');

printf("  SELECT 1 عبر Laravel          : %.3f ms\n", $time(fn () => DB::select('SELECT 1')));
printf("  SELECT 1 عبر PDO مباشرة       : %.3f ms\n", $time(fn () => $pdo->query('SELECT 1')->fetchAll()));
printf("  SELECT 1 ببيان محضّر معاد      : %.3f ms\n", $time(function () use ($prepared) {
    $prepared->execute();
    $prepared->fetchAll();
}));

echo "\n  الفرق بين السطرين الأخيرين هو رحلة PREPARE الزائدة في كلّ استعلام.\n";

// A scratch table of its own, so the script measures the same thing on an
// empty test database and on a full one.
DB::statement('DROP TABLE IF EXISTS zz_perf_probe');
DB::statement('CREATE TABLE zz_perf_probe (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(64)) ENGINE=InnoDB');

DB::transaction(function (): void {
    for ($i = 0; $i < 200; $i++) {
        DB::insert('INSERT INTO zz_perf_probe (v) VALUES (?)', ['row']);
    }
});

echo "\n── 2. كلفة الصفّ فوق الأرضيّة ──\n\n";

foreach ([1, 10, 100] as $rows) {
    printf("  %4d صفًّا : %.3f ms\n", $rows, $time(
        fn () => DB::select("SELECT * FROM zz_perf_probe LIMIT {$rows}"),
        $rows > 50 ? 100 : 200,
    ));
}

echo "\n  الفرق بين السطر الأوّل وهذه الأرضيّة هو ما يكلّفه الصفّ فعلًا.\n";

echo "\n── 3. كلفة التثبيت: هل الكتابة مقيّدة بمزامنة القرص؟ ──\n\n";

$rows = 50;

$alone = $time(function () use ($rows): void {
    for ($i = 0; $i < $rows; $i++) {
        DB::insert('INSERT INTO zz_perf_probe (v) VALUES (?)', ['x']);
    }
}, 3) / $rows;

$together = $time(function () use ($rows): void {
    DB::transaction(function () use ($rows): void {
        for ($i = 0; $i < $rows; $i++) {
            DB::insert('INSERT INTO zz_perf_probe (v) VALUES (?)', ['x']);
        }
    });
}, 3) / $rows;

$read = $time(fn () => DB::selectOne('SELECT * FROM zz_perf_probe WHERE id = 1'));

DB::statement('DROP TABLE zz_perf_probe');

printf("  كتابة في معاملة مستقلّة  : %.2f ms للصفّ\n", $alone);
printf("  كتابة داخل معاملة واحدة : %.2f ms للصفّ\n", $together);
printf("  قراءة بالمفتاح الأساسيّ   : %.2f ms للصفّ\n", $read);

if ($together > 0) {
    printf("\n  النسبة: %.0f×\n", $alone / $together);
}

echo "\n  إن كانت النسبة كبيرة فالكتابة مقيّدة بمزامنة القرص لا بالاستعلام،\n";
echo "  والعلاج تقليل عدد التثبيتات لا تسريع الاستعلامات.\n\n";
