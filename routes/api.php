<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\FullCaseController;
use App\Http\Controllers\Api\LegalAnswerReviewController;
use App\Http\Controllers\Api\LegalChatController;
use Illuminate\Support\Facades\Route;

// ── Auth ──────────────────────────────────────────────────────────────────────
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login',    [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);
});

// ── Cases (public read) ───────────────────────────────────────────────────────
Route::get('/cases/{type}/{caseId}', [FullCaseController::class, 'show'])
    ->where('type', 'civil|administrative')
    ->where('caseId', '[0-9]+');

Route::get('/cases/{caseId}', [FullCaseController::class, 'showById'])
    ->where('caseId', '[0-9]+');

Route::middleware('auth:sanctum')->prefix('chats')->group(function () {
    Route::get('/',                                 [LegalChatController::class, 'index']);
    Route::post('/',                                [LegalChatController::class, 'store']);
    Route::get('/{chat}',                           [LegalChatController::class, 'show']);
    Route::patch('/{chat}/title',                   [LegalChatController::class, 'updateTitle']);
    Route::delete('/{chat}',                        [LegalChatController::class, 'destroy']);
    Route::get('/{chat}/messages',                  [LegalChatController::class, 'messages']);
    Route::post('/{chat}/messages/stream',          [LegalChatController::class, 'streamMessage'])
        ->middleware('throttle:chat-stream');
    Route::post('/{chat}/messages',                 [LegalChatController::class, 'sendMessage'])
        ->middleware('throttle:chat-stream');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/messages/{message}/review', [LegalAnswerReviewController::class, 'show']);
    Route::post('/messages/{message}/review', [LegalAnswerReviewController::class, 'store']);

    Route::get('/admin/users', [AdminUserController::class, 'index']);
    Route::post('/admin/users', [AdminUserController::class, 'store']);
});
