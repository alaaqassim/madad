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
    Route::post('login', [SessionController::class, 'store'])
        ->middleware(['guest', 'throttle:6,1'])
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
