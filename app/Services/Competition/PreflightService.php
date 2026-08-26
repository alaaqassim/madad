<?php

namespace App\Services\Competition;

use App\Models\CompetitionQuestion;
use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The competition-day readiness check.
 *
 * ─── STRICTLY READ-ONLY ─────────────────────────────────────────────────────
 * Every statement in this class is a SELECT. Nothing is inserted, updated,
 * deleted or migrated. It is safe to run against a live competition mid-exam,
 * which is the point: an operator must be able to ask "is this healthy?" at any
 * moment without changing the answer by asking.
 *
 * ─── WHAT IS A BLOCKER AND WHAT IS A WARNING ────────────────────────────────
 * FAIL is reserved for states in which the competition cannot correctly run:
 * missing configuration, a short question bank, contradictory exam rows,
 * aggregates that disagree with the rows they summarise. WARNING is for things
 * an operator should see and rule on — undelivered credentials, unprovisioned
 * accounts, a second competition in the database. No warning is silently
 * promoted to a blocker, because that would be inventing a business rule.
 */
class PreflightService
{
    /** Phase 1's stated question bank size. */
    public const EXPECTED_QUESTIONS = 75;

    /** Databases this application must never be pointed at. */
    private const FORBIDDEN_DATABASES = ['cms_moher', 'cms_moher_exam_engine'];

    public function run(?CompetitionSettings $settings = null): PreflightReport
    {
        $settings ??= CompetitionSettings::current();

        $checks = array_merge(
            $this->environmentChecks(),
            $this->competitionChecks($settings),
        );

        if ($settings !== null) {
            $checks = array_merge(
                $checks,
                $this->questionChecks($settings),
                $this->contestantChecks($settings),
                $this->examDataChecks($settings),
            );
        }

        return new PreflightReport(array_values($checks));
    }

    // ─────────────────────────────────────────────────────── environment ────

    /** @return list<PreflightCheck> */
    private function environmentChecks(): array
    {
        $configured = (string) config('database.connections.'.config('database.default').'.database');
        $resolved = (string) DB::connection()->getDatabaseName();
        $live = (string) DB::selectOne('SELECT DATABASE() AS d')->d;

        $checks = [];

        // All three must agree. A mismatch means the application is not talking
        // to the database its configuration describes, and nothing below this
        // line could be trusted.
        $checks[] = $configured === $resolved && $resolved === $live
            ? PreflightCheck::pass('Environment', 'database', "connected to `{$live}` (configuration, connection and SELECT DATABASE() agree)")
            : PreflightCheck::fail('Environment', 'database', "database identity disagrees - configured `{$configured}`, resolved `{$resolved}`, live `{$live}`");

        $checks[] = in_array(strtolower($live), self::FORBIDDEN_DATABASES, true)
            ? PreflightCheck::fail('Environment', 'database isolation', "`{$live}` belongs to another project and must never be used by Madad")
            : PreflightCheck::pass('Environment', 'database isolation', "`{$live}` is not one of the reserved foreign databases");

        $env = (string) config('app.env');
        $debug = (bool) config('app.debug');

        $checks[] = match (true) {
            $env === 'production' && $debug => PreflightCheck::fail('Environment', 'application', 'APP_ENV=production with APP_DEBUG=true - debug output would leak internals to contestants'),
            $env === 'production' => PreflightCheck::pass('Environment', 'application', 'APP_ENV=production, APP_DEBUG=false'),
            default => PreflightCheck::warning('Environment', 'application', "APP_ENV={$env} (APP_DEBUG=".($debug ? 'true' : 'false').') - not a production configuration'),
        };

        $checks[] = $this->pendingMigrationCheck();

        return $checks;
    }

