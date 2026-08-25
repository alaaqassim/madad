<?php

namespace Tests\Feature;

use App\Exceptions\ExamException;
use App\Models\Competition;
use App\Models\CompetitionUser;
use App\Models\CompetitionUserQuestion;
use App\Services\Competition\CompetitionExamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MadadFixtures;
use Tests\TestCase;

/** Randomisation, stability of the persisted paper, and the status gate. */
class ExamPaperTest extends TestCase
{
    use MadadFixtures, RefreshDatabase;

    public function test_starting_creates_exactly_question_count_assignments_numbered_one_to_n(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $participation = $this->makeContestant($competition);

        app(CompetitionExamService::class)->startOrResume($participation->user, $competition);

        $rows = CompetitionUserQuestion::query()
            ->where('competition_user_id', $participation->id)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(5, $rows);
        $this->assertSame([1, 2, 3, 4, 5], $rows->pluck('sequence')->all());
        $this->assertCount(5, $rows->pluck('competition_question_id')->unique(), 'no question may repeat on a paper');
    }

    public function test_restarting_reuses_the_same_paper_and_never_reshuffles(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $participation = $this->makeContestant($competition);
        $service = app(CompetitionExamService::class);

        $service->startOrResume($participation->user, $competition);
        $first = CompetitionUserQuestion::query()
            ->where('competition_user_id', $participation->id)
            ->orderBy('sequence')->pluck('competition_question_id')->all();

        // A refresh, a re-login and a duplicate start request all land here.
        $service->startOrResume($participation->user, $competition);
        $service->startOrResume($participation->user, $competition);

        $second = CompetitionUserQuestion::query()
            ->where('competition_user_id', $participation->id)
            ->orderBy('sequence')->pluck('competition_question_id')->all();

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('competition_user_questions', 5);
    }

    public function test_started_at_is_not_reset_by_a_second_start(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $participation = $this->makeContestant($competition);
        $service = app(CompetitionExamService::class);

        $service->startOrResume($participation->user, $competition);
        $startedAt = $participation->fresh()->started_at;

        $this->travel(2)->minutes();
        $service->startOrResume($participation->user, $competition);

        $this->assertEquals($startedAt, $participation->fresh()->started_at);
    }

    public function test_papers_differ_between_contestants(): void
    {
        $competition = $this->makeCompetition(['question_count' => 20]);
        $this->makeQuestions($competition, 20);
        $service = app(CompetitionExamService::class);

        $orders = [];

        for ($i = 0; $i < 8; $i++) {
            $participation = $this->makeContestant($competition);
            $service->startOrResume($participation->user, $competition);
            $orders[] = implode(',', CompetitionUserQuestion::query()
                ->where('competition_user_id', $participation->id)
                ->orderBy('sequence')->pluck('competition_question_id')->all());
        }

        // 20! orderings — identical papers across eight contestants would mean
        // the shuffle is not happening at all.
        $this->assertGreaterThan(1, count(array_unique($orders)));
    }

    public function test_a_short_question_bank_is_refused_rather_than_silently_shortening_the_paper(): void
    {
        $competition = $this->makeCompetition(['question_count' => 10]);
        $this->makeQuestions($competition, 4);
        $participation = $this->makeContestant($competition);

        $this->expectException(ExamException::class);
        app(CompetitionExamService::class)->startOrResume($participation->user, $competition);
    }

    public static function blockedStatuses(): array
    {
        return [
            'draft' => [Competition::STATUS_DRAFT],
            'ready' => [Competition::STATUS_READY],
            'closed' => [Competition::STATUS_CLOSED],
        ];
    }

    #[DataProvider('blockedStatuses')]
    public function test_only_an_open_competition_permits_starting(string $status): void
    {
        $competition = $this->makeCompetition(['status' => $status, 'question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $participation = $this->makeContestant($competition);

        $this->expectException(ExamException::class);
        app(CompetitionExamService::class)->startOrResume($participation->user, $competition);
    }

    public function test_closing_also_blocks_resuming_an_exam_already_under_way(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $participation = $this->makeContestant($competition);
        $service = app(CompetitionExamService::class);

        $service->startOrResume($participation->user, $competition);

        // The approved rule is "closed → no new start/resume", with no carve-out
        // for contestants already sitting the paper.
        $competition->forceFill(['status' => Competition::STATUS_CLOSED])->save();

        $this->expectException(ExamException::class);
        $service->currentQuestion($participation->fresh());
    }

    /**
     * An account can exist while provisioning is still marked incomplete —
     * a delivery that failed after the user row was written, for instance.
     * Eligibility is decided by account_status, not by the mere existence of a
     * users row.
     */
    public function test_a_participation_whose_account_is_not_marked_created_cannot_start(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $participation = $this->makeContestant($competition);

        $participation->forceFill(['account_status' => CompetitionUser::ACCOUNT_FAILED])->save();

        try {
            app(CompetitionExamService::class)->startOrResume($participation->user, $competition);
            $this->fail('a participation without a created account must not start');
        } catch (ExamException $e) {
            $this->assertSame('account_not_provisioned', $e->reason);
        }

        $this->assertDatabaseCount('competition_user_questions', 0);
    }

    public function test_a_user_with_no_participation_is_refused(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);

        $outsider = \App\Models\User::query()->create([
            'name' => 'غريب',
            'email' => 'outsider@madad.test',
            'password' => 'irrelevant',
        ]);

        $this->expectException(ExamException::class);
        app(CompetitionExamService::class)->startOrResume($outsider, $competition);
    }

    public function test_exam_status_moves_to_in_progress_on_start(): void
    {
        $competition = $this->makeCompetition(['question_count' => 5]);
        $this->makeQuestions($competition, 5);
        $participation = $this->makeContestant($competition);

        app(CompetitionExamService::class)->startOrResume($participation->user, $competition);

        $this->assertSame(CompetitionUser::EXAM_IN_PROGRESS, $participation->fresh()->exam_status);
        $this->assertNotNull($participation->fresh()->started_at);
    }
}
