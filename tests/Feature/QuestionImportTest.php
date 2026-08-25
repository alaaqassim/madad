<?php

namespace Tests\Feature;

use App\Models\CompetitionQuestion;
use App\Services\Competition\QuestionImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

class QuestionImportTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private function row(int $number, array $overrides = []): array
    {
        return $overrides + [
            'question_number' => $number,
            'question_text' => "سؤال {$number}",
            'option_a' => 'أ',
            'option_b' => 'ب',
            'option_c' => 'ج',
            'option_d' => 'د',
            'correct_option' => 'B',
        ];
    }

    public function test_it_imports_valid_rows(): void
    {
        $competition = $this->makeCompetition();

        $summary = app(QuestionImportService::class)->import($competition, [
            $this->row(1), $this->row(2), $this->row(3),
        ]);

        $this->assertSame(3, $summary->insertedCount());
        $this->assertSame(0, $summary->rejectedCount());
        $this->assertDatabaseCount('competition_questions', 3);
        $this->assertSame('B', CompetitionQuestion::query()->where('question_number', 1)->value('correct_option'));
    }

    public function test_it_rejects_an_invalid_correct_option_without_skipping_silently(): void
    {
        $competition = $this->makeCompetition();

        $summary = app(QuestionImportService::class)->import($competition, [
            $this->row(1),
            $this->row(2, ['correct_option' => 'E']),
        ]);

        $this->assertSame(1, $summary->insertedCount());
        $this->assertSame(1, $summary->rejectedCount());
        $this->assertSame(2, $summary->errors[0]['question_number']);
        $this->assertStringContainsString('correct option', strtolower($summary->errors[0]['errors'][0]));
    }

    public function test_it_rejects_a_row_with_a_missing_option(): void
    {
        $competition = $this->makeCompetition();

        $summary = app(QuestionImportService::class)->import($competition, [
            $this->row(1, ['option_c' => '']),
        ]);

        $this->assertSame(0, $summary->insertedCount());
        $this->assertSame(1, $summary->rejectedCount());
        $this->assertDatabaseCount('competition_questions', 0);
    }

    public function test_a_duplicate_question_number_inside_one_file_is_reported_not_merged(): void
    {
        $competition = $this->makeCompetition();

        $summary = app(QuestionImportService::class)->import($competition, [
            $this->row(1, ['question_text' => 'الأصل']),
            $this->row(1, ['question_text' => 'مكرر']),
        ]);

        $this->assertSame(1, $summary->insertedCount());
        $this->assertSame(1, $summary->rejectedCount());
        $this->assertDatabaseCount('competition_questions', 1);
        // The first occurrence wins; the duplicate does not quietly overwrite it.
        $this->assertSame('الأصل', CompetitionQuestion::query()->value('question_text'));
    }

    public function test_rerunning_updates_in_place_rather_than_duplicating(): void
    {
        $competition = $this->makeCompetition();
        $importer = app(QuestionImportService::class);

        $importer->import($competition, [$this->row(1), $this->row(2)]);

        $second = $importer->import($competition, [
            $this->row(1, ['question_text' => 'نص محدَّث', 'correct_option' => 'D']),
            $this->row(2),
        ]);

        $this->assertSame(0, $second->insertedCount());
        $this->assertSame(2, $second->updatedCount());
        $this->assertDatabaseCount('competition_questions', 2);

        $first = CompetitionQuestion::query()->where('question_number', 1)->first();
        $this->assertSame('نص محدَّث', $first->question_text);
        $this->assertSame('D', $first->correct_option);
    }

    public function test_the_answer_key_is_never_serialised(): void
    {
        $competition = $this->makeCompetition();
        app(QuestionImportService::class)->import($competition, [$this->row(1)]);

        $question = CompetitionQuestion::query()->first();

        $this->assertArrayNotHasKey('correct_option', $question->toArray());
        $this->assertArrayNotHasKey('correct_option', $question->toContestantPayload());
    }
}
