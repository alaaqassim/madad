<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Exam\ExamController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('app');
});

/*
|--------------------------------------------------------------------------
| Contestant API
|--------------------------------------------------------------------------
|
| Session-authenticated JSON, consumed by the Vue front end. Nothing here
| accepts a competition id or a participation id: the backend resolves the
| competition, and resolves the participation from the authenticated user.
|
| Operational actions — question import, provisioning, credential retries,
| opening and closing the portal, result extraction — are deliberately NOT
| routed. They are artisan commands (madad:*), so there is no public surface
| to defend.
|
*/

Route::prefix('api')->name('api.')->group(function (): void {
    /*
     * Two limits guard this route, and they do different jobs.
     *
     * LoginRequest holds the one that stops password guessing: five attempts
     * keyed by email and address, counted only on failure and cleared the
     * moment a login succeeds.
     *
     * This one is keyed by address alone and counts every request, successful
     * or not - so it is a flood limit, not an attempt limit, and it must be set
     * for a shared address. Contestants sit exams in halls, on university
     * networks and behind carrier NAT, where hundreds of them are one address
     * to us. The busiest minute of the whole competition is the one where they
     * all log in at once; a low number here would lock out everyone after the
     * first few and take the competition down at exactly the wrong moment.
     *
     * 300 a minute is far above any hall of contestants and far below anything
     * a script would do.
     */
    Route::post('login', [SessionController::class, 'store'])
        ->middleware(['guest', 'throttle:300,1'])
        ->name('login');

    // Public so a closed portal can say so without forcing a login first.
    Route::get('competition/status', [ExamController::class, 'status'])->name('competition.status');

    Route::middleware('auth')->group(function (): void {
        Route::post('logout', [SessionController::class, 'destroy'])->name('logout');

        Route::prefix('exam')->name('exam.')->group(function (): void {
            Route::post('start', [ExamController::class, 'start'])->name('start');
            Route::get('current', [ExamController::class, 'currentQuestion'])->name('current');
            Route::post('answer', [ExamController::class, 'submit'])
                ->middleware('throttle:120,1')
                ->name('answer');
            Route::get('result', [ExamController::class, 'result'])->name('result');
        });
    });
});
