<?php

use App\Http\Controllers\Api\FieldDeviceAuthController;
use App\Http\Controllers\Api\FieldSurveyApiController;
use App\Http\Middleware\AuthenticateFieldDevice;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => ['status' => 'ok', 'service' => config('app.name')]);
    Route::prefix('field')->group(function () {
        Route::post('/login', [FieldDeviceAuthController::class, 'login'])->middleware('throttle:30,1');
        Route::middleware([AuthenticateFieldDevice::class, 'throttle:120,1'])->group(function () {
            Route::post('/logout', [FieldDeviceAuthController::class, 'logout']);
            Route::get('/bootstrap', [FieldSurveyApiController::class, 'bootstrap']);
            Route::post('/surveys/sync', [FieldSurveyApiController::class, 'sync']);
            Route::post('/surveys/{clientUuid}/attachments', [FieldSurveyApiController::class, 'attachment'])->whereUuid('clientUuid');
            Route::get('/attachments/{clientUuid}', [FieldSurveyApiController::class, 'download'])->whereUuid('clientUuid');
        });
    });
});