    private function pendingMigrationCheck(): PreflightCheck
    {
        try {
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles([database_path('migrations')]);
            $ran = $migrator->getRepository()->getRan();
            $pending = array_keys(array_diff_key($files, array_flip($ran)));
        } catch (Throwable $e) {
            return PreflightCheck::fail('Environment', 'migrations', 'could not be read: '.$e->getMessage());
        }

        return $pending === []
            ? PreflightCheck::pass('Environment', 'migrations', count($files).' migrations, none pending')
            : PreflightCheck::fail('Environment', 'migrations', count($pending).' pending: '.implode(', ', $pending));
    }

    // ─────────────────────────────────────────────────────── competition ────

    /** @return list<PreflightCheck> */
    private function competitionChecks(?CompetitionSettings $settings): array
    {
        if ($settings === null) {
            return [PreflightCheck::fail('Competition', 'settings', 'no competition_settings row exists - nothing can run')];
        }

        $rows = CompetitionSettings::query()->count();

        $checks = [
            $rows === 1
                ? PreflightCheck::pass('Competition', 'settings', "exactly one settings row: \"{$settings->name}\"")
                : PreflightCheck::fail('Competition', 'settings', "{$rows} settings rows exist; there must be exactly one"),
            PreflightCheck::pass('Competition', 'status', $settings->status.' (portal open: '.($settings->isOpen() ? 'yes' : 'no').')'),
            PreflightCheck::pass('Competition', 'show_result', $settings->show_result ? 'true - contestants see their score' : 'false - contestants do not see their score'),
        ];

        // Read the raw attributes here, not the clamped accessors: the point of
        // these two checks is to catch a zero that the accessors would hide.
        $checks[] = (int) $settings->question_count > 0
            ? PreflightCheck::pass('Competition', 'question_count', (string) $settings->question_count)
            : PreflightCheck::fail('Competition', 'question_count', 'must be greater than zero');

        $checks[] = (int) $settings->seconds_per_question > 0
            ? PreflightCheck::pass('Competition', 'seconds_per_question', $settings->seconds_per_question.' seconds')
            : PreflightCheck::fail('Competition', 'seconds_per_question', 'must be greater than zero');

        $checks[] = (int) $settings->exam_duration_minutes > 0
            ? PreflightCheck::pass('Competition', 'exam duration', $settings->exam_duration_minutes.' minutes per contestant')
            : PreflightCheck::fail('Competition', 'exam duration', 'must be greater than zero');

        if ((int) $settings->question_count > 0 && (int) $settings->question_count !== self::EXPECTED_QUESTIONS) {
            $checks[] = PreflightCheck::warning(
                'Competition',
                'expected size',
                "question_count is {$settings->question_count}; Phase 1 was specified as ".self::EXPECTED_QUESTIONS,
            );
        }

        return array_merge($checks, $this->windowChecks($settings));
    }

