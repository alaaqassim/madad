<?php

namespace Tests\Feature;

use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use App\Services\Competition\ResultExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * The result file the doctor opens in Excel.
 *
 * The two things that can silently ruin it are Arabic arriving as mojibake and
 * the Top-100 boundary being decided by accident. Both are asserted here.
 */
class ResultExportTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = storage_path('framework/testing/madad-results-'.getmypid().'.csv');
        @unlink($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function completed(int $correct, ?string $name = null): CompetitionUser
    {
        $participation = $this->makeContestant(
            CompetitionSettings::current(),
            $name === null ? [] : ['contestant_name' => $name],
        );

        $participation->forceFill([
            'contestant_name' => $name ?? $participation->contestant_name,
            'exam_status' => CompetitionUser::EXAM_COMPLETED,
            'started_at' => now()->subMinutes(30),
            'completed_at' => now(),
            'correct_answers' => $correct,
            'answered_questions' => 75,
        ])->save();

        return $participation;
    }

    private function contents(): string
    {
        $this->assertFileExists($this->path);

        return file_get_contents($this->path);
    }

    // ── format ──────────────────────────────────────────────────────────────

    public function test_the_file_begins_with_a_utf8_bom_so_excel_reads_arabic(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);
        $this->completed(70, 'أحمد بن عبد الله');

        app(ResultExporter::class)->export($competition, $this->path, 100);

        // Without the BOM, Excel on Windows guesses the ANSI code page and the
        // Arabic names arrive as mojibake.
        $this->assertSame("\xEF\xBB\xBF", substr($this->contents(), 0, 3));
    }

    public function test_arabic_names_survive_the_round_trip_intact(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);
        $this->completed(70, 'أحمد بن عبد الله');
        $this->completed(60, 'فاطمة الزهراء');

        app(ResultExporter::class)->export($competition, $this->path, 100);
        $contents = $this->contents();

        $this->assertStringContainsString('أحمد بن عبد الله', $contents);
        $this->assertStringContainsString('فاطمة الزهراء', $contents);
        $this->assertTrue(mb_check_encoding($contents, 'UTF-8'), 'the file must be valid UTF-8');

        // And it must genuinely parse back as CSV, not merely contain the text.
        $handle = fopen($this->path, 'r');
        fgets($handle); // header (with BOM)
        $first = fgetcsv($handle);
        fclose($handle);

        $this->assertSame('أحمد بن عبد الله', $first[1]);
    }

    public function test_the_columns_are_the_agreed_headings_in_a_fixed_order(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);
        $this->completed(70);

        app(ResultExporter::class)->export($competition, $this->path, 100);

        $handle = fopen($this->path, 'r');
        $header = fgetcsv($handle);
        $row = fgetcsv($handle);
        fclose($handle);

        $header[0] = ltrim($header[0], "\xEF\xBB\xBF");

        $this->assertSame([
            'rank',
            'contestant_name',
            'contestant_email',
            'correct_answers',
            'total_questions',
            'answered_questions',
            'started_at',
            'completed_at',
        ], $header);

        $this->assertSame('1', $row[0]);
        $this->assertSame('70', $row[3]);
        $this->assertSame('75', $row[4], 'total_questions comes from the competition');
    }

    public function test_the_export_carries_no_password_hash_or_answer_key(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);
        $this->makeQuestions($competition, 5);

        for ($i = 0; $i < 5; $i++) {
            $this->completed(70 - $i);
        }

        app(ResultExporter::class)->export($competition, $this->path, 100);
        $contents = $this->contents();

        foreach (['password', '$2y$', 'correct_option', 'is_correct', 'remember_token'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $contents, "the export leaked: {$forbidden}");
        }
    }

    public function test_the_export_is_deterministic(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);

        for ($i = 0; $i < 8; $i++) {
            $this->completed(50);
        }

        app(ResultExporter::class)->export($competition, $this->path, 100);
        $first = $this->contents();

        app(ResultExporter::class)->export($competition, $this->path, 100);
        $second = $this->contents();

        $this->assertSame($first, $second, 'two extractions of the same data must be byte-identical');
    }

    public function test_only_completed_contestants_are_exported(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);
        $this->completed(70);

        $this->makeContestant($competition); // not_started
        $inProgress = $this->makeContestant($competition);
        $inProgress->forceFill(['exam_status' => CompetitionUser::EXAM_IN_PROGRESS, 'correct_answers' => 60])->save();

        $payload = app(ResultExporter::class)->export($competition, $this->path, 100);

        $this->assertSame(1, $payload['returned']);
        $this->assertSame(1, $payload['total_completed']);
        $this->assertSame(2, substr_count($this->contents(), "\n"), 'header plus one row');
    }

    public function test_a_name_that_looks_like_a_formula_is_neutralised(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);
        $this->completed(70, '=HYPERLINK("http://evil","click")');

        app(ResultExporter::class)->export($competition, $this->path, 100);

        // Excel must treat it as text, not evaluate it.
        $this->assertStringContainsString("'=HYPERLINK", $this->contents());
    }

    // ── Top 100 and the tie ─────────────────────────────────────────────────

    public function test_top_100_returns_at_most_one_hundred_rows(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);

        for ($i = 0; $i < 105; $i++) {
            $this->completed(75 - (int) ($i / 2));
        }

        $payload = app(ResultExporter::class)->export($competition, $this->path, 100);

        $this->assertSame(105, $payload['total_completed']);
        $this->assertSame(100, $payload['returned']);
        $this->assertSame(101, substr_count($this->contents(), "\n"), 'header plus 100 rows');
    }

    public function test_a_contested_top_100_cutoff_is_reported_and_warned_about(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);

        // 98 clear places, then five contestants tied for the last two.
        for ($i = 0; $i < 98; $i++) {
            $this->completed(75 - (int) ($i / 4) - 10);
        }

        for ($i = 0; $i < 5; $i++) {
            $this->completed(1);
        }

        $payload = app(ResultExporter::class)->export($competition, $this->path, 100);

        $this->assertSame(100, $payload['returned']);
        $this->assertSame(1, $payload['cutoff_score']);
        $this->assertTrue($payload['cutoff_is_contested']);
        $this->assertSame(5, $payload['contestants_tied_at_cutoff']);
        // The tie-break is still an open business decision and must stay null.
        $this->assertNull($payload['tie_break_rule']);

        $this->artisan('madad:results', ['--top' => 100, '--export' => $this->path])
            ->expectsOutputToContain('WARNING: Top-100 cutoff is tied and requires a business decision.')
            ->assertExitCode(0);
    }

    public function test_an_uncontested_cutoff_produces_no_warning(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);

        for ($i = 0; $i < 10; $i++) {
            $this->completed(70 - $i);
        }

        $payload = app(ResultExporter::class)->export($competition, $this->path, 100);

        $this->assertFalse($payload['cutoff_is_contested']);
        $this->assertSame(10, $payload['returned']);
    }

    public function test_top_zero_exports_every_completed_contestant(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);

        for ($i = 0; $i < 12; $i++) {
            $this->completed(40);
        }

        $payload = app(ResultExporter::class)->export($competition, $this->path, 0);

        $this->assertSame(12, $payload['returned']);
        $this->assertFalse($payload['cutoff_is_contested'], 'nobody is cut off when everyone is included');
    }

    public function test_the_command_writes_the_file_and_reports_the_counts(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);
        $this->completed(70, 'سعاد المنصوري');
        $this->completed(55);

        $this->artisan('madad:results', ['--top' => 100, '--export' => $this->path])
            ->expectsOutputToContain('CSV written to')
            ->expectsOutputToContain('completed: 2')
            ->assertExitCode(0);

        $this->assertStringContainsString('سعاد المنصوري', $this->contents());
    }

    public function test_the_command_refuses_an_unwritable_destination_without_crashing(): void
    {
        $this->makeCompetition(['question_count' => 75]);

        $this->artisan('madad:results', ['--export' => storage_path('no/such/directory/out.csv')])
            ->expectsOutputToContain('Export failed')
            ->assertExitCode(1);
    }
}
