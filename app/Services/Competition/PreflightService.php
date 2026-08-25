<?php

namespace App\Services\Competition;

use App\Models\Competition;
use App\Models\CompetitionQuestion;
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

    public function run(?Competition $competition = null): PreflightReport
    {
        $competition ??= Competition::query()->orderBy('id')->first();

        $checks = array_merge(
            $this->environmentChecks(),
            $this->competitionChecks($competition),
        );

        if ($competition !== null) {
            $checks = array_merge(
                $checks,
                $this->questionChecks($competition),
                $this->contestantChecks($competition),
                $this->examDataChecks($competition),
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
    private function competitionChecks(?Competition $competition): array
    {
        if ($competition === null) {
            return [PreflightCheck::fail('Competition', 'exists', 'no competition row exists - nothing can run')];
        }

        $total = Competition::query()->count();

        $checks = [
            $total === 1
                ? PreflightCheck::pass('Competition', 'exists', "exactly one competition: #{$competition->id} \"{$competition->name}\"")
                : PreflightCheck::warning('Competition', 'exists', "{$total} competitions exist; checking #{$competition->id} \"{$competition->name}\""),
            PreflightCheck::pass('Competition', 'status', $competition->status.' (portal open: '.($competition->isOpen() ? 'yes' : 'no').')'),
            PreflightCheck::pass('Competition', 'show_result', $competition->show_result ? 'true - contestants see their score' : 'false - contestants do not see their score'),
        ];

        $checks[] = $competition->question_count > 0
            ? PreflightCheck::pass('Competition', 'question_count', (string) $competition->question_count)
            : PreflightCheck::fail('Competition', 'question_count', 'must be greater than zero');

        $checks[] = $competition->seconds_per_question > 0
            ? PreflightCheck::pass('Competition', 'seconds_per_question', $competition->seconds_per_question.' seconds')
            : PreflightCheck::fail('Competition', 'seconds_per_question', 'must be greater than zero');

        if ($competition->question_count > 0 && $competition->question_count !== self::EXPECTED_QUESTIONS) {
            $checks[] = PreflightCheck::warning(
                'Competition',
                'expected size',
                "question_count is {$competition->question_count}; Phase 1 was specified as ".self::EXPECTED_QUESTIONS,
            );
        }

        return $checks;
    }

    // ───────────────────────────────────────────────────────── questions ────

    /** @return list<PreflightCheck> */
    private function questionChecks(Competition $competition): array
    {
        $questions = fn () => DB::table('competition_questions')->where('competition_id', $competition->id);

        $total = $questions()->count();

        $checks = [
            $total >= $competition->question_count
                ? PreflightCheck::pass('Questions', 'bank size', "{$total} questions for a paper of {$competition->question_count}")
                : PreflightCheck::fail('Questions', 'bank size', "only {$total} questions for a paper of {$competition->question_count} - papers cannot be built"),
        ];

        if ($total >= $competition->question_count && $total < self::EXPECTED_QUESTIONS) {
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
    private function contestantChecks(Competition $competition): array
    {
        $base = fn () => DB::table('competition_users')->where('competition_id', $competition->id);

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
            ->where('cu.competition_id', $competition->id)
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

    /** @return list<PreflightCheck> */
    private function examDataChecks(Competition $competition): array
    {
        $paper = fn () => DB::table('competition_user_questions as cuq')
            ->join('competition_users as cu', 'cu.id', '=', 'cuq.competition_user_id')
            ->where('cu.competition_id', $competition->id);

        $checks = [
            PreflightCheck::pass('Exam data', 'assignments', $paper()->count().' question assignments'),
        ];

        $duplicateSequences = DB::table('competition_user_questions')
            ->selectRaw('competition_user_id, sequence')
            ->groupBy('competition_user_id', 'sequence')
            ->havingRaw('COUNT(*) > 1')
            ->get()->count();

        $checks[] = PreflightCheck::forCount(
            'Exam data', 'duplicate sequences', $duplicateSequences,
            '(contestant, sequence) pairs appear more than once',
            'no contestant has a repeated sequence position',
        );

        $duplicateQuestions = DB::table('competition_user_questions')
            ->selectRaw('competition_user_id, competition_question_id')
            ->groupBy('competition_user_id', 'competition_question_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()->count();

        $checks[] = PreflightCheck::forCount(
            'Exam data', 'duplicate assignments', $duplicateQuestions,
            'questions appear twice on the same paper',
            'no question appears twice on one paper',
        );

        // A row cannot be both answered and timed out; a selected option with no
        // answered_at, or an answer to a question that was never served, are the
        // other two states the engine must never produce.
        $checks[] = PreflightCheck::forCount(
            'Exam data', 'answered and timed out',
            $paper()->whereNotNull('cuq.answered_at')->where('cuq.timed_out', true)->count(),
            'rows are both answered and timed out',
            'no row is both answered and timed out',
        );

        $checks[] = PreflightCheck::forCount(
            'Exam data', 'answer timestamps',
            $paper()->whereNotNull('cuq.selected_option')->whereNull('cuq.answered_at')->count(),
            'rows carry a selected option with no answered_at',
            'every selected option has an answered_at',
        );

        $checks[] = PreflightCheck::forCount(
            'Exam data', 'unopened answers',
            $paper()->whereNotNull('cuq.answered_at')->whereNull('cuq.opened_at')->count(),
            'rows were answered without ever being served',
            'no question was answered before it was served',
        );

        $checks[] = PreflightCheck::forCount(
            'Exam data', 'scoring integrity',
            $paper()->where('cuq.is_correct', true)->whereNull('cuq.selected_option')->count(),
            'rows are marked correct with no option selected',
            'every correct row has a selected option',
        );

        // A deadline that is not exactly seconds_per_question after opened_at
        // would mean the 40-second rule was not applied to that question.
        $checks[] = PreflightCheck::forCount(
            'Exam data', 'timer windows',
            $paper()
                ->whereNotNull('cuq.opened_at')
                ->whereRaw('TIMESTAMPDIFF(SECOND, cuq.opened_at, cuq.expires_at) <> ?', [$competition->seconds_per_question])
                ->count(),
            "served questions have a window that is not {$competition->seconds_per_question} seconds",
            "every served question has exactly a {$competition->seconds_per_question}-second window",
        );

        // Papers that are the wrong length would silently advantage or
        // disadvantage a contestant against the field.
        $checks[] = PreflightCheck::forCount(
            'Exam data', 'paper length',
            DB::table('competition_users as cu')
                ->leftJoin('competition_user_questions as cuq', 'cuq.competition_user_id', '=', 'cu.id')
                ->where('cu.competition_id', $competition->id)
                ->where('cu.exam_status', '!=', CompetitionUser::EXAM_NOT_STARTED)
                ->select('cu.id')
                ->groupBy('cu.id')
                ->havingRaw('COUNT(cuq.id) <> ?', [$competition->question_count])
                ->get()->count(),
            "started contestants have a paper that is not {$competition->question_count} questions",
            "every started contestant has exactly {$competition->question_count} questions",
        );

        // A completed contestant must have no question still awaiting an answer.
        $checks[] = PreflightCheck::forCount(
            'Exam data', 'terminal states',
            DB::table('competition_users as cu')
                ->where('cu.competition_id', $competition->id)
                ->where('cu.exam_status', CompetitionUser::EXAM_COMPLETED)
                ->whereExists(function ($q): void {
                    $q->select(DB::raw(1))
                        ->from('competition_user_questions as cuq')
                        ->whereColumn('cuq.competition_user_id', 'cu.id')
                        ->whereNull('cuq.answered_at')
                        ->where('cuq.timed_out', false);
                })->count(),
            'completed contestants still have an unanswered, un-expired question',
            'no completed contestant has an outstanding question',
        );

        $checks[] = PreflightCheck::forCount(
            'Exam data', 'not-started integrity',
            DB::table('competition_users as cu')
                ->where('cu.competition_id', $competition->id)
                ->where('cu.exam_status', CompetitionUser::EXAM_NOT_STARTED)
                ->whereExists(function ($q): void {
                    $q->select(DB::raw(1))
                        ->from('competition_user_questions as cuq')
                        ->whereColumn('cuq.competition_user_id', 'cu.id')
                        ->whereNotNull('cuq.opened_at');
                })->count(),
            'not_started contestants have already been served a question',
            'no not_started contestant has been served a question',
        );

        // The stored result must equal the rows it summarises.
        $checks[] = PreflightCheck::forCount(
            'Exam data', 'completed aggregates',
            DB::table('competition_users as cu')
                ->leftJoin('competition_user_questions as cuq', 'cuq.competition_user_id', '=', 'cu.id')
                ->where('cu.competition_id', $competition->id)
                ->where('cu.exam_status', CompetitionUser::EXAM_COMPLETED)
                ->select('cu.id', 'cu.correct_answers', 'cu.answered_questions')
                ->groupBy('cu.id', 'cu.correct_answers', 'cu.answered_questions')
                ->havingRaw('cu.correct_answers <> COALESCE(SUM(cuq.is_correct), 0) OR cu.answered_questions <> COALESCE(SUM(cuq.selected_option IS NOT NULL), 0)')
                ->get()->count(),
            'completed contestants have stored totals that disagree with their answer rows',
            'every completed total matches the rows it summarises',
        );

        $checks[] = PreflightCheck::forCount(
            'Exam data', 'completion timestamps',
            DB::table('competition_users')
                ->where('competition_id', $competition->id)
                ->where('exam_status', CompetitionUser::EXAM_COMPLETED)
                ->whereNull('completed_at')
                ->count(),
            'completed contestants have no completed_at',
            'every completed contestant has a completed_at',
        );

        return $checks;
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
