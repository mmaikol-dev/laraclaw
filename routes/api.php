<?php

use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('conversations', [ConversationController::class, 'index'])->name('api.conversations.index');
    Route::post('conversations', [ConversationController::class, 'store'])->name('api.conversations.store');
    Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->name('api.conversations.show');
    Route::get('conversations/{conversation}/stream', [ConversationController::class, 'stream'])->name('api.conversations.stream');
    Route::post('conversations/{conversation}/messages', [ConversationController::class, 'sendMessage'])->name('api.conversations.messages.store');
    Route::post('conversations/{conversation}/messages/stream', [ConversationController::class, 'streamMessage'])->name('api.conversations.messages.stream');
    Route::patch('conversations/{conversation}/title', [ConversationController::class, 'updateTitle'])->name('api.conversations.title.update');
    Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy'])->name('api.conversations.destroy');
    Route::get('files/browse', [FileController::class, 'browse'])->name('api.files.browse');
    Route::get('files/read', [FileController::class, 'read'])->name('api.files.read');
    Route::post('files/write', [FileController::class, 'write'])->name('api.files.write');
    Route::post('files/create', [FileController::class, 'createFile'])->name('api.files.create');
    Route::post('files/mkdir', [FileController::class, 'createDirectory'])->name('api.files.mkdir');
    Route::post('files/move', [FileController::class, 'move'])->name('api.files.move');
    Route::delete('files', [FileController::class, 'delete'])->name('api.files.delete');
    Route::get('tasks', [TaskController::class, 'index'])->name('api.tasks.index');
    Route::get('tasks/stats', [TaskController::class, 'stats'])->name('api.tasks.stats');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('api.tasks.show');
    Route::get('metrics', [MetricsController::class, 'index'])->name('api.metrics.index');
    Route::get('settings', [SettingsController::class, 'index'])->name('api.settings.index');
    Route::post('settings', [SettingsController::class, 'update'])->name('api.settings.update');
    Route::delete('settings/tasks', [SettingsController::class, 'clearTasks'])->name('api.settings.tasks.clear');
    Route::delete('settings/conversations', [SettingsController::class, 'archiveConversations'])->name('api.settings.conversations.archive');
});
