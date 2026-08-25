<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Madad Phase 1 — realistic development fixtures.
 *
 * ─── WHAT THIS IS NOT ────────────────────────────────────────────────────────
 * This seeder is NOT the exam engine, the randomiser, the provisioning service
 * or the scoring service. It writes plausible END STATES directly so that the
 * UI and the backend have something real to work against. None of the logic
 * here may be promoted into production code — the real implementations must be
 * written against the requirements, not lifted from this fixture.
 *
 * ─── DETERMINISM ─────────────────────────────────────────────────────────────
 * Every random draw is seeded from a fixed constant plus the contestant index,
 * so a run against a clean database always produces byte-identical data. That
 * makes bug reports reproducible ("contestant 0417 shows the wrong order").
 *
 * ─── RUNNING IT ──────────────────────────────────────────────────────────────
 *     php artisan db:seed --class=MadadPhase1Seeder
 *
 * Deliberately NOT registered in DatabaseSeeder, so a bare `db:seed` cannot
 * pull 76k rows into a database by accident.
 */
class MadadPhase1Seeder extends Seeder
{
    /** Fixed so reruns against a clean database reproduce the dataset exactly. */
    private const RANDOM_SEED = 20260825;

    private const CONTESTANTS = 1000;

    private const QUESTIONS = 75;

    private const SECONDS_PER_QUESTION = 40;

    /** DEVELOPMENT ONLY. Shared by every fixture contestant for convenience. */
    private const DEV_PASSWORD = 'Madad@123456';

    private const COMPETITION_NAME = 'Madad Phase 1';

    /** Contestants 1..N. Boundaries are cumulative and must stay consistent. */
    private const ACCOUNT_CREATED_THROUGH = 900;   // 901..970 pending, 971..1000 failed

    private const ACCOUNT_PENDING_THROUGH = 970;

    private const EMAIL_SENT_THROUGH = 850;        // 851..900 failed, 901..1000 pending

    private const EXAM_COMPLETED_THROUGH = 150;    // 151..250 in progress, rest not started

    private const EXAM_IN_PROGRESS_THROUGH = 250;

    private const INSERT_CHUNK = 1000;

    /** Synthetic gateway failures. Never contains a credential. */
    private const EMAIL_ERRORS = [
        'SMTP 550 5.1.1 recipient address rejected: user unknown',
        'SMTP 421 4.7.0 temporary deferral: too many connections from sender',
        'gateway timeout after 30s while awaiting DATA acknowledgement',
        'SMTP 552 5.2.2 mailbox full',
    ];

    public function run(): void
    {
        $this->guardEnvironment();
        $this->guardDatabase();
        $this->guardAlreadySeeded();

        $started = microtime(true);

        $competitionId = $this->seedCompetition();
        $questionIds = $this->seedQuestions($competitionId);
        $plan = $this->buildContestantPlan();
        $userIdByIndex = $this->seedUsers($plan);
        $competitionUserIdByIndex = $this->seedCompetitionUsers($competitionId, $plan, $userIdByIndex);
        $this->seedAssignments($plan, $questionIds, $competitionUserIdByIndex);

        $this->command?->info(sprintf(
            'Madad fixtures seeded in %.1fs.',
            microtime(true) - $started
        ));
    }

    // ─────────────────────────────────────────────────────────── guards ─────