    /**
     * The global availability window, and how it interacts with the personal
     * allowance.
     *
     * These are two different clocks and the interesting cases are where they
     * disagree: a window shorter than one contestant's allowance means nobody
     * who starts late gets a full paper, and a window that has already passed
     * means the portal is terminal no matter what `status` says.
     *
     * @return list<PreflightCheck>
     */
    private function windowChecks(CompetitionSettings $settings): array
    {
        $now = now();
        $checks = [];

        $window = match (true) {
            $settings->starts_at === null && $settings->ends_at === null => 'no window set - status alone governs access',
            $settings->starts_at === null => 'until '.$settings->ends_at->toDateTimeString(),
            $settings->ends_at === null => 'from '.$settings->starts_at->toDateTimeString(),
            default => $settings->starts_at->toDateTimeString().' to '.$settings->ends_at->toDateTimeString(),
        };

        $checks[] = $settings->starts_at !== null && $settings->ends_at !== null
            && $settings->ends_at->lessThanOrEqualTo($settings->starts_at)
                ? PreflightCheck::fail('Competition', 'availability window', "ends_at is not after starts_at ({$window}) - the portal could never open")
                : PreflightCheck::pass('Competition', 'availability window', $window);

        if ($settings->windowHasEnded($now)) {
            $checks[] = PreflightCheck::fail(
                'Competition',
                'window state',
                'the availability window has already passed - no contestant can start or resume',
            );
        } elseif (! $settings->windowHasOpened($now)) {
            $checks[] = PreflightCheck::warning(
                'Competition',
                'window state',
                'the availability window has not opened yet - contestants will be refused until '.$settings->starts_at->toDateTimeString(),
            );
        } else {
            $checks[] = PreflightCheck::pass('Competition', 'window state', 'the availability window is open now');
        }

        // A full paper needs question_count x seconds_per_question of wall
        // clock. If the personal allowance is shorter than that, the last
        // questions are unreachable for everyone, which an operator should be
        // told before the doctor discovers it mid-competition.
        $paperSeconds = $settings->questionCount() * $settings->secondsPerQuestion();
        $allowance = $settings->examDurationSeconds();

        $checks[] = $allowance >= $paperSeconds
            ? PreflightCheck::pass('Competition', 'allowance vs paper', "{$allowance}s allowance covers a {$paperSeconds}s paper")
            : PreflightCheck::warning('Competition', 'allowance vs paper', "the {$allowance}s allowance is shorter than the {$paperSeconds}s paper - the last questions are unreachable");

        if ($settings->ends_at !== null && ! $settings->windowHasEnded($now)) {
            $remaining = $settings->secondsAvailableFrom($now);

            $checks[] = $remaining >= $paperSeconds
                ? PreflightCheck::pass('Competition', 'time left in window', "{$remaining}s remain - enough for a full paper")
                : PreflightCheck::warning('Competition', 'time left in window', "{$remaining}s remain - a contestant starting now could not finish a {$paperSeconds}s paper");
        }

        return $checks;
    }

    // ───────────────────────────────────────────────────────── questions ────

    /** @return list<PreflightCheck> */
    private function questionChecks(CompetitionSettings $settings): array
    {
        $questions = fn () => DB::table('competition_questions');

        $total = $questions()->count();

        $checks = [
            $total >= $settings->questionCount()
                ? PreflightCheck::pass('Questions', 'bank size', "{$total} questions for a paper of {$settings->questionCount()}")
                : PreflightCheck::fail('Questions', 'bank size', "only {$total} questions for a paper of {$settings->questionCount()} - papers cannot be built"),
        ];

        if ($total >= $settings->questionCount() && $total < self::EXPECTED_QUESTIONS) {
            $checks[] = PreflightCheck::warning('Questions', 'expected bank size', "{$total} questions; Phase 1 was specified as at least ".self::EXPECTED_QUESTIONS);
        }

        $blankOptions = $questions()->where(function ($q): void {
            foreach (['option_a', 'option_b', 'option_c', 'option_d'] as $column) {
                $q->orWhereNull($column)->orWhere($column, '=', '');
            }
        })->count();

        $checks[] = PreflightCheck::forCount(
            'Questions', 'options A/B/C/D', $blankOptions,
            'questions have a missing or empty option',
            'every question has all four options',
        );

        $blankText = $questions()->where(function ($q): void {
            $q->whereNull('question_text')->orWhere('question_text', '=', '');
        })->count();

        $checks[] = PreflightCheck::forCount(
            'Questions', 'question text', $blankText,
            'questions have no text',
            'every question has text',
        );

        $badKey = $questions()->whereNotIn('correct_option', CompetitionQuestion::OPTIONS)->count();

        $checks[] = PreflightCheck::forCount(
            'Questions', 'correct option', $badKey,
            'questions have a correct_option outside A-D',
            'every correct_option is one of A-D',
        );

        $duplicateNumbers = $questions()
            ->select('question_number')
            ->groupBy('question_number')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $checks[] = PreflightCheck::forCount(
            'Questions', 'question numbers', $duplicateNumbers,
            'question numbers appear more than once',
            'question numbers are unique',
        );

        return $checks;
    }

    // ─────────────────────────────────────────────────────── contestants ────

