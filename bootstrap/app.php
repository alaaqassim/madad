<?php

use App\Exceptions\ExamException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
        /*
         * ExamException carries a message that is safe to show a contestant and
         * a machine-readable reason the Vue client can branch on. Rendering it
         * here keeps every controller free of try/catch.
         */
        $exceptions->render(function (ExamException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'reason' => $e->reason,
                ], $e->status);
            }

            return null;
        });
    })->create();