    private function guardEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'MadadPhase1Seeder refuses to run outside local/testing. Current environment: '
                .app()->environment()
            );
        }
    }

    /**
     * Second, independent guard. The environment name is a claim; the database
     * name is a fact. Anything not called madad_* is somebody else's schema.
     */
    private function guardDatabase(): void
    {
        $database = DB::connection()->getDatabaseName();

        if (! str_starts_with((string) $database, 'madad_')) {
            throw new RuntimeException(
                "MadadPhase1Seeder refuses to write to '{$database}'. Expected a madad_* database."
            );
        }

        $this->command?->info("Target database: {$database}");
    }

    /**
     * Refuse rather than replace. Deleting fixture rows would mean issuing
     * DELETEs against a database this seeder did not create, so the safe move
     * is to stop and let a human clear it deliberately.
     */
    private function guardAlreadySeeded(): void
    {
        $counts = [
            'competitions' => DB::table('competitions')->count(),
            'competition_questions' => DB::table('competition_questions')->count(),
            'competition_users' => DB::table('competition_users')->count(),
            'competition_user_questions' => DB::table('competition_user_questions')->count(),
        ];

        $occupied = array_filter($counts);

        if ($occupied !== []) {
            $detail = implode(', ', array_map(
                fn ($table, $n) => "{$table}={$n}",
                array_keys($occupied),
                $occupied
            ));

            throw new RuntimeException(
                "MadadPhase1Seeder expects empty competition tables but found: {$detail}. "
                .'Clear them deliberately before reseeding; this seeder will not delete data it did not create.'
            );
        }
    }

    // ──────────────────────────────────────────────────── competition ──────

    private function seedCompetition(): int
    {
        $now = $this->now();

        return (int) DB::table('competitions')->insertGetId([
            'name' => self::COMPETITION_NAME,
            // Deliberately 'ready', not 'open': the portal stays shut until a
            // human opens it, so testing controls the gate explicitly.
            'status' => 'ready',
            'show_result' => false,
            'question_count' => self::QUESTIONS,
            'seconds_per_question' => self::SECONDS_PER_QUESTION,
            'starts_at' => $this->examDay()->format('Y-m-d H:i:s'),
            'ends_at' => $this->examDay()->addHours(6)->format('Y-m-d H:i:s'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return list<int> question ids ordered by question_number */
    private function seedQuestions(int $competitionId): array
    {
        $now = $this->now();
        $bank = require database_path('seeders/data/madad_phase1_questions.php');

        $rows = [];
        foreach ($bank as [$number, $text, $a, $b, $c, $d, $correct]) {
            $rows[] = [
                'competition_id' => $competitionId,
                'question_number' => $number,
                'question_text' => $text,
                'option_a' => $a,
                'option_b' => $b,
                'option_c' => $c,
                'option_d' => $d,
                'correct_option' => $correct,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('competition_questions')->insert($rows);

        return DB::table('competition_questions')
            ->where('competition_id', $competitionId)
            ->orderBy('question_number')
            ->pluck('id')
            ->all();
    }

    // ─────────────────────────────────────────────────────── contestants ────

    /**
     * One plan entry per contestant, decided up front so every later stage
     * agrees about who has an account, who was emailed and who sat the exam.
     *
     * @return list<array<string, mixed>>
     */
    private function buildContestantPlan(): array
    {
        $plan = [];

        for ($i = 1; $i <= self::CONTESTANTS; $i++) {
            $accountStatus = match (true) {
                $i <= self::ACCOUNT_CREATED_THROUGH => 'created',
                $i <= self::ACCOUNT_PENDING_THROUGH => 'pending',
                default => 'failed',
            };

            // Delivery only means anything once an account exists.
            $emailStatus = match (true) {
                $accountStatus !== 'created' => 'pending',
                $i <= self::EMAIL_SENT_THROUGH => 'sent',
                default => 'failed',
            };

            // Only a contestant who actually received credentials can sit the exam.
            $examStatus = match (true) {
                $emailStatus !== 'sent' => 'not_started',
                $i <= self::EXAM_COMPLETED_THROUGH => 'completed',
                $i <= self::EXAM_IN_PROGRESS_THROUGH => 'in_progress',
                default => 'not_started',
            };

            $plan[] = [
                'index' => $i,
                'name' => 'متسابق '.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'email' => sprintf('contestant%04d@madad.test', $i),
                'account_status' => $accountStatus,
                'email_status' => $emailStatus,
                'exam_status' => $examStatus,
            ];
        }

        return $plan;
    }

    /**
     * Users exist only for contestants whose account was actually created.
     * Creating a user for a 'pending' or 'failed' row would contradict the very
     * state the fixture is meant to represent.
     *
     * @param  list<array<string, mixed>>  $plan
     * @return array<int, int> contestant index => users.id
     */
    private function seedUsers(array $plan): array
    {
        $now = $this->now();

        // Hashed once and reused. Every fixture contestant shares one dev
        // password anyway, and 900 separate bcrypt rounds would cost ~90s.
        $hash = Hash::make(self::DEV_PASSWORD);

        $buffer = [];
        foreach ($plan as $entry) {
            if ($entry['account_status'] !== 'created') {
                continue;
            }

            $buffer[] = [
                'name' => $entry['name'],
                'email' => $entry['email'],
                'email_verified_at' => null,
                'password' => $hash,
                'remember_token' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($buffer) >= self::INSERT_CHUNK) {
                DB::table('users')->insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table('users')->insert($buffer);
        }

        $idByEmail = DB::table('users')
            ->where('email', 'like', 'contestant%@madad.test')
            ->pluck('id', 'email')
            ->all();

        $map = [];
        foreach ($plan as $entry) {
            if (isset($idByEmail[$entry['email']])) {
                $map[$entry['index']] = (int) $idByEmail[$entry['email']];
            }
        }

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     * @param  array<int, int>  $userIdByIndex
     * @return array<int, int> contestant index => competition_users.id
     */
    private function seedCompetitionUsers(int $competitionId, array $plan, array $userIdByIndex): array
    {
        $now = $this->now();
        $generatedAt = $this->examDay()->subDays(3)->setTime(10, 0);

        $buffer = [];
        foreach ($plan as $entry) {
            $i = $entry['index'];
            $this->seedRandom($i, 'provisioning');

            $created = $entry['account_status'] === 'created';
            $sent = $entry['email_status'] === 'sent';
            $failed = $entry['email_status'] === 'failed';

            // A password is only generated once the account exists.
            $credentialsGeneratedAt = $created
                ? $generatedAt->copy()->addSeconds($i)->format('Y-m-d H:i:s')
                : null;

            $buffer[] = [
                'competition_id' => $competitionId,
                'user_id' => $userIdByIndex[$i] ?? null,
                'contestant_name' => $entry['name'],
                'contestant_email' => $entry['email'],
                'source_reference' => sprintf('MADAD-2026-%04d', $i),
                'account_status' => $entry['account_status'],
                'credentials_generated_at' => $credentialsGeneratedAt,
                'email_status' => $entry['email_status'],
                'email_attempts' => match (true) {
                    $sent => 1,
                    $failed => mt_rand(1, 3),
                    default => 0,
                },
                'credentials_sent_at' => $sent
                    ? $generatedAt->copy()->addSeconds($i + 5)->format('Y-m-d H:i:s')
                    : null,
                'email_last_error' => $failed
                    ? self::EMAIL_ERRORS[mt_rand(0, count(self::EMAIL_ERRORS) - 1)]
                    : null,
                'exam_status' => $entry['exam_status'],
                // started_at / completed_at / results are filled by the
                // assignment pass, which is where the per-question truth lives.
                'started_at' => null,
                'completed_at' => null,
                'correct_answers' => 0,
                'answered_questions' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($buffer) >= self::INSERT_CHUNK) {
                DB::table('competition_users')->insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table('competition_users')->insert($buffer);
        }

        $idByEmail = DB::table('competition_users')
            ->where('competition_id', $competitionId)
            ->pluck('id', 'contestant_email')
            ->all();

        $map = [];
        foreach ($plan as $entry) {
            $map[$entry['index']] = (int) $idByEmail[$entry['email']];
        }

        return $map;
    }

    // ──────────────────────────────────────────────────── assignments ───────

    /**
     * Every contestant gets all 75 questions exactly once, in their own
     * persisted order. The per-question states are then written to match the
     * contestant's exam_status, and the aggregates on competition_users are
     * derived from the rows actually written — never guessed.
     *
     * @param  list<array<string, mixed>>  $plan
     * @param  list<int>  $questionIds
     * @param  array<int, int>  $competitionUserIdByIndex
     */
    private function seedAssignments(array $plan, array $questionIds, array $competitionUserIdByIndex): void
    {
        $now = $this->now();
        $correctByQuestionId = DB::table('competition_questions')
            ->pluck('correct_option', 'id')
            ->all();

        $buffer = [];
        $aggregateUpdates = [];

        foreach ($plan as $entry) {
            $i = $entry['index'];
            $competitionUserId = $competitionUserIdByIndex[$i];

            $this->seedRandom($i, 'order');
            $order = $this->deterministicShuffle($questionIds);

            $this->seedRandom($i, 'exam');
            $state = $this->buildExamState($entry['exam_status'], $order, $correctByQuestionId, $i);

            foreach ($order as $position => $questionId) {
                $sequence = $position + 1;
                $q = $state['questions'][$sequence];

                $buffer[] = [
                    'competition_user_id' => $competitionUserId,
                    'competition_question_id' => $questionId,
                    'sequence' => $sequence,
                    'opened_at' => $q['opened_at'],
                    'expires_at' => $q['expires_at'],
                    'selected_option' => $q['selected_option'],
                    'answered_at' => $q['answered_at'],
                    'is_correct' => $q['is_correct'],
                    'timed_out' => $q['timed_out'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($buffer) >= self::INSERT_CHUNK) {
                    DB::table('competition_user_questions')->insert($buffer);
                    $buffer = [];
                }
            }

            $aggregateUpdates[$competitionUserId] = [
                'started_at' => $state['started_at'],
                'completed_at' => $state['completed_at'],
                'correct_answers' => $state['correct_answers'],
                'answered_questions' => $state['answered_questions'],
            ];
        }

        if ($buffer !== []) {
            DB::table('competition_user_questions')->insert($buffer);
        }

        $this->applyAggregates($aggregateUpdates);
    }

    /**
     * Builds the per-question end state for one contestant.
     *
     * @param  list<int>  $order
     * @param  array<int, string>  $correctByQuestionId
     * @return array<string, mixed>
     */
    private function buildExamState(string $examStatus, array $order, array $correctByQuestionId, int $index): array
    {
        $questions = [];
        $blank = [
            'opened_at' => null, 'expires_at' => null, 'selected_option' => null,
            'answered_at' => null, 'is_correct' => false, 'timed_out' => false,
        ];

        if ($examStatus === 'not_started') {
            for ($s = 1; $s <= self::QUESTIONS; $s++) {
                $questions[$s] = $blank;
            }

            return [
                'questions' => $questions,
                'started_at' => null,
                'completed_at' => null,
                'correct_answers' => 0,
                'answered_questions' => 0,
            ];
        }

        // How many questions are already terminal, and is one still open?
        if ($examStatus === 'completed') {
            $terminal = self::QUESTIONS;
            $timeouts = $this->weightedTimeouts();
            $activeSequence = null;
        } else {
            $terminal = 5 + ($index % 60);          // 5..64 finished
            $timeouts = min($this->weightedTimeouts(), max(0, $terminal - 1));
            $activeSequence = $terminal + 1;        // exactly one open question
        }

        // Which of the terminal sequences timed out.
        $timedOutSequences = $this->pickDistinct($terminal, $timeouts, 2);

        $answeredCount = $terminal - count($timedOutSequences);
        // Accuracy is a RATIO of what was attempted, not an absolute count.
        // An absolute target clamped against a partially-finished paper would
        // hand most in-progress contestants a perfect record.
        $targetCorrect = (int) round($this->bellCurveAccuracy() * $answeredCount);
        $targetCorrect = max(0, min($targetCorrect, $answeredCount));
        $correctSequences = $this->pickDistinct($terminal, $targetCorrect, 1, $timedOutSequences);

        $cursor = $this->examDay()->copy()->addSeconds(($index % 900) * 4);
        $startedAt = null;
        $lastTerminalAt = null;
        $correctAnswers = 0;
        $answeredQuestions = 0;

        for ($s = 1; $s <= self::QUESTIONS; $s++) {
            if ($s > $terminal && $s !== $activeSequence) {
                $questions[$s] = $blank;

                continue;
            }

            $openedAt = $cursor->copy();
            $expiresAt = $openedAt->copy()->addSeconds(self::SECONDS_PER_QUESTION);
            $startedAt ??= $openedAt->copy();

            if ($s === $activeSequence) {
                // The live question: opened, deadline set, nothing answered yet.
                $questions[$s] = [
                    'opened_at' => $this->ms($openedAt),
                    'expires_at' => $this->ms($expiresAt),
                    'selected_option' => null,
                    'answered_at' => null,
                    'is_correct' => false,
                    'timed_out' => false,
                ];

                continue;
            }

            if (in_array($s, $timedOutSequences, true)) {
                $questions[$s] = [
                    'opened_at' => $this->ms($openedAt),
                    'expires_at' => $this->ms($expiresAt),
                    'selected_option' => null,
                    'answered_at' => null,
                    'is_correct' => false,
                    'timed_out' => true,
                ];
                $lastTerminalAt = $expiresAt->copy();
                $cursor = $expiresAt->copy()->addSeconds(mt_rand(1, 3));

                continue;
            }

            // Answered strictly inside the 40-second window.
            $answeredAt = $openedAt->copy()->addMilliseconds(mt_rand(1500, (self::SECONDS_PER_QUESTION * 1000) - 500));
            $questionId = $order[$s - 1];
            $correctOption = $correctByQuestionId[$questionId];
            $isCorrect = in_array($s, $correctSequences, true);

            $questions[$s] = [
                'opened_at' => $this->ms($openedAt),
                'expires_at' => $this->ms($expiresAt),
                'selected_option' => $isCorrect
                    ? $correctOption
                    : $this->wrongOption($correctOption),
                'answered_at' => $this->ms($answeredAt),
                'is_correct' => $isCorrect,
                'timed_out' => false,
            ];

            $answeredQuestions++;
            $correctAnswers += $isCorrect ? 1 : 0;
            $lastTerminalAt = $answeredAt->copy();
            $cursor = $answeredAt->copy()->addSeconds(mt_rand(1, 3));
        }

        return [
            'questions' => $questions,
            'started_at' => $startedAt ? $this->ms($startedAt) : null,
            'completed_at' => $examStatus === 'completed' && $lastTerminalAt ? $this->ms($lastTerminalAt) : null,
            'correct_answers' => $correctAnswers,
            'answered_questions' => $answeredQuestions,
        ];
    }

    /** @param array<int, array<string, mixed>> $updates */
    private function applyAggregates(array $updates): void
    {
        foreach (array_chunk($updates, 200, true) as $chunk) {
            DB::transaction(function () use ($chunk) {
                foreach ($chunk as $competitionUserId => $values) {
                    DB::table('competition_users')->where('id', $competitionUserId)->update($values);
                }
            });
        }
    }

    // ───────────────────────────────────────────────────────── helpers ──────

    /** Reseeds the generator so each contestant's data is independent of iteration order. */
    private function seedRandom(int $index, string $channel): void
    {
        mt_srand(self::RANDOM_SEED + ($index * 31) + crc32($channel));
    }

    /**
     * Fisher-Yates driven by mt_rand, rather than shuffle(), so the order does
     * not depend on the engine PHP's shuffle() happens to use.
     *
     * @param  list<int>  $items
     * @return list<int>
     */
    private function deterministicShuffle(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }

    /** Most contestants time out on nothing; a few lose a handful of questions. */
    private function weightedTimeouts(): int
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 55 => 0,
            $roll <= 80 => mt_rand(1, 2),
            $roll <= 95 => mt_rand(3, 6),
            default => mt_rand(7, 12),
        };
    }

    /**
     * Per-contestant accuracy, roughly normal around 0.60 with sd 0.16.
     *
     * Applied to a full 75-question paper that lands near 45/75 with a spread
     * of about 12 marks — a believable middle, real tails, natural ties and a
     * genuine cluster around a Top-100 cutoff — while staying just as sensible
     * for a contestant who is only twenty questions in.
     */
    private function bellCurveAccuracy(): float
    {
        $u1 = max(1.0e-9, mt_rand(1, 1_000_000) / 1_000_000);
        $u2 = mt_rand(1, 1_000_000) / 1_000_000;
        $z = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);

        return max(0.04, min(1.0, 0.60 + ($z * 0.16)));
    }

    /**
     * @param  list<int>  $exclude
     * @return list<int>
     */
    private function pickDistinct(int $upperBound, int $howMany, int $channel, array $exclude = []): array
    {
        $pool = array_values(array_diff(range(1, $upperBound), $exclude));
        $howMany = max(0, min($howMany, count($pool)));

        for ($i = count($pool) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
        }

        return array_slice($pool, 0, $howMany);
    }

    private function wrongOption(string $correct): string
    {
        $options = array_values(array_diff(['A', 'B', 'C', 'D'], [$correct]));

        return $options[mt_rand(0, count($options) - 1)];
    }

    private function examDay(): Carbon
    {
        return Carbon::create(2026, 9, 5, 9, 0, 0);
    }

    private function now(): string
    {
        return Carbon::create(2026, 8, 25, 12, 0, 0)->format('Y-m-d H:i:s');
    }

    /** datetime(3) — millisecond precision is part of the approved contract. */
    private function ms(Carbon $moment): string
    {
        return $moment->format('Y-m-d H:i:s.v');
    }
}
