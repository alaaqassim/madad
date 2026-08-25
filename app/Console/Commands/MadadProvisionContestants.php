<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\CompetitionUser;
use App\Services\Competition\CredentialDeliveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Requirement 2 — create accounts, generate passwords, deliver credentials.
 *
 * ─── RERUNNING IS SAFE ──────────────────────────────────────────────────────
 * Participations whose credential was already delivered are not selected at
 * all, so a second run does nothing to them. Those that are selected reuse
 * their existing user row (or adopt one by email) rather than creating a
 * second, which the unique index on users.email would refuse anyway.
 *
 * No plaintext credential is written to any table or to this command's output.
 *
 * ─── THE REPORT ─────────────────────────────────────────────────────────────
 * The before/after figures are measured from the database, not accumulated from
 * the loop, so the report describes what is actually stored rather than what
 * the run believed it did.
 */
class MadadProvisionContestants extends Command
{
    protected $signature = 'madad:provision
                            {competition? : Competition id. Omit when there is only one}
                            {--limit=0 : Stop after this many participations (0 = all)}
                            {--retry-failed : Include participations whose delivery previously failed}
                            {--dry-run : Report what would be attempted and change nothing}';

    protected $description = 'Provision contestant accounts and deliver credentials (safe to rerun)';

    public function handle(CredentialDeliveryService $delivery): int
    {
        $competition = $this->resolveCompetition();

        if ($competition === null) {
            return self::FAILURE;
        }

        $before = $this->snapshot($competition);

        $query = CompetitionUser::query()
            ->where('competition_id', $competition->id)
            // Already delivered is never re-sent: a resend would invalidate a
            // password the contestant is already holding.
            ->where('email_status', '!=', CompetitionUser::EMAIL_SENT);

        if (! $this->option('retry-failed')) {
            $query->where('email_status', CompetitionUser::EMAIL_PENDING);
        }

        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $selected = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->line('DRY RUN — nothing was changed.');
            $this->line("would attempt: {$selected} participation(s)");
            $this->renderSnapshot('current state', $before);

            return self::SUCCESS;
        }

        $delivered = 0;
        $failed = 0;
        $retries = 0;
        $errors = [];

        $query->orderBy('id')->each(function (CompetitionUser $participation) use ($delivery, &$delivered, &$failed, &$retries, &$errors): void {
            // Anything with a prior attempt on the clock is a retry, not a
            // first delivery — worth counting separately because a high retry
            // count is the signal that the gateway itself is unhealthy.
            if ($participation->email_attempts > 0) {
                $retries++;
            }

            if ($delivery->deliver($participation)) {
                $delivered++;

                return;
            }

            $failed++;
            $error = $participation->fresh()->email_last_error;
            $errors[] = "{$participation->contestant_email} — {$error}";
        });

        $after = $this->snapshot($competition);

        $this->newLine();
        $this->table(['this run', 'count'], [
            ['source participations (total)', $before['total']],
            ['selected for this run', $selected],
            ['skipped (already delivered or not selected)', $before['total'] - $selected],
            ['accounts already created before this run', $before['account_created']],
            ['accounts newly created by this run', $after['account_created'] - $before['account_created']],
            ['email delivered by this run', $delivered],
            ['delivery retries attempted', $retries],
            ['delivery failures this run', $failed],
        ]);

        $this->renderSnapshot('state after this run', $after);

        if ($errors !== []) {
            $this->newLine();
            $this->warn(count($errors).' delivery error(s):');

            foreach (array_slice($errors, 0, 20) as $error) {
                $this->warn('  '.$error);
            }

            if (count($errors) > 20) {
                $this->warn('  … '.(count($errors) - 20).' more. Full detail is in competition_users.email_last_error.');
            }
        }

        if ($after['email_failed'] > 0 || $after['email_pending'] > 0) {
            $this->newLine();
            $this->line('Rerun with --retry-failed to re-issue credentials to the failed rows. A retry generates a NEW');
            $this->line('password and replaces the stored hash — the old one stops working, which is safe because a');
            $this->line('failed delivery means the contestant never received it.');
        }

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function snapshot(Competition $competition): array
    {
        $base = fn () => DB::table('competition_users')->where('competition_id', $competition->id);

        $accounts = $base()->selectRaw('account_status, COUNT(*) c')->groupBy('account_status')->pluck('c', 'account_status');
        $emails = $base()->selectRaw('email_status, COUNT(*) c')->groupBy('email_status')->pluck('c', 'email_status');

        return [
            'total' => $base()->count(),
            'account_pending' => (int) ($accounts[CompetitionUser::ACCOUNT_PENDING] ?? 0),
            'account_created' => (int) ($accounts[CompetitionUser::ACCOUNT_CREATED] ?? 0),
            'account_failed' => (int) ($accounts[CompetitionUser::ACCOUNT_FAILED] ?? 0),
            'email_pending' => (int) ($emails[CompetitionUser::EMAIL_PENDING] ?? 0),
            'email_sent' => (int) ($emails[CompetitionUser::EMAIL_SENT] ?? 0),
            'email_failed' => (int) ($emails[CompetitionUser::EMAIL_FAILED] ?? 0),
        ];
    }

    /** @param  array<string, int>  $snapshot */
    private function renderSnapshot(string $title, array $snapshot): void
    {
        $this->newLine();
        $this->table([$title, 'count'], [
            ['participations', $snapshot['total']],
            ['account: pending', $snapshot['account_pending']],
            ['account: created', $snapshot['account_created']],
            ['account: failed', $snapshot['account_failed']],
            ['email: pending', $snapshot['email_pending']],
            ['email: sent', $snapshot['email_sent']],
            ['email: failed', $snapshot['email_failed']],
        ]);
    }

    private function resolveCompetition(): ?Competition
    {
        $id = $this->argument('competition');

        if ($id !== null) {
            $competition = Competition::query()->find($id);

            if ($competition === null) {
                $this->error('Competition not found.');
            }

            return $competition;
        }

        $competitions = Competition::query()->orderBy('id')->get();

        if ($competitions->count() !== 1) {
            $this->error($competitions->isEmpty()
                ? 'No competition exists.'
                : 'More than one competition exists — name the one you mean.');

            return null;
        }

        return $competitions->first();
    }
}