    /** @return list<PreflightCheck> */
    private function contestantChecks(CompetitionSettings $settings): array
    {
        $base = fn () => DB::table('competition_users');

        $total = $base()->count();

        $accounts = $base()->selectRaw('account_status, COUNT(*) c')->groupBy('account_status')->pluck('c', 'account_status');
        $emails = $base()->selectRaw('email_status, COUNT(*) c')->groupBy('email_status')->pluck('c', 'email_status');
        $exams = $base()->selectRaw('exam_status, COUNT(*) c')->groupBy('exam_status')->pluck('c', 'exam_status');

        $checks = [
            $total > 0
                ? PreflightCheck::pass('Contestants', 'total', "{$total} participations")
                : PreflightCheck::fail('Contestants', 'total', 'no participations exist - nobody can compete'),
            PreflightCheck::pass('Contestants', 'account status', $this->distribution($accounts, CompetitionUser::ACCOUNT_PENDING, CompetitionUser::ACCOUNT_CREATED, CompetitionUser::ACCOUNT_FAILED)),
            PreflightCheck::pass('Contestants', 'email status', $this->distribution($emails, CompetitionUser::EMAIL_PENDING, CompetitionUser::EMAIL_SENT, CompetitionUser::EMAIL_FAILED)),
            PreflightCheck::pass('Contestants', 'exam status', $this->distribution($exams, CompetitionUser::EXAM_NOT_STARTED, CompetitionUser::EXAM_IN_PROGRESS, CompetitionUser::EXAM_COMPLETED)),
        ];

        $usable = (int) ($accounts[CompetitionUser::ACCOUNT_CREATED] ?? 0);

        $checks[] = $usable > 0
            ? PreflightCheck::pass('Contestants', 'usable accounts', "{$usable} contestants can log in")
            : PreflightCheck::fail('Contestants', 'usable accounts', 'no contestant has a provisioned account - nobody could log in');

        $notProvisioned = $total - $usable;

        if ($notProvisioned > 0) {
            // A warning, not a blocker: no stated rule says every invitee must
            // be provisioned before the portal may open.
            $checks[] = PreflightCheck::warning('Contestants', 'unprovisioned', "{$notProvisioned} participations have no account and cannot log in");
        }

        $undelivered = (int) ($emails[CompetitionUser::EMAIL_PENDING] ?? 0) + (int) ($emails[CompetitionUser::EMAIL_FAILED] ?? 0);

        if ($undelivered > 0) {
            // Explicitly a warning. Launch is not blocked by delivery failures
            // unless the business states that rule.
            $checks[] = PreflightCheck::warning('Contestants', 'credential delivery', "{$undelivered} contestants have not been sent credentials (pending or failed) - not a launch blocker under any stated rule");
        }

        // Case-insensitive duplicates: two rows differing only in case are two
        // people to the database but one person to the doctor.
        $duplicateEmails = $base()
            ->selectRaw('LOWER(contestant_email) AS e')
            ->groupByRaw('LOWER(contestant_email)')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $checks[] = PreflightCheck::forCount(
            'Contestants', 'duplicate emails', $duplicateEmails,
            'email addresses appear on more than one participation',
            'every contestant email is unique',
        );

        $orphanUsers = DB::table('competition_users as cu')
            ->leftJoin('users as u', 'u.id', '=', 'cu.user_id')
            ->whereNotNull('cu.user_id')
            ->whereNull('u.id')
            ->count();

        $checks[] = PreflightCheck::forCount(
            'Contestants', 'orphan account links', $orphanUsers,
            'participations point at a user row that does not exist',
            'every linked account exists',
        );

        $createdWithoutUser = $base()
            ->where('account_status', CompetitionUser::ACCOUNT_CREATED)
            ->whereNull('user_id')
            ->count();

        $checks[] = PreflightCheck::forCount(
            'Contestants', 'account state', $createdWithoutUser,
            'participations are marked created but have no user_id',
            'every created account is linked to a user',
        );

        return $checks;
    }

    // ───────────────────────────────────────────────────────── exam data ────

