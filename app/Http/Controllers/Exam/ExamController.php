<?php

namespace App\Http\Controllers\Exam;

use App\Exceptions\ExamException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitAnswerRequest;
use App\Models\Competition;
use App\Models\CompetitionUser;
use App\Services\Competition\CompetitionExamService;
use App\Services\Competition\CompetitionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin by design: every decision lives in CompetitionExamService.
 *
 * Note what is absent from every signature — there is no competition_user id,
 * no sequence, and no participation parameter. The contestant's row is always
 * resolved FROM the authenticated user, so there is no identifier an attacker
 * could substitute to reach another paper.
 */
class ExamController extends Controller
{
    public function __construct(
        private readonly CompetitionExamService $exam,
        private readonly CompetitionGate $gate,
    ) {}

    /** Public: what the portal will let you do right now. */
    public function status(Request $request): JsonResponse
    {
        $competition = $this->activeCompetition();

        if ($competition === null) {
            return response()->json([
                'competition' => null,
                'status' => null,
                'open' => false,
                'reason' => 'no_competition',
                'server_time' => now()->toIso8601String(),
            ]);
        }

        $open = $this->gate->mayParticipate($competition);

        $payload = [
            'competition' => $competition->name,
            'status' => $competition->status,
            'open' => $open,
            // Same vocabulary the error contract uses, so the client has one
            // set of codes to branch on whether it asked or was refused.
            'reason' => match (true) {
                $open => null,
                $competition->isClosed() => 'competition_closed',
                default => 'competition_not_open',
            },
            'total_questions' => $competition->question_count,
            'seconds_per_question' => $competition->seconds_per_question,
            'show_result' => $competition->show_result,
            'server_time' => now()->toIso8601String(),
        ];

        $user = $request->user();

        if ($user !== null) {
            $participation = $this->exam->participationFor($user, $competition);

            $payload['participation'] = $participation === null ? null : [
                'exam_status' => $participation->exam_status,
                'account_status' => $participation->account_status,
            ];
        }

        return response()->json($payload);
    }

    public function start(Request $request): JsonResponse
    {
        $competition = $this->requireCompetition();
        $participation = $this->exam->startOrResume($request->user(), $competition);

        $question = $this->exam->currentQuestion($participation);

        return response()->json([
            'exam_status' => $participation->fresh()->exam_status,
            'started_at' => $participation->started_at?->toIso8601String(),
            'question' => $question,
        ]);
    }

    public function currentQuestion(Request $request): JsonResponse
    {
        $participation = $this->requireParticipation($request);
        $question = $this->exam->currentQuestion($participation);

        // Same envelope as /exam/start, so the client renders one shape whether
        // it just started, resumed, or merely refreshed.
        return response()->json([
            'exam_status' => $participation->fresh()->exam_status,
            'started_at' => $participation->started_at?->toIso8601String(),
            'question' => $question,
        ]);
    }

    public function submit(SubmitAnswerRequest $request): JsonResponse
    {
        $participation = $this->requireParticipation($request);

        $outcome = $this->exam->submitAnswer(
            $participation,
            $request->questionId(),
            $request->selectedOption(),
        );

        return response()->json($outcome + [
            'next_question' => $this->exam->currentQuestion($participation->fresh()),
        ]);
    }

    public function result(Request $request): JsonResponse
    {
        return response()->json($this->exam->result($this->requireParticipation($request)));
    }

    // ───────────────────────────────────────────────────────── internals ────

    /**
     * Phase 1 runs exactly one competition, so the backend resolves it rather
     * than letting the client name one — another identifier the client cannot
     * tamper with.
     */
    private function activeCompetition(): ?Competition
    {
        return Competition::query()->orderBy('id')->first();
    }

    private function requireCompetition(): Competition
    {
        $competition = $this->activeCompetition();

        if ($competition === null) {
            throw ExamException::competitionNotOpen();
        }

        return $competition;
    }

    private function requireParticipation(Request $request): CompetitionUser
    {
        $participation = $this->exam->participationFor($request->user(), $this->requireCompetition());

        if ($participation === null) {
            throw ExamException::notAContestant();
        }

        return $participation;
    }
}
