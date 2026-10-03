<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => ['status' => 'ok', 'service' => config('app.name')]);
});
