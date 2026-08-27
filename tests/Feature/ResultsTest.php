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

    /**
     * A completed attempt.
     *
     * `$minutes` is how long the attempt TOOK. It defaults to 30 so existing
     * cases are unaffected, and every attempt is anchored to a different start
     * so that duration and finishing time are genuinely independent — a test
     * that gave everyone the same start could not tell the two apart.
     */
    private function completedContestant($competition, int $correct, int $minutes = 30, int $startedMinutesAgo = 120): CompetitionUser
    {
        $participation = $this->makeContestant($competition);
        $startedAt = now()->subMinutes($startedMinutesAgo);

        $participation->forceFill([
            'exam_status' => CompetitionUser::EXAM_COMPLETED,
            'started_at' => $startedAt,
            'completed_at' => $startedAt->copy()->addMinutes($minutes),
            'correct_answers' => $correct,
            'answered_questions' => 75,
        ])->save();

        return $participation;
    }

    public function test_show_result_false_withholds_the_score(): void
    {
        $competition = $this->makeCompetition(['show_result' => false]);
        $participation = $this->completedContestant($competition, 63);

        $payload = app(CompetitionExamService::class)->result($participation, $competition);

        $this->assertFalse($payload['show_result']);
        $this->assertArrayNotHasKey('correct_answers', $payload, 'the score must not be sent at all, not merely hidden by the client');
        $this->assertSame(CompetitionUser::EXAM_COMPLETED, $payload['exam_status']);
    }

    public function test_show_result_true_exposes_the_score(): void
    {
        $competition = $this->makeCompetition(['show_result' => true, 'question_count' => 75]);
        $participation = $this->completedContestant($competition, 63);

        $payload = app(CompetitionExamService::class)->result($participation, $competition);

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

        $payload = app(CompetitionExamService::class)->result($participation->fresh(), $competition);

        $this->assertArrayNotHasKey('correct_answers', $payload);
    }

    public function test_extraction_returns_only_completed_contestants_ordered_by_score(): void
    {
        $competition = $this->makeCompetition();

        $this->completedContestant($competition, 40);
        $this->completedContestant($competition, 70);
        $this->completedContestant($competition, 55);
        $this->makeContestant($competition); // not_started — must be excluded

        $rows = app(ResultService::class)->completed();

        $this->assertCount(3, $rows);
        $this->assertSame([70, 55, 40], $rows->pluck('correct_answers')->all());
    }

    public function test_top_n_reports_the_confirmed_tie_break_rule(): void
    {
        $competition = $this->makeCompetition();
        $this->completedContestant($competition, 70);
        $this->completedContestant($competition, 60);

        $payload = app(ResultService::class)->topN($competition, 2);

        $this->assertSame('fastest_completion', $payload['tie_break_rule']);
        $this->assertSame('correct_answers DESC, duration ASC', $payload['ordered_by']);
    }

    public function test_contestants_level_on_score_are_ordered_by_the_shorter_attempt(): void
    {
        $competition = $this->makeCompetition();

        $slow = $this->completedContestant($competition, 50, minutes: 45);
        $fast = $this->completedContestant($competition, 50, minutes: 12);
        $middling = $this->completedContestant($competition, 50, minutes: 30);

        $order = app(ResultService::class)->completed()->pluck('id')->all();

        $this->assertSame([$fast->id, $middling->id, $slow->id], $order, 'the faster attempt did not win');
    }

    public function test_the_score_still_outranks_speed(): void
    {
        $competition = $this->makeCompetition();

        $fastButWrong = $this->completedContestant($competition, 40, minutes: 5);
        $slowButRight = $this->completedContestant($competition, 60, minutes: 55);

        $order = app(ResultService::class)->completed()->pluck('id')->all();

        // Speed only separates equals. It never beats a better score.
        $this->assertSame([$slowButRight->id, $fastButWrong->id], $order);
    }

    public function test_the_tie_break_measures_duration_not_finishing_time(): void
    {
        $competition = $this->makeCompetition();

        // Began early inside the window and took 50 minutes; finishes FIRST by
        // the wall clock.
        $earlyStarter = $this->completedContestant($competition, 50, minutes: 50, startedMinutesAgo: 180);
        // Began late and took 20 minutes; finishes LAST by the wall clock.
        $lateStarter = $this->completedContestant($competition, 50, minutes: 20, startedMinutesAgo: 60);

        $order = app(ResultService::class)->completed()->pluck('id')->all();

        $this->assertTrue(
            $earlyStarter->fresh()->completed_at->lessThan($lateStarter->fresh()->completed_at),
            'the fixture does not actually test what it claims',
        );

        // The one who took less time wins, even though they finished later.
        $this->assertSame([$lateStarter->id, $earlyStarter->id], $order);
    }

    public function test_the_hundred_and_first_beats_the_hundredth_when_faster(): void
    {
        $competition = $this->makeCompetition();

        // 99 clear places.
        for ($i = 0; $i < 99; $i++) {
            $this->completedContestant($competition, 70 - (int) ($i / 4), minutes: 30);
        }

        // Two contestants level on the cutoff score, one distinctly faster.
        $slower = $this->completedContestant($competition, 20, minutes: 44);
        $faster = $this->completedContestant($competition, 20, minutes: 9);

        $payload = app(ResultService::class)->topN($competition, 100);
        $ids = array_column($payload['rows'], 'competition_user_id');

        $this->assertSame(100, $payload['returned']);
        $this->assertSame($faster->id, end($ids), 'the faster contestant did not take the last place');
        $this->assertNotContains($slower->id, $ids, 'the slower contestant should have been pushed out');
        $this->assertFalse($payload['cutoff_is_contested'], 'the tie-break settled it, so nothing is contested');
        $this->assertSame(9 * 60, $payload['cutoff_duration_seconds']);
    }

    public function test_a_contested_cutoff_is_flagged_rather_than_silently_resolved(): void
    {
        $competition = $this->makeCompetition();
        $this->completedContestant($competition, 70);
        // Three contestants tie on 50 AND on duration — the one case the
        // faster-wins rule cannot settle.
        $this->completedContestant($competition, 50, minutes: 25);
        $this->completedContestant($competition, 50, minutes: 25);
        $this->completedContestant($competition, 50, minutes: 25);

        $payload = app(ResultService::class)->topN($competition, 2);

        $this->assertSame(2, $payload['returned']);
        $this->assertSame(50, $payload['cutoff_score']);
        $this->assertTrue($payload['cutoff_is_contested']);
        $this->assertSame(3, $payload['contestants_tied_at_cutoff']);
        $this->assertSame(2, $payload['contestants_indistinguishable_at_cutoff']);
    }

    public function test_a_cutoff_the_tie_break_can_settle_is_not_flagged(): void
    {
        $competition = $this->makeCompetition();
        $this->completedContestant($competition, 70, minutes: 30);
        // Level on score, but their durations differ, so the rule decides.
        $this->completedContestant($competition, 50, minutes: 20);
        $this->completedContestant($competition, 50, minutes: 35);

        $payload = app(ResultService::class)->topN($competition, 2);

        $this->assertSame(50, $payload['cutoff_score']);
        $this->assertSame(2, $payload['contestants_tied_at_cutoff'], 'they do share the score');
        $this->assertFalse($payload['cutoff_is_contested'], 'but duration separated them');
        $this->assertSame(0, $payload['contestants_indistinguishable_at_cutoff']);
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
        $first = $service->completed(3)->pluck('id')->all();
        $second = $service->completed(3)->pluck('id')->all();

        // Stability is a pagination property, not a ranking rule.
        $this->assertSame($first, $second);
    }
}
