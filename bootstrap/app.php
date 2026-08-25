<?php

use App\Exceptions\ExamException;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\TooManyAttemptsException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
|--------------------------------------------------------------------------
| The contestant error contract
|--------------------------------------------------------------------------
|
| Every JSON error the Vue client can receive carries a `reason` — a stable,
| machine-readable code — alongside a human message. The codes and their HTTP
| statuses are documented in docs/API_CONTRACT.md and locked by
| tests/Feature/ErrorContractTest.php.
|
| Nothing here ever renders a SQL string, a stack trace, a model class name or
| an answer-key clue. The QueryException handler exists precisely so that a
| database failure cannot leak a query even when APP_DEBUG is on.
|
*/

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /** Does this request want the JSON contract rather than a web page? */
        $wantsJson = fn (Request $request): bool => $request->expectsJson() || $request->is('api/*');

        /*
         * ExamException carries a message that is safe to show a contestant and
         * a machine-readable reason the Vue client can branch on. Rendering it
         * here keeps every controller free of try/catch.
         */
        $exceptions->render(function (ExamException $e, Request $request) use ($wantsJson): ?JsonResponse {
            if (! $wantsJson($request)) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
            ], $e->status);
        });

        /*
         * Validation keeps Laravel's {message, errors} body — the frontend still
         * gets per-field messages — and gains the code. Login failures and
         * lockouts are validation exceptions too, and are told apart by type
         * rather than by sniffing the message text.
         */
        $exceptions->render(function (ValidationException $e, Request $request) use ($wantsJson): ?JsonResponse {
            if (! $wantsJson($request)) {
                return null;
            }

            $reason = match (true) {
                $e instanceof InvalidCredentialsException => InvalidCredentialsException::REASON,
                $e instanceof TooManyAttemptsException => TooManyAttemptsException::REASON,
                default => 'validation_error',
            };

            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $reason,
                'errors' => $e->errors(),
            ], $e->status);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($wantsJson): ?JsonResponse {
            if (! $wantsJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Authentication is required.',
                'reason' => 'unauthenticated',
            ], 401);
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($wantsJson): ?JsonResponse {
            if (! $wantsJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Too many attempts. Please wait and try again.',
                'reason' => 'too_many_attempts',
            ], 429)->withHeaders($e->getHeaders());
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($wantsJson): ?JsonResponse {
            if (! $wantsJson($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Not found.',
                'reason' => 'not_found',
            ], 404);
        });

        /*
         * A database failure must never reach a contestant as SQL. This handler
         * is unconditional — it does not consult APP_DEBUG — so a mis-set
         * production flag cannot turn an outage into a schema disclosure. The
         * real exception is still logged.
         */
        $exceptions->render(function (QueryException $e, Request $request) use ($wantsJson): ?JsonResponse {
            if (! $wantsJson($request)) {
                return null;
            }

            report($e);

            return response()->json([
                'message' => 'A server error occurred. Please try again.',
                'reason' => 'server_error',
            ], 500);
        });
    })->create();
