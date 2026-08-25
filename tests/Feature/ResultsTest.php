<?php

namespace Tests\Feature;

use App\Models\CompetitionUser;
use App\Services\Competition\CompetitionExamService;
use App\Services\Competition\ResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

class ResultsTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    private function completedContestant($competition, int $correct): CompetitionUser
    {
        $participation = $this->makeContestant($competition);
        $participation->forceFill([
            'exam_status' => CompetitionUser::EXAM_COMPLETED,
            'started_at' => now()->subMinutes(30),
            'completed_at' => now(),
            'correct_answers' => $correct,
            'answered_questions' => 75,
        ])->save();

        return $participation;
    }

    public function test_show_result_false_withholds_the_score(): void
    {
        $competition = $this->makeCompetition(['show_result' => false]);
        $participation = $this->completedContestant($competition, 63);

        $payload = app(CompetitionExamService::class)->result($participation);

        $this->assertFalse($payload['show_result']);
        $this->assertArrayNotHasKey('correct_answers', $payload, 'the score must not be sent at all, not merely hidden by the client');
        $this->assertSame(CompetitionUser::EXAM_COMPLETED, $payload['exam_status']);
    }

    public function test_show_result_true_exposes_the_score(): void
    {
        $competition = $this->makeCompetition(['show_result' => true, 'question_count' => 75]);
        $participation = $this->completedContestant($competition, 63);

        $payload = app(CompetitionExamService::class)->result($participation);

        $this->assertTrue($payload['show_result']);
        $this->assertSame(63, $payload['correct_answers']);
        $this->assertSame(75, $payload['total_questions']);
    }

    public function test_an_unfinished_exam_never_exposes_a_score_even_when_show_result_is_true(): void
    {
        $competition = $this->makeCompetition(['show_result' => true]);
        $participation = $this->makeContestant($competition);
        $participation->forceFill([
            'exam_status' => CompetitionUser::EXAM_IN_PROGRESS,
            'correct_answers' => 12,
        ])->save();

        $payload = app(CompetitionExamService::class)->result($participation->fresh());

        $this->assertArrayNotHasKey('correct_answers', $payload);
    }

    public function test_extraction_returns_only_completed_contestants_ordered_by_score(): void
    {
        $competition = $this->makeCompetition();

        $this->completedContestant($competition, 40);
        $this->completedContestant($competition, 70);
        $this->completedContestant($competition, 55);
        $this->makeContestant($competition); // not_started — must be excluded

        $rows = app(ResultService::class)->completed($competition);

        $this->assertCount(3, $rows);
        $this->assertSame([70, 55, 40], $rows->pluck('correct_answers')->all());
    }

    public function test_top_n_reports_that_no_tie_break_rule_exists(): void
    {
        $competition = $this->makeCompetition();
        $this->completedContestant($competition, 70);
        $this->completedContestant($competition, 60);

        $payload = app(ResultService::class)->topN($competition, 2);

        $this->assertNull($payload['tie_break_rule'], 'the tie-break is an open business decision and must stay null');
        $this->assertSame('correct_answers DESC', $payload['ordered_by']);
    }

    public function test_a_contested_cutoff_is_flagged_rather_than_silently_resolved(): void
    {
        $competition = $this->makeCompetition();
        $this->completedContestant($competition, 70);
        // Three contestants tie on 50 but only one place remains in a Top-2.
        $this->completedContestant($competition, 50);
        $this->completedContestant($competition, 50);
        $this->completedContestant($competition, 50);

        $payload = app(ResultService::class)->topN($competition, 2);

        $this->assertSame(2, $payload['returned']);
        $this->assertSame(50, $payload['cutoff_score']);
        $this->assertTrue($payload['cutoff_is_contested']);
        $this->assertSame(3, $payload['contestants_tied_at_cutoff']);
    }

    public function test_an_uncontested_cutoff_is_not_flagged(): void
    {
        $competition = $this->makeCompetition();
        $this->completedContestant($competition, 70);
        $this->completedContestant($competition, 50);
        $this->completedContestant($competition, 30);

        $payload = app(ResultService::class)->topN($competition, 2);

        $this->assertFalse($payload['cutoff_is_contested']);
    }

    public function test_extraction_is_stable_across_repeated_runs(): void
    {
        $competition = $this->makeCompetition();
        for ($i = 0; $i < 6; $i++) {
            $this->completedContestant($competition, 50);
        }

        $service = app(ResultService::class);
        $first = $service->completed($competition, 3)->pluck('id')->all();
        $second = $service->completed($competition, 3)->pluck('id')->all();

        // Stability is a pagination property, not a ranking rule.
        $this->assertSame($first, $second);
    }
}