    /**
     * Integrity of the Array + Index exam state.
     *
     * Everything the exam knows now lives on competition_users, so these checks
     * read the participation row and inspect two strings: the persisted question
     * order and the positional answer string. They must agree with each other,
     * with the question bank, and with the stored totals.
     *
     * @return list<PreflightCheck>
     */
    private function examDataChecks(CompetitionSettings $settings): array
    {
        $count = $settings->questionCount();
        $seconds = $settings->secondsPerQuestion();
        $now = now()->format('Y-m-d H:i:s');

        $bank = DB::table('competition_questions')
            ->pluck('correct_option', 'id')
            ->all();

        $withOrder = 0;
        $startedWithoutOrder = 0;
        $wrongLength = 0;
        $duplicateIds = 0;
        $foreignIds = 0;
        $badAnswerLength = 0;
        $badAnswerChars = 0;
        $indexOutOfRange = 0;
        $answeredAhead = 0;
        $missingStartedAt = 0;
        $aheadOfTheClock = 0;
        $notStartedWithProgress = 0;
        $completedNotAtEnd = 0;
        $aggregateMismatch = 0;

        DB::table('competition_users')
            ->orderBy('id')
            ->select([
                'id', 'exam_status', 'started_at', 'completed_at', 'question_order',
                'current_question', 'answers',
                'correct_answers', 'answered_questions',
            ])
            ->chunk(500, function ($rows) use (
                $count, $bank, $seconds, $now,
                &$withOrder, &$startedWithoutOrder, &$wrongLength, &$duplicateIds, &$foreignIds,
                &$badAnswerLength, &$badAnswerChars, &$indexOutOfRange, &$answeredAhead,
                &$missingStartedAt, &$aheadOfTheClock, &$notStartedWithProgress,
                &$completedNotAtEnd, &$aggregateMismatch,
            ): void {
                foreach ($rows as $row) {
                    $started = $row->exam_status !== CompetitionUser::EXAM_NOT_STARTED;
                    $order = $row->question_order === null ? [] : (array) json_decode($row->question_order, true);
                    $answers = (string) $row->answers;
                    $index = $row->current_question === null ? null : (int) $row->current_question;

                    if ($order === []) {
                        if ($started) {
                            $startedWithoutOrder++;
                        }

                        continue;
                    }

                    $withOrder++;

                    if (count($order) !== $count) {
                        $wrongLength++;
                    }

                    if (count(array_unique($order)) !== count($order)) {
                        $duplicateIds++;
                    }

                    if (array_diff($order, array_keys($bank)) !== []) {
                        $foreignIds++;
                    }

                    if (strlen($answers) !== count($order)) {
                        $badAnswerLength++;
                    }

                    if (preg_match('/[^ABCD\-]/', $answers) === 1) {
                        $badAnswerChars++;
                    }

                    if ($index === null || $index < 0 || $index > $count) {
                        $indexOutOfRange++;

                        continue;
                    }

                    // Nothing may be recorded at a position the contestant has
                    // not reached — that would mean answering out of order.
                    if (preg_match('/[ABCD]/', substr($answers, $index)) === 1) {
                        $answeredAhead++;
                    }

                    if ($started && $row->started_at === null) {
                        $missingStartedAt++;
                    }

                    // Invariant 4 of the engine: the index reaches time_index by
                    // reconciliation and time_index + 1 by answering the live
                    // slot, so it can never be further ahead than that. A row
                    // that is means something wrote a position the clock never
                    // reached.
                    if ($row->exam_status === CompetitionUser::EXAM_IN_PROGRESS && $row->started_at !== null) {
                        $elapsed = max(0, strtotime((string) $now) - strtotime((string) $row->started_at));
                        $timeIndex = intdiv($elapsed, $seconds);

                        if ($index > $timeIndex + 1) {
                            $aheadOfTheClock++;
                        }
                    }

                    if (! $started && ($index > 0 || preg_match('/[ABCD]/', $answers) === 1)) {
                        $notStartedWithProgress++;
                    }

                    if ($row->exam_status === CompetitionUser::EXAM_COMPLETED) {
                        if ($index < $count) {
                            $completedNotAtEnd++;
                        }

                        $correct = 0;
                        $answered = 0;

                        foreach ($order as $position => $questionId) {
                            $given = substr($answers, $position, 1);

                            if ($given === '' || $given === false || $given === CompetitionUser::NO_ANSWER) {
                                continue;
                            }

                            $answered++;

                            if (($bank[$questionId] ?? null) === $given) {
                                $correct++;
                            }
                        }

                        if ((int) $row->correct_answers !== $correct || (int) $row->answered_questions !== $answered) {
                            $aggregateMismatch++;
                        }
                    }
                }
            });

        return [
            PreflightCheck::pass('Exam data', 'question orders', $withOrder.' contestants hold a persisted order'),

            PreflightCheck::forCount(
                'Exam data', 'missing orders', $startedWithoutOrder,
                'started contestants have no question_order',
                'every started contestant has a persisted question order',
            ),

            PreflightCheck::forCount(
                'Exam data', 'order length', $wrongLength,
                "orders are not exactly {$count} questions",
                "every order is exactly {$count} questions",
            ),

            PreflightCheck::forCount(
                'Exam data', 'duplicate questions', $duplicateIds,
                'orders contain the same question twice',
                'no order contains a repeated question',
            ),

            PreflightCheck::forCount(
                'Exam data', 'foreign questions', $foreignIds,
                "orders reference questions outside this competition's bank",
                'every question in every order belongs to this competition',
            ),

            PreflightCheck::forCount(
                'Exam data', 'answer length', $badAnswerLength,
                'answer strings do not match the length of the order',
                'every answer string matches its order length',
            ),

            PreflightCheck::forCount(
                'Exam data', 'answer alphabet', $badAnswerChars,
                'answer strings contain a character outside A/B/C/D/-',
                'every answer string uses only A, B, C, D or -',
            ),

            PreflightCheck::forCount(
                'Exam data', 'position range', $indexOutOfRange,
                "current_question is missing or outside 0..{$count}",
                'every current_question is within range',
            ),

            PreflightCheck::forCount(
                'Exam data', 'answers ahead of position', $answeredAhead,
                'contestants have answers recorded beyond their current position',
                'no contestant has answered ahead of their position',
            ),

            PreflightCheck::forCount(
                'Exam data', 'timeline anchor', $missingStartedAt,
                'started contestants have no started_at - their timeline cannot be derived',
                'every started contestant has a started_at',
            ),

            PreflightCheck::forCount(
                'Exam data', 'position vs clock', $aheadOfTheClock,
                'contestants sit further ahead than the fixed timeline allows',
                'no contestant is ahead of the position the clock permits',
            ),

            PreflightCheck::forCount(
                'Exam data', 'not-started integrity', $notStartedWithProgress,
                'not_started contestants already carry progress',
                'no not_started contestant has progress recorded',
            ),

            PreflightCheck::forCount(
                'Exam data', 'terminal position', $completedNotAtEnd,
                'completed contestants have not reached the end of their paper',
                'every completed contestant is at the end of their paper',
            ),

            PreflightCheck::forCount(
                'Exam data', 'completed aggregates', $aggregateMismatch,
                'completed contestants have stored totals that disagree with their answers',
                'every completed total matches the answers it summarises',
            ),

            PreflightCheck::forCount(
                'Exam data', 'completion timestamps',
                DB::table('competition_users')
                    ->where('exam_status', CompetitionUser::EXAM_COMPLETED)
                    ->whereNull('completed_at')
                    ->count(),
                'completed contestants have no completed_at',
                'every completed contestant has a completed_at',
            ),
        ];
    }

    /** @param  Collection<string, int>  $counts */
    private function distribution(Collection $counts, string ...$keys): string
    {
        $parts = [];

        foreach ($keys as $key) {
            $parts[] = $key.'='.(int) ($counts[$key] ?? 0);
        }

        return implode('  ', $parts);
    }
}
