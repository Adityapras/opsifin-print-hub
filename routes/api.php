<?php

use App\Http\Controllers\Agent\V1\ClaimJobsController;
use App\Http\Controllers\Agent\V1\CommandResultController;
use App\Http\Controllers\Agent\V1\DownloadDocumentController;
use App\Http\Controllers\Agent\V1\EnrollController;
use App\Http\Controllers\Agent\V1\HeartbeatController;
use App\Http\Controllers\Agent\V1\JobEventsController;
use Illuminate\Support\Facades\Route;

Route::prefix('agent/v1')->group(function (): void {
    Route::post('enroll', EnrollController::class)->middleware('throttle:agent-enroll');

    Route::middleware(['agent.auth', 'throttle:agent-api'])->group(function (): void {
        Route::middleware('agent.version')->group(function (): void {
            Route::post('heartbeat', HeartbeatController::class);
            Route::post('jobs/claim', ClaimJobsController::class);
            Route::post('jobs/{jobId}/events', JobEventsController::class);
        });

        Route::post('commands/{commandId}/result', CommandResultController::class);
    });

    Route::get('documents/{documentId}', DownloadDocumentController::class)
        ->middleware('signed')
        ->name('agent.v1.documents.show');
});
