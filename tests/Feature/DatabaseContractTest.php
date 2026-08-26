<?php

namespace Tests\Feature;

use App\Models\CompetitionUser;
use App\Services\Competition\CredentialDeliveryService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * THE DATABASE CONTRACT IS LOCKED.
 *
 * The competition schema was agreed and must not drift. This file is the lock:
 * the four tables, their exact columns, their exact types, the uniqueness rules
 * that make a paper stable, and the millisecond precision that makes the timing
 * evidence admissible.
 *
 * A failure here is not a bug in this test — it means the schema changed, and
 * that is a decision that has to be made deliberately and re-agreed.
 */
class DatabaseContractTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    /** The three competition tables. Exactly these, and no more. */
    private const COMPETITION_TABLES = [
        'competitions',
        'competition_questions',
        'competition_users',
    ];

    /** Framework tables Laravel itself owns; not part of the competition contract. */
    private const FRAMEWORK_TABLES = [
        'migrations', 'users', 'password_reset_tokens', 'sessions',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    ];

    /** @var array<string, array<string, string>> table => column => type */
    private const SCHEMA = [
        'competitions' => [
            'id' => 'bigint',
            'name' => 'varchar(191)',
            'status' => "enum('draft','ready','open','closed')",
            'show_result' => 'tinyint(1)',
            'question_count' => 'smallint',
            'seconds_per_question' => 'smallint',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ],
        'competition_questions' => [
            'id' => 'bigint',
            'competition_id' => 'bigint',
            'question_number' => 'smallint',
            'question_text' => 'text',
            'option_a' => 'varchar(500)',
            'option_b' => 'varchar(500)',
            'option_c' => 'varchar(500)',
            'option_d' => 'varchar(500)',
            'correct_option' => "enum('A','B','C','D')",
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ],
        'competition_users' => [
            'id' => 'bigint',
            'competition_id' => 'bigint',
            'user_id' => 'bigint',
            'contestant_name' => 'varchar(191)',
            'contestant_email' => 'varchar(191)',
            'source_reference' => 'varchar(100)',
            'account_status' => "enum('pending','created','failed')",
            'credentials_generated_at' => 'datetime',
            'email_status' => "enum('pending','sent','failed')",
            'email_attempts' => 'tinyint',
            'credentials_sent_at' => 'datetime',
            'email_last_error' => 'varchar(500)',
            'exam_status' => "enum('not_started','in_progress','completed')",
            'started_at' => 'datetime(3)',
            'completed_at' => 'datetime(3)',
            // The Array + Index exam state. question_order is a JSON array held
            // as a string: 75 ids of d digits encode to 75d + 76 characters, so
            // the current bank already needs 277 and varchar(255) would truncate.
            'question_order' => 'varchar(1024)',
            'current_question' => 'smallint',
            'current_question_started_at' => 'datetime(3)',
            'answers' => 'varchar(255)',
            'correct_answers' => 'smallint',
            'answered_questions' => 'smallint',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ],
    ];

    /** @return array<string, string> column => normalised type */
    private function columns(string $table): array
    {
        $columns = [];

        foreach (DB::select("SHOW COLUMNS FROM `{$table}`") as $column) {
            // Strip the unsigned marker and integer display widths: they are
            // storage detail, not part of the agreed contract. Enum members,
            // varchar lengths and datetime precision ARE part of it, and their
            // case is preserved.
            $type = str_replace(' unsigned', '', $column->Type);

            if (str_starts_with($type, 'int') || str_starts_with($type, 'bigint')
                || str_starts_with($type, 'smallint') || str_starts_with($type, 'mediumint')
                || ($type !== 'tinyint(1)' && str_starts_with($type, 'tinyint'))) {
                $type = preg_replace('/\(\d+\)/', '', $type);
            }

            $columns[$column->Field] = $type;
        }

        return $columns;
    }

    public function test_the_competition_schema_contains_exactly_three_tables(): void
    {
        $tables = array_map(
            fn ($row) => array_values((array) $row)[0],
            DB::select('SHOW TABLES'),
        );

        $competitionTables = array_values(array_filter(
            $tables,
            fn (string $t) => ! in_array($t, self::FRAMEWORK_TABLES, true),
        ));

        sort($competitionTables);
        $expected = self::COMPETITION_TABLES;
        sort($expected);

        $this->assertSame($expected, $competitionTables, 'the competition schema must be exactly these four tables');
    }

    public function test_every_column_matches_the_agreed_type(): void
    {
        foreach (self::SCHEMA as $table => $expected) {
            $this->assertSame($expected, $this->columns($table), "the schema of `{$table}` has drifted");
        }
    }

    public function test_millisecond_timing_precision_is_preserved(): void
    {
        // The timing evidence is only admissible at the precision it was stored
        // at. Narrowing any of these to whole seconds would silently make a
        // 40.000-second answer indistinguishable from a 40.999-second one.
        $precise = [
            'competition_users' => ['started_at', 'completed_at', 'current_question_started_at'],
        ];

        foreach ($precise as $table => $columns) {
            $actual = $this->columns($table);

            foreach ($columns as $column) {
                $this->assertSame('datetime(3)', $actual[$column], "{$table}.{$column} lost its millisecond precision");
            }
        }
    }

    public function test_there_are_no_pending_migrations(): void
    {
        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles([database_path('migrations')]);
        $pending = array_diff_key($files, array_flip($migrator->getRepository()->getRan()));

        $this->assertSame([], array_keys($pending), 'the database is behind the migrations');
    }

    public function test_no_column_anywhere_could_hold_a_plaintext_secret(): void
    {
        // Only text-bearing columns can hold a credential, so only those are
        // swept. credentials_generated_at and credentials_sent_at are datetimes
        // recording WHEN a credential was issued, never what it was.
        $forbidden = ['password', 'plaintext', 'plain', 'secret', 'api_key', 'token'];

        foreach (self::COMPETITION_TABLES as $table) {
            foreach ($this->columns($table) as $column => $type) {
                $holdsText = str_starts_with($type, 'varchar')
                    || str_starts_with($type, 'char')
                    || str_contains($type, 'text')
                    || str_contains($type, 'blob');

                if (! $holdsText) {
                    continue;
                }

                foreach ($forbidden as $pattern) {
                    $this->assertStringNotContainsString(
                        $pattern,
                        strtolower($column),
                        "{$table}.{$column} is a text column named like a secret",
                    );
                }
            }
        }
    }

    public function test_no_row_in_the_competition_tables_holds_a_bcrypt_hash(): void
    {
        // Provision and play a real exam first, so the sweep has genuine rows
        // written by the production code paths rather than an empty database.
        $competition = $this->makeCompetition(['question_count' => 3]);
        $this->makeQuestions($competition, 3);

        $participation = $this->makeUnprovisionedContestant($competition);
        app(CredentialDeliveryService::class)->deliver($participation);

        $this->actingAs($participation->fresh()->user);
        $question = $this->postJson('/api/exam/start')->assertOk()->json('question');
        $this->postJson('/api/exam/answer', [
            'question_id' => $question['question_id'], 'selected_option' => 'A',
        ])->assertOk();

        $this->assertNotNull($participation->fresh()->question_order);

        // The hash belongs on users.password and nowhere else. A copy anywhere
        // in the competition tables would be a second place to leak it from.
        foreach (self::COMPETITION_TABLES as $table) {
            foreach (DB::table($table)->get() as $record) {
                foreach ((array) $record as $column => $value) {
                    if (is_string($value)) {
                        $this->assertStringNotContainsString('$2y$', $value, "{$table}.{$column} holds a password hash");
                    }
                }
            }
        }
    }

    public function test_the_uniqueness_rules_that_keep_a_paper_stable_are_present(): void
    {
        $expected = [
            'competition_questions' => ['uq_competition_questions_competition_number'],
            'competition_users' => ['uq_competition_users_competition_email', 'uq_competition_users_competition_user'],
        ];

        foreach ($expected as $table => $indexes) {
            $present = collect(DB::select("SHOW INDEX FROM `{$table}`"))
                ->where('Non_unique', 0)
                ->pluck('Key_name')
                ->unique()
                ->all();

            foreach ($indexes as $index) {
                $this->assertContains($index, $present, "{$table} lost the unique index {$index}");
            }
        }
    }

    /**
     * The paper must survive the round trip.
     *
     * VARCHAR(255) was the original suggestion for question_order, and it is
     * too small: the live bank already encodes to 277 characters. A silent
     * truncation here would corrupt a contestant's paper without any error.
     */
    public function test_a_full_seventy_five_question_order_round_trips_without_truncation(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);
        $questions = $this->makeQuestions($competition, 75);
        $participation = $this->makeContestant($competition);

        $order = array_map(fn ($q) => $q->id, $questions);
        $encoded = json_encode($order);

        $participation->forceFill([
            'question_order' => $order,
            'answers' => str_repeat(CompetitionUser::NO_ANSWER, 75),
        ])->save();

        $stored = DB::table('competition_users')->where('id', $participation->id)->value('question_order');

        $this->assertSame($encoded, $stored, 'the question order was truncated on write');
        $this->assertSame($order, $participation->fresh()->order());
        $this->assertCount(75, $participation->fresh()->order());
        $this->assertSame(75, strlen($participation->fresh()->answers));
    }
}
