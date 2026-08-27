<?php

/**
 * Shared setup for the performance scripts.
 *
 * These drive the real application - real routes, real middleware, real
 * session - and journey.php goes as far as sitting a whole exam. That is fine
 * against a development database and unacceptable against a live one, so the
 * target database is named on the command line and verified before anything
 * runs. Nothing here guesses.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$script = basename($argv[0]);
$expected = $argv[1] ?? null;

if ($expected === null) {
    fwrite(STDERR, "الاستعمال: php scripts/perf/{$script} <اسم قاعدة البيانات>\n");
    fwrite(STDERR, "مثال     : php scripts/perf/{$script} madad_dev\n");
    exit(1);
}

if ($app->environment('production')) {
    fwrite(STDERR, "APP_ENV=production — هذه السكربتات لا تعمل على الإنتاج\n");
    exit(1);
}

// The named database is used, not whatever .env happens to point at. Naming it
// is the consent: nothing runs against a database the operator did not type.
$connection = config('database.default');

config(["database.connections.{$connection}.database" => $expected]);
DB::purge($connection);

try {
    $connected = DB::selectOne('SELECT DATABASE() AS name')->name;
} catch (Throwable $e) {
    fwrite(STDERR, "تعذّر الاتّصال بقاعدة «{$expected}»: {$e->getMessage()}\n");
    exit(1);
}

if ($connected !== $expected) {
    fwrite(STDERR, "القاعدة المتّصلة «{$connected}» لا تطابق المطلوبة «{$expected}» — توقّف\n");
    exit(1);
}

fwrite(STDERR, sprintf(
    "قاعدة البيانات: %s على %s\n",
    $connected,
    config("database.connections.{$connection}.host"),
));

/**
 * Sends requests through the whole stack and records what each one cost.
 *
 * The cookie jar is what makes the run realistic: the login is a real login,
 * the session persists, and the contestant carries on from where they were,
 * exactly as a browser would.
 */
final class PerfProbe
{
    /** @var list<array{sql: string, ms: float}> */
    private array $captured = [];

    private bool $recording = false;

    /** @var array<string, string> */
    private array $jar = [];

    public function __construct(private readonly Kernel $kernel)
    {
        // Laravel's own timing, not an estimate of ours.
        DB::listen(function (QueryExecuted $query): void {
            if ($this->recording) {
                $this->captured[] = [
                    'sql' => preg_replace('/\s+/', ' ', $query->sql),
                    'ms' => $query->time,
                ];
            }
        });
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: int, queries: list<array{sql: string, ms: float}>, wall: float, body: array<mixed>}
     */
    public function request(string $method, string $uri, array $body = []): array
    {
        $this->captured = [];
        $this->recording = true;

        $headers = ['HTTP_ACCEPT' => 'application/json'];

        if (isset($this->jar['XSRF-TOKEN'])) {
            $headers['HTTP_X_XSRF_TOKEN'] = $this->jar['XSRF-TOKEN'];
        }

        $wall = microtime(true);
        $response = $this->kernel->handle(Request::create($uri, $method, $body, $this->jar, [], $headers));
        $wall = (microtime(true) - $wall) * 1000;

        $this->recording = false;

        foreach ($response->headers->getCookies() as $cookie) {
            $this->jar[$cookie->getName()] = $cookie->getValue();
        }

        return [
            'status' => $response->getStatusCode(),
            'queries' => $this->captured,
            'wall' => $wall,
            'body' => json_decode($response->getContent(), true) ?? [],
        ];
    }

    /**
     * Which part of the system a query serves.
     *
     * The split is the whole point of these scripts: it separates the work the
     * exam actually needs from the work the framework needs to keep a login
     * alive and to count requests.
     */
    public static function group(string $sql): string
    {
        return match (true) {
            str_contains($sql, '`sessions`') => 'جلسة الدخول',
            str_contains($sql, '`cache`') => 'تحديد معدّل الطلبات',
            default => 'بيانات المسابقة',
        };
    }

    /** @return list<string> The groups, in report order. */
    public static function groups(): array
    {
        return ['بيانات المسابقة', 'جلسة الدخول', 'تحديد معدّل الطلبات'];
    }
}

return [$app, new PerfProbe($kernel)];
