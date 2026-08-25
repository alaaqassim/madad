<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\CompetitionUser;
use App\Services\Competition\CredentialDeliveryService;
use Illuminate\Console\Command;

/**
 * Requirement 2 — create accounts, generate passwords, deliver credentials.
 *
 * Rerunning is safe: participations that already have a delivered credential
 * are skipped, and those that do not reuse their existing user row rather than
 * creating a second one.
 */
class MadadProvisionContestants extends Command
{
    protected $signature = 'madad:provision
                            {competition : Competition id}
                            {--limit=0 : Stop after this many participations (0 = all)}
                            {--retry-failed : Include participations whose delivery previously failed}';

    protected $description = 'Provision contestant accounts and deliver credentials';

    public function handle(CredentialDeliveryService $delivery): int
    {
        $competition = Competition::query()->find($this->argument('competition'));

        if ($competition === null) {
            $this->error('Competition not found.');

            return self::FAILURE;
        }

        $query = CompetitionUser::query()
            ->where('competition_id', $competition->id)
            ->where('email_status', '!=', CompetitionUser::EMAIL_SENT);

        if (! $this->option('retry-failed')) {
            $query->where('email_status', CompetitionUser::EMAIL_PENDING);
        }

        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $delivered = 0;
        $failed = 0;

        $query->orderBy('id')->each(function (CompetitionUser $participation) use ($delivery, &$delivered, &$failed): void {
            if ($delivery->deliver($participation)) {
                $delivered++;

                return;
            }

            $failed++;
            $this->warn("delivery failed: {$participation->contestant_email} — {$participation->fresh()->email_last_error}");
        });

        $this->table(['delivered', 'failed'], [[$delivered, $failed]]);

        return self::SUCCESS;
    }
}
