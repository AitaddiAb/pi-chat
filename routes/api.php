<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/chat/sessions', [ChatController::class, 'sessions']);
    Route::post('/chat/sessions/claim', [ChatController::class, 'claim']);
    Route::get('/chat/sessions/{session}/messages', [ChatController::class, 'messages']);
    Route::post('/chat/sessions/{session}/messages', [ChatController::class, 'store']);
});
