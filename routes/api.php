<?php

use App\Http\Controllers\Api\FullCaseController;
use App\Http\Controllers\Api\LegalChatController;
use Illuminate\Support\Facades\Route;

Route::get('/cases/{type}/{caseId}', [FullCaseController::class, 'show'])
    ->where('type', 'civil|administrative')
    ->where('caseId', '[0-9]+');

Route::prefix('chats')->group(function () {
    Route::get('/',                                 [LegalChatController::class, 'index']);
    Route::post('/',                                [LegalChatController::class, 'store']);
    Route::get('/{chat}',                           [LegalChatController::class, 'show']);
    Route::patch('/{chat}/title',                   [LegalChatController::class, 'updateTitle']);
    Route::delete('/{chat}',                        [LegalChatController::class, 'destroy']);
    Route::get('/{chat}/messages',                  [LegalChatController::class, 'messages']);
    Route::post('/{chat}/messages/stream',          [LegalChatController::class, 'streamMessage']);
    Route::post('/{chat}/messages',                 [LegalChatController::class, 'sendMessage']);
});
