<?php

use App\Http\Controllers\Api\V1\ExtensionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - KomikoID Chrome Extension Backend
|--------------------------------------------------------------------------
*/

// API Version 1
Route::prefix('v1')->group(function () {

    // Chrome Extension endpoints
    Route::prefix('extension')->group(function () {
        Route::get('/health', [ExtensionController::class, 'health']);
        Route::post('/translate-page', [ExtensionController::class, 'translatePage']);
    });

});
