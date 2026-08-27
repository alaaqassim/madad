<?php

namespace Tests\Feature;

use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use App\Services\Competition\ResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/**
 * The `madad_results` view must never disagree with `ResultService`.
 *
 * The project manager reads results straight out of SQL, so the ranking exists
 * twice: once in PHP, which is the authority, and once in the view, which is
 * what they actually query. Two implementations of one rule drift — and this
 * one would drift silently, because a list ordered by score alone looks
 * perfectly reasonable and simply ignores the tie-break.
 *
 * So the ordering is compared row by row, against data whose ties can ONLY be
 * settled by duration. Change the rule in one place and this fails.
 */
class ResultsViewTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    /**
     * A completed attempt with an explicit score and length.
     *
     * Every contestant starts at a different moment, so duration and finishing
     * time are genuinely independent — data where everyone begins together
     * cannot tell the two apart, and would let a view ordered by `completed_at`
     * pass by accident.
     */
    private function finisher(int $correct, int $minutes, int $startedMinutesAgo): CompetitionUser
    {
        $contestant = $this->makeContestant(CompetitionSettings::current());
        $startedAt = now()->subMinutes($startedMinutesAgo);

        $contestant->forceFill([
            'exam_status' => CompetitionUser::EXAM_COMPLETED,
            'started_at' => $startedAt,
            'completed_at' => $startedAt->copy()->addMinutes($minutes),
            'correct_answers' => $correct,
            'answered_questions' => 75,
        ])->save();

        return $contestant;
    }

    /** A field whose ties are unresolvable without the duration term. */
    private function field(): void
    {
        $this->makeCompetition(['question_count' => 75]);

        // Three levels of score. Within each, only duration separates them —
        // and the durations are deliberately anti-correlated with start time.
        $this->finisher(70, 41, 200);
        $this->finisher(70, 12, 30);
        $this->finisher(70, 27, 300);

        $this->finisher(55, 8, 150);
        $this->finisher(55, 50, 20);

        $this->finisher(31, 33, 90);
        $this->finisher(31, 33, 240);   // a genuine dead heat: id decides
    }

    public function test_the_view_returns_exactly_what_the_service_returns(): void
    {
        $this->field();

        $fromService = app(ResultService::class)->completed()
            ->map(fn (CompetitionUser $p) => $p->id)
            ->all();

        $fromView = DB::table('madad_results')->orderBy('rank')->pluck('competition_user_id')->all();

        $this->assertNotEmpty($fromService);
        $this->assertSame(
            $fromService,
            array_map('intval', $fromView),
            'the view and ResultService disagree — one of them has drifted from the ranking rule',
        );
    }

    public function test_the_view_applies_the_tie_break_and_not_merely_the_score(): void
    {
        $this->field();

        $top = DB::table('madad_results')->orderBy('rank')->limit(3)->get();

        // All three scored 70; the order is entirely the tie-break's doing.
        $this->assertSame([70, 70, 70], array_map('intval', $top->pluck('correct_answers')->all()));
        $this->assertSame(
            [12 * 60, 27 * 60, 41 * 60],
            array_map('intval', $top->pluck('duration_seconds')->all()),
            'the view ordered by something other than the shorter attempt',
        );
    }

    public function test_the_view_ranks_by_duration_not_by_finishing_time(): void
    {
        $this->field();

        $top = DB::table('madad_results')->orderBy('rank')->limit(3)->get();
        $finishes = $top->pluck('completed_at')->all();

        // If the view were ordered by completed_at these would ascend. They do
        // not: the fastest attempt began most recently and finished last.
        $this->assertNotSame(
            collect($finishes)->sort()->values()->all(),
            $finishes,
            'the ordering is indistinguishable from ordering by finishing time',
        );
    }

    public function test_rank_is_a_dense_sequence_from_one(): void
    {
        $this->field();

        $ranks = array_map('intval', DB::table('madad_results')->orderBy('rank')->pluck('rank')->all());

        $this->assertSame(range(1, count($ranks)), $ranks);
    }

    public function test_the_view_shows_only_completed_contestants(): void
    {
        $this->field();

        $settings = CompetitionSettings::current();
        $this->makeContestant($settings)->forceFill(['exam_status' => CompetitionUser::EXAM_IN_PROGRESS])->save();
        $this->makeContestant($settings);   // not_started

        $this->assertSame(7, DB::table('madad_results')->count(), 'an unfinished contestant leaked into the results');
    }

    public function test_the_view_carries_no_secret(): void
    {
        $this->field();

        $columns = array_keys((array) DB::table('madad_results')->first());

        $this->assertSame([
            'rank',
            'competition_user_id',
            'contestant_name',
            'contestant_email',
            'correct_answers',
            'total_questions',
            'answered_questions',
            'started_at',
            'completed_at',
            'duration_seconds',
        ], $columns);

        // Nothing that could identify an answer, a paper, or an account.
        foreach (['answers', 'question_order', 'user_id', 'password', 'remember_token', 'current_question'] as $secret) {
            $this->assertNotContains($secret, $columns);
        }
    }

    // ── madad_top100 ────────────────────────────────────────────────────────

    public function test_the_top_hundred_view_holds_the_first_hundred(): void
    {
        $this->makeCompetition(['question_count' => 75]);

        for ($i = 0; $i < 130; $i++) {
            $this->finisher(75 - (int) ($i / 2), 10 + ($i % 37), 300 - $i);
        }

        $top = DB::table('madad_top100')->orderBy('rank')->get();

        $this->assertCount(100, $top);
        $this->assertSame(1, (int) $top->first()->rank);
        $this->assertSame(100, (int) $top->last()->rank);

        // It must be the SAME hundred the service returns, in the same order.
        $fromService = app(ResultService::class)->completed(100)->pluck('id')->all();

        $this->assertSame($fromService, array_map('intval', $top->pluck('competition_user_id')->all()));
    }

    public function test_the_top_hundred_view_returns_everyone_when_the_field_is_smaller(): void
    {
        $this->field();   // seven contestants

        $this->assertCount(7, DB::table('madad_top100')->get());
    }

    public function test_a_contestant_outside_the_hundred_is_absent_from_it_but_ranked_in_the_full_view(): void
    {
        $this->makeCompetition(['question_count' => 75]);

        for ($i = 0; $i < 105; $i++) {
            $this->finisher(75 - (int) ($i / 2), 10 + ($i % 37), 300 - $i);
        }

        $ranked = DB::table('madad_results')->orderByDesc('rank')->first();

        $this->assertSame(105, (int) $ranked->rank, 'the full view must rank everybody');
        $this->assertNull(
            DB::table('madad_top100')->where('competition_user_id', $ranked->competition_user_id)->first(),
            'someone past the cutoff appeared in the winners list',
        );
    }

    public function test_looking_up_one_contestant_still_gives_their_true_rank(): void
    {
        $this->field();

        // Third overall: 70 correct in 41 minutes, the slowest of the three.
        $expected = app(ResultService::class)->completed()->pluck('id')->all()[2];

        $row = DB::table('madad_results')->where('competition_user_id', $expected)->first();

        $this->assertNotNull($row, 'an individual lookup returned nothing');
        $this->assertSame(
            3,
            (int) $row->rank,
            'filtering the view changed the rank - the window function was merged away',
        );
    }

    public function test_both_views_agree_where_they_overlap(): void
    {
        $this->makeCompetition(['question_count' => 75]);

        for ($i = 0; $i < 120; $i++) {
            $this->finisher(75 - (int) ($i / 2), 10 + ($i % 37), 300 - $i);
        }

        $fromFull = DB::table('madad_results')->orderBy('rank')->limit(100)->pluck('competition_user_id')->all();
        $fromTop = DB::table('madad_top100')->orderBy('rank')->pluck('competition_user_id')->all();

        $this->assertSame($fromFull, $fromTop, 'the two views disagree about who is in the top 100');
    }

    public function test_the_top_hundred_view_carries_the_same_columns(): void
    {
        $this->field();

        $this->assertSame(
            array_keys((array) DB::table('madad_results')->first()),
            array_keys((array) DB::table('madad_top100')->first()),
        );
    }

    public function test_the_top_hundred_is_a_plain_limit(): void
    {
        $this->makeCompetition(['question_count' => 75]);

        for ($i = 0; $i < 120; $i++) {
            $this->finisher(75 - (int) ($i / 2), 10 + ($i % 37), 300 - $i);
        }

        $top = DB::table('madad_results')->orderBy('rank')->limit(100)->get();

        $this->assertCount(100, $top);
        $this->assertSame(1, (int) $top->first()->rank);
        $this->assertSame(100, (int) $top->last()->rank);

        // And it agrees with the command's own Top 100.
        $fromService = app(ResultService::class)->completed(100)->pluck('id')->all();

        $this->assertSame($fromService, array_map('intval', $top->pluck('competition_user_id')->all()));
    }
}
