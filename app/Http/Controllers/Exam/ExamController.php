<?php

namespace App\Http\Controllers\Exam;

use App\Exceptions\ExamException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitAnswerRequest;
use App\Models\CompetitionSettings;
use App\Models\CompetitionUser;
use App\Services\Competition\CompetitionExamService;
use App\Services\Competition\CompetitionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin by design: every decision lives in CompetitionExamService.
 *
 * Note what is absent from every signature — there is no competition id, no
 * competition_user id, no sequence, and no participation parameter. There is one
 * competition, and the contestant's row is always resolved FROM the
 * authenticated user, so there is no identifier an attacker could substitute to
 * reach another paper.
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
        $settings = CompetitionSettings::current();

        if ($settings === null) {
            return response()->json([
                'competition' => null,
                'status' => null,
                'open' => false,
                'reason' => 'no_competition',
                'server_time' => now()->toIso8601String(),
            ]);
        }

        $payload = [
            'competition' => $settings->name,
            'status' => $settings->status,
            'open' => $this->gate->mayParticipate($settings),
            // Same vocabulary the error contract uses, so the client has one
            // set of codes to branch on whether it asked or was refused.
            'reason' => $this->gate->reason($settings),
            'total_questions' => $settings->questionCount(),
            'seconds_per_question' => $settings->secondsPerQuestion(),
            'show_result' => $settings->show_result,

            // The availability window, and the personal allowance inside it.
            'starts_at' => $settings->starts_at?->toIso8601String(),
            'ends_at' => $settings->ends_at?->toIso8601String(),
            'exam_duration_minutes' => $settings->exam_duration_minutes,

            // What a contestant beginning NOW would actually get. A late starter
            // has to be told they are getting the remainder of the window rather
            // than a full allowance — before they press Begin, not after.
            'seconds_available' => $settings->secondsAvailableFrom(),

            'server_time' => now()->toIso8601String(),
        ];

        $user = $request->user();

        if ($user !== null) {
            $participation = $this->exam->participationFor($user);

            $payload['participation'] = $participation === null ? null : [
                'exam_status' => $participation->exam_status,
                'account_status' => $participation->account_status,
            ];
        }

        return response()->json($payload);
    }

    public function start(Request $request): JsonResponse
    {
        $settings = $this->requireSettings();
        $participation = $this->exam->startOrResume($request->user(), $settings);

        return response()->json($this->exam->state($participation, $settings));
    }

    public function currentQuestion(Request $request): JsonResponse
    {
        $settings = $this->requireSettings();

        // Same envelope as /exam/start, so the client renders one shape whether
        // it just began, resumed, or merely refreshed.
        return response()->json($this->exam->state(
            $this->requireParticipation($request),
            $settings,
        ));
    }

    public function submit(SubmitAnswerRequest $request): JsonResponse
    {
        $settings = $this->requireSettings();
        $participation = $this->requireParticipation($request);

        $outcome = $this->exam->submitAnswer(
            $participation,
            $settings,
            $request->questionId(),
            $request->selectedOption(),
        );

        /*
         * The tail of the answer is the same state the client would have got by
         * asking, so a submission needs no follow-up round trip.
         *
         * It is also the only part of this response that can fail, and by the
         * time it runs the answer is already committed. Letting it throw was
         * the worst answer available: the contestant was told their answer
         * failed when it had in fact been recorded, and a contestant told that
         * submits again.
         *
         * So a failure here costs the convenience, never the answer. The client
         * receives the outcome with no next question and asks for the current
         * state, which is exactly what it does after any network failure.
         */
        try {
            $next = $this->exam->state($participation, $settings)['question'];
        } catch (Throwable $e) {
            Log::warning('Madad: an answer was recorded but the next question could not be prepared', [
                'competition_user_id' => $participation->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $next = null;
        }

        return response()->json($outcome + ['next_question' => $next]);
    }

    public function result(Request $request): JsonResponse
    {
        $settings = $this->requireSettings();

        return response()->json($this->exam->result(
            $this->requireParticipation($request),
            $settings,
        ));
    }

    // ───────────────────────────────────────────────────────── internals ────

    /**
     * Phase 1 runs exactly one competition, and its configuration is a database
     * singleton. The client never names it — there is nothing to name.
     */
    private function requireSettings(): CompetitionSettings
    {
        $settings = CompetitionSettings::current();

        if ($settings === null) {
            throw ExamException::competitionNotOpen();
        }

        return $settings;
    }

    private function requireParticipation(Request $request): CompetitionUser
    {
        $participation = $this->exam->participationFor($request->user());

        if ($participation === null) {
            throw ExamException::notAContestant();
        }

        return $participation;
    }
}
