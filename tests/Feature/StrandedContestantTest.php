<?php

namespace Tests\Feature;

use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use App\Services\Competition\PreflightCheck;
use App\Services\Competition\PreflightService;
use App\Services\Competition\ResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * The contestant who walks away and never comes back.
 *
 * Nothing touches their row again, so nothing marks them finished, and every
 * results surface used to filter on that mark - so they vanished from the
 * ranking, the top hundred and the export with their answers sitting intact in
 * the table. A hundred contestants in the development database were in that
 * state and nobody knew until it was looked for.
 *
 * The usual answer is something that runs when their time expires. There is
 * nothing to run it: no cron and no persistent process on the production
 * server. So `effective_end_at` is written at Begin instead, and the results
 * read it. Their result no longer depends on when, or whether, anybody runs
 * anything.
 *
 * These tests are all one question asked from different directions: does a
 * contestant nobody settled get the result they earned?
 */
class StrandedContestantTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    /**
     * A contestant left mid-exam, exactly as an abandoned attempt looks.
     *
     * No completed_at, still in_progress - nothing has run for them - but their
     * answers are recorded and their end was written down at Begin.
     */
    private function abandoned(
        CompetitionSettings $competition,
        int $correct,
        int $startedMinutesAgo,
        int $lastsMinutes = 60,
    ): CompetitionUser {
        $contestant = $this->makeContestant($competition);
        $startedAt = now()->subMinutes($startedMinutesAgo);

        $contestant->forceFill([
            'exam_status' => CompetitionUser::EXAM_IN_PROGRESS,
            'started_at' => $startedAt,
            'effective_end_at' => $startedAt->copy()->addMinutes($lastsMinutes),
            'completed_at' => null,
            'correct_answers' => $correct,
            'answered_questions' => $correct,
        ])->save();

        return $contestant;
    }

    /** The same, but somebody did settle them. */
    private function settled(CompetitionSettings $competition, int $correct, int $startedMinutesAgo, int $lastsMinutes = 60): CompetitionUser
    {
        $contestant = $this->abandoned($competition, $correct, $startedMinutesAgo, $lastsMinutes);

        $contestant->forceFill([
            'exam_status' => CompetitionUser::EXAM_COMPLETED,
            'completed_at' => $contestant->effective_end_at,
        ])->save();

        return $contestant;
    }

    public function test_a_contestant_nobody_settled_is_still_in_the_results(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);

        // Began ninety minutes ago with a sixty minute allowance: their time
        // ran out half an hour ago and nothing has touched them since.
        $abandoned = $this->abandoned($competition, correct: 64, startedMinutesAgo: 90);

        $this->assertSame(CompetitionUser::EXAM_IN_PROGRESS, $abandoned->fresh()->exam_status);

        $fromView = DB::table('madad_results')->pluck('competition_user_id')->all();
        $fromService = app(ResultService::class)->completed()->pluck('id')->all();

        $this->assertSame([$abandoned->id], array_map('intval', $fromView), 'the view lost a finished contestant');
        $this->assertSame([$abandoned->id], $fromService, 'the service lost a finished contestant');
    }

    public function test_a_contestant_still_inside_their_time_is_not_in_the_results(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);

        // Ten minutes into a sixty minute allowance: still sitting the exam.
        $this->abandoned($competition, correct: 30, startedMinutesAgo: 10);

        $this->assertSame(0, DB::table('madad_results')->count(), 'somebody mid-exam was ranked');
        $this->assertCount(0, app(ResultService::class)->completed());
    }

    public function test_they_are_measured_at_the_end_they_were_going_to_have(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);

        // Sixty minutes of allowance, abandoned, noticed ninety minutes later.
        // The attempt is sixty minutes long - not ninety, which would be the
        // answer if the measurement ran from now.
        $abandoned = $this->abandoned($competition, correct: 50, startedMinutesAgo: 150, lastsMinutes: 60);

        $row = DB::table('madad_results')->where('competition_user_id', $abandoned->id)->first();

        $this->assertSame(3600, (int) $row->duration_seconds, 'the attempt was measured to now instead of to its end');
        $this->assertSame(
            3600,
            app(ResultService::class)->durationSeconds($abandoned->fresh()),
            'the service and the view measure differently',
        );
    }

    public function test_settling_afterwards_changes_nothing_about_their_result(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);
        $abandoned = $this->abandoned($competition, correct: 64, startedMinutesAgo: 120);

        $before = (array) DB::table('madad_results')->where('competition_user_id', $abandoned->id)->first();

        // Somebody runs madad:settle later. The row becomes honest; the result
        // must not move, or the ranking would depend on when it was run.
        $abandoned->forceFill([
            'exam_status' => CompetitionUser::EXAM_COMPLETED,
            'completed_at' => $abandoned->effective_end_at,
        ])->save();

        $after = (array) DB::table('madad_results')->where('competition_user_id', $abandoned->id)->first();

        $this->assertEquals($before, $after, 'settling a contestant moved their result');
    }

    public function test_settled_and_unsettled_contestants_rank_against_each_other_correctly(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);

        // Same score, different attempt lengths, and it must not matter which
        // of them anybody happened to settle.
        $slowSettled = $this->settled($competition, correct: 70, startedMinutesAgo: 200, lastsMinutes: 50);
        $fastAbandoned = $this->abandoned($competition, correct: 70, startedMinutesAgo: 90, lastsMinutes: 20);
        $middleAbandoned = $this->abandoned($competition, correct: 70, startedMinutesAgo: 150, lastsMinutes: 35);

        $order = array_map('intval', DB::table('madad_results')->orderBy('rank')->pluck('competition_user_id')->all());

        $this->assertSame(
            [$fastAbandoned->id, $middleAbandoned->id, $slowSettled->id],
            $order,
            'the tie-break ordered by settlement rather than by the shorter attempt',
        );

        $this->assertSame($order, app(ResultService::class)->completed()->pluck('id')->all());
    }

    public function test_the_top_hundred_and_the_export_see_them_too(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);

        $abandoned = $this->abandoned($competition, correct: 64, startedMinutesAgo: 120);
        $settled = $this->settled($competition, correct: 70, startedMinutesAgo: 200, lastsMinutes: 40);

        $top = array_map('intval', DB::table('madad_top100')->orderBy('rank')->pluck('competition_user_id')->all());

        $this->assertSame([$settled->id, $abandoned->id], $top);

        $report = app(ResultService::class)->topN($competition, 100);

        $this->assertSame(2, $report['returned']);
        $this->assertSame(2, $report['total_completed'], 'the count disagrees with the list it counts');
    }

    public function test_a_contestant_who_never_began_is_not_swept_in(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);

        $this->makeContestant($competition);                    // not_started
        $this->abandoned($competition, 64, 120);                // finished, unsettled

        $this->assertSame(1, DB::table('madad_results')->count(), 'somebody who never sat the exam was ranked');
    }

    public function test_preflight_reports_the_stranded_rows_without_calling_them_a_blocker(): void
    {
        $competition = $this->makeCompetition(['question_count' => 75]);
        $this->makeQuestions($competition, 75);
        $this->abandoned($competition, 64, 120);

        $report = app(PreflightService::class)->run($competition);

        $settled = null;

        foreach ($report->checks as $check) {
            if ($check->name === 'settled') {
                $settled = $check;
            }
        }

        $this->assertNotNull($settled);

        // A warning, not a blocker: the results are right either way, and only
        // the row itself is out of date.
        $this->assertSame(PreflightCheck::WARNING, $settled->status);
        $this->assertStringContainsString('1 contestants', $settled->detail);
    }
}
