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
            return response()->json(['open' => false, 'reason' => 'no_competition']);
        }

        $payload = [
            'competition' => $competition->name,
            'status' => $competition->status,
            'open' => $this->gate->mayParticipate($competition),
            'total_questions' => $competition->question_count,
            'seconds_per_question' => $competition->seconds_per_question,
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

        return response()->json([
            'exam_status' => $participation->exam_status,
            'started_at' => $participation->started_at?->toIso8601String(),
            'question' => $this->exam->currentQuestion($participation),
        ]);
    }

    public function currentQuestion(Request $request): JsonResponse
    {
        $participation = $this->requireParticipation($request);

        return response()->json([
            'exam_status' => $participation->exam_status,
            'question' => $this->exam->currentQuestion($participation),
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
