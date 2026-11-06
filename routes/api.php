<?php

use App\Http\Controllers\Api\ApiController;
use App\Http\Middleware\ApiAuth;
use Illuminate\Support\Facades\Route;

// JSON API for other tools and scripts (docs/api.md): bearer tokens from the Staff page, JSON in and out.
Route::middleware([ApiAuth::class, 'throttle:api'])->group(function () { // 120 calls a minute per token (App\Http\RateLimits)
    Route::get('/clients', [ApiController::class, 'clients']);
    Route::get('/clients/{client}/posts', [ApiController::class, 'posts']);
    Route::post('/clients/{client}/posts', [ApiController::class, 'storePost']);
    Route::get('/posts/{post}', [ApiController::class, 'post']);
    Route::middleware('feature:publishing')->group(function () {
        Route::get('/clients/{client}/comments', [ApiController::class, 'comments']);
        Route::post('/comments/{comment}/reply', [ApiController::class, 'reply']);
    });
});
