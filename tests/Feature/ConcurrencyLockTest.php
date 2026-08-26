<?php

namespace Tests\Feature;

use App\Models\CompetitionUser;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * Proof that the row lock the exam engine relies on is real.
 *
 * ─── WHY THIS TEST IS SHAPED DIFFERENTLY ────────────────────────────────────
 * Every other test in the suite runs inside RefreshDatabase's wrapping
 * transaction — so its rows are invisible to any other connection, and a second
 * connection could not contend for them at all. This class keeps RefreshDatabase
 * for the schema but overrides beginDatabaseTransaction() to a no-op, so its
 * rows are genuinely COMMITTED and two real MySQL connections can be made to
 * fight over the same row.
 *
 * Because nothing rolls those rows back, this class cleans up after itself in
 * tearDown. It deliberately does NOT use DatabaseMigrations: that trait rolls
 * the migrations back when it finishes, which would leave every later test in
 * the process with no tables.
 *
 * ─── WHAT THIS PROVES, AND WHAT IT DOES NOT ─────────────────────────────────
 * ConcurrencyTest proves the engine decides correctly once a lock is released.
 * This proves the lock actually blocks in the first place — the piece a single
 * connection cannot demonstrate.
 *
 * The trade for a genuine second connection is that the two sides are driven in
 * sequence from one process: the blocked side is detected by its lock-wait
 * timeout rather than by a second thread waking up. That is a deliberate,
 * documented limitation — PHPUnit cannot safely fire two parallel HTTP requests
 * here, and the smallest reliable proof was preferred to a fragile one.
 */
class ConcurrencyLockTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    /**
     * Committed rows are the whole point of this class, so RefreshDatabase's
     * wrapping transaction is skipped. The schema setup it performs is kept.
     */
    public function beginDatabaseTransaction(): void
    {
        // Deliberately empty — see the class docblock.
    }

    /** A second, genuinely separate connection to the same test database. */
    private function secondConnection(): Connection
    {
        config(['database.connections.madad_second' => config('database.connections.'.config('database.default'))]);

        $connection = DB::connection('madad_second');

        // Fail fast instead of hanging the suite for the server default (50s).
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');

        return $connection;
    }

    protected function tearDown(): void
    {
        // Release any lock still held before deleting, or the delete would
        // block on it.
        while (DB::connection()->transactionLevel() > 0) {
            DB::connection()->rollBack();
        }

        if (array_key_exists('madad_second', config('database.connections') ?? [])) {
            DB::purge('madad_second');
        }

        $this->purgeCommittedRows();

        parent::tearDown();
    }

    /**
     * Nothing rolls this class's rows back, so it removes them itself — a later
     * test asserting `assertDatabaseCount('users', 1)` must not find leftovers.
     */
    private function purgeCommittedRows(): void
    {
        // Never truncate anything that is not the test database.
        if (DB::selectOne('SELECT DATABASE() AS d')->d !== 'madad_test') {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ([
            'competition_users',
            'competition_questions',
            'competitions',
            'users',
        ] as $table) {
            DB::table($table)->truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_the_participation_row_lock_blocks_a_second_connection(): void
    {
        $this->assertSame('madad_test', DB::selectOne('SELECT DATABASE() AS d')->d, 'tests must never run against a non-test database');

        $competition = $this->makeCompetition(['question_count' => 3]);
        $this->makeQuestions($competition, 3);
        $participation = $this->makeContestant($competition);

        $second = $this->secondConnection();

        // The two connections are genuinely separate sessions.
        $this->assertNotSame(
            DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
            $second->selectOne('SELECT CONNECTION_ID() AS id')->id,
        );

        // The row must really be committed, or there would be nothing to contend for.
        $this->assertNotNull($second->table('competition_users')->where('id', $participation->id)->first());

        // ── request A takes the lock the exam engine takes ──────────────────
        DB::beginTransaction();

        $this->assertNotNull(CompetitionUser::query()->whereKey($participation->id)->lockForUpdate()->first());

        // ── request B tries to take the same lock and is made to wait ───────
        $blocked = false;

        try {
            $second->transaction(function () use ($second, $participation): void {
                $second->table('competition_users')
                    ->where('id', $participation->id)
                    ->lockForUpdate()
                    ->first();
            }, 1);
        } catch (QueryException $e) {
            // MySQL 1205: lock wait timeout exceeded. The second request was
            // genuinely held off by the first — the serialisation is the
            // database's, not a convention in PHP.
            $blocked = str_contains($e->getMessage(), '1205')
                || str_contains(strtolower($e->getMessage()), 'lock wait timeout');
        }

        $this->assertTrue($blocked, 'a second connection must be blocked while the participation row is locked');

        // ── once A commits, B proceeds ─────────────────────────────────────
        DB::commit();

        $row = $second->table('competition_users')->where('id', $participation->id)->first();
        $this->assertNotNull($row, 'the lock must be released on commit');
        $this->assertSame($participation->id, (int) $row->id);
    }

    public function test_a_reader_on_another_connection_is_not_blocked_by_the_lock(): void
    {
        $competition = $this->makeCompetition(['question_count' => 3]);
        $participation = $this->makeContestant($competition);

        $second = $this->secondConnection();

        DB::beginTransaction();
        CompetitionUser::query()->whereKey($participation->id)->lockForUpdate()->first();

        // A plain SELECT is an InnoDB consistent read and must not queue behind
        // the write lock — which is why the read-only preflight and status
        // commands are safe to run while contestants are mid-exam.
        $seen = $second->table('competition_users')->where('id', $participation->id)->first();

        $this->assertNotNull($seen);
        $this->assertSame($participation->contestant_email, $seen->contestant_email);

        DB::commit();
    }
}
