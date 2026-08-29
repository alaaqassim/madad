<?php

namespace Tests\Feature;

use App\Models\CompetitionUser;
use App\Services\Competition\CredentialDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * THE DATABASE CONTRACT IS LOCKED.
 *
 * The competition schema was agreed and must not drift. This file is the lock:
 * the three competition tables, their exact columns, their exact types, the
 * uniqueness rules that make a paper stable, and the millisecond precision that
 * makes the timing evidence admissible.
 *
 * Two absences are asserted as hard as the presences, because both are business
 * rules rather than tidying: `competition_user_questions` (the per-question
 * assignment table the Array + Index model replaced) and
 * `current_question_started_at` (the arrival anchor the fixed timeline
 * replaced). A column that exists is a column something can start reading.
 *
 * A failure here is not a bug in this test — it means the schema changed, and
 * that is a decision that has to be made deliberately and re-agreed.
 */
class DatabaseContractTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    /** The three competition tables. Exactly these, and no more. */
    private const COMPETITION_TABLES = [
        'competition_settings',
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
        'competition_settings' => [
            // A fixed key, not a sequence: see the singleton migration.
            'id' => 'tinyint',
            'name' => 'varchar(191)',
            'status' => "enum('draft','ready','open','closed')",
            'show_result' => 'tinyint(1)',
            'question_count' => 'smallint',
            'seconds_per_question' => 'smallint',
            'exam_duration_minutes' => 'smallint',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ],
        'competition_questions' => [
            'id' => 'bigint',
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
            'user_id' => 'bigint',
            'contestant_name' => 'varchar(191)',
            'contestant_email' => 'varchar(191)',
            'source_reference' => 'varchar(100)',
            'account_status' => "enum('pending','created','failed')",
            'credentials_generated_at' => 'datetime',
            'email_attempts' => 'tinyint',
            'credentials_sent_at' => 'datetime',
            'email_last_error' => 'varchar(500)',
            'exam_status' => "enum('not_started','in_progress','completed')",
            'started_at' => 'datetime(3)',
            // When this attempt runs out, decided once at Begin. It is what
            // lets a results view see that a contestant has finished without
            // anything having to run at the moment they do.
            'effective_end_at' => 'datetime(3)',
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
        // BASE TABLE only. `SHOW TABLES` also lists views, and `madad_results`
        // is one — it stores nothing, it is the published ordering for people
        // who read the results out of SQL. The guarantee being locked here is
        // that no fourth place to KEEP data has crept in.
        $tables = DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_TYPE', 'BASE TABLE')
            ->pluck('TABLE_NAME')
            ->all();

        $competitionTables = array_values(array_filter(
            $tables,
            fn (string $t) => ! in_array($t, self::FRAMEWORK_TABLES, true),
        ));

        sort($competitionTables);
        $expected = self::COMPETITION_TABLES;
        sort($expected);

        $this->assertSame($expected, $competitionTables, 'the competition schema must be exactly these three tables');
    }

    public function test_the_only_view_is_the_published_results_ordering(): void
    {
        $views = DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_TYPE', 'VIEW')
            ->pluck('TABLE_NAME')
            ->all();

        sort($views);

        $this->assertSame(['madad_results', 'madad_top100'], $views, 'an unexpected view exists');
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
            'competition_users' => ['started_at', 'completed_at'],
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
            'competition_questions' => ['uq_competition_questions_number'],
            'competition_users' => ['uq_competition_users_email', 'uq_competition_users_user'],
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

    public function test_the_two_timing_anchors_both_exist_and_are_distinct(): void
    {
        // Under immediate advance the question timer cannot be derived from the
        // attempt timer: it depends on when the previous answer landed. So both
        // are stored, on the SAME row, and they mean different things.
        $columns = $this->columns('competition_users');

        $this->assertArrayHasKey('started_at', $columns, 'the attempt anchor is missing');
        $this->assertArrayHasKey('current_question_started_at', $columns, 'the question anchor is missing');

        // Millisecond precision on both, so a fast answer is not rounded into
        // free time.
        $this->assertSame('datetime(3)', $columns['started_at']);
        $this->assertSame('datetime(3)', $columns['current_question_started_at']);

        // And nothing derived is stored: no expiry, no end time, no per-question
        // opened_at beyond the single live one.
        foreach (['expires_at', 'ends_at', 'end_time', 'opened_at', 'deadline'] as $derived) {
            $this->assertArrayNotHasKey($derived, $columns, "a derived timestamp `{$derived}` was persisted");
        }
    }

    public function test_the_per_question_assignment_table_is_gone(): void
    {
        // The Array + Index model replaced one row per contestant-question with
        // one array on the participation row. Recreating that table would be a
        // return to 75,000 rows for 1,000 contestants.
        $this->assertFalse(Schema::hasTable('competition_user_questions'));

        $this->assertSame(0, DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'competition_user_questions')
            ->count());
    }

    public function test_the_multi_competition_scaffolding_is_gone(): void
    {
        $this->assertFalse(Schema::hasTable('competitions'));
        $this->assertFalse(Schema::hasColumn('competition_users', 'competition_id'));
        $this->assertFalse(Schema::hasColumn('competition_questions', 'competition_id'));

        // One foreign key survives, and it is the only relationship left.
        $foreignKeys = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->all();

        $this->assertSame(['fk_competition_users_user'], $foreignKeys);
    }

    public function test_the_settings_row_is_a_database_enforced_singleton(): void
    {
        $this->assertSame(1, DB::table('competition_settings')->count());

        // id = 1 is refused by the primary key.
        try {
            DB::table('competition_settings')->insert($this->settingsRow(1));
            $this->fail('a duplicate settings row was accepted');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Duplicate entry', $e->getMessage());
        }

        // Anything else is refused by the check constraint.
        try {
            DB::table('competition_settings')->insert($this->settingsRow(2));
            $this->fail('a second settings row was accepted');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('chk_competition_settings_singleton', $e->getMessage());
        }

        $this->assertSame(1, DB::table('competition_settings')->count());
    }

    /** @return array<string, mixed> */
    private function settingsRow(int $id): array
    {
        return [
            'id' => $id,
            'name' => 'Another competition',
            'status' => 'draft',
            'show_result' => false,
            'question_count' => 75,
            'seconds_per_question' => 40,
            'exam_duration_minutes' => 60,
        ];
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
