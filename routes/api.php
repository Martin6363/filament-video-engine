<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Martin6363\FilamentVideoEngine\Http\Controllers\Api\VideoApiController;
use Martin6363\FilamentVideoEngine\Http\Middleware\EnsureVideoAccess;

Route::middleware([EnsureVideoAccess::class])->group(function (): void {
    Route::get('videos/{uuid}/manifest', [VideoApiController::class, 'manifest'])
        ->name('filament-video-engine.api.manifest');

    Route::get('videos/{uuid}/qualities/{quality}', [VideoApiController::class, 'quality'])
        ->name('filament-video-engine.api.quality');

    Route::get('videos/{uuid}/progress', [VideoApiController::class, 'progress'])
        ->name('filament-video-engine.api.progress');
});
