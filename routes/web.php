<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\SkillController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('chat/{conversation}', [ChatController::class, 'show'])->name('chat.show');
    Route::inertia('files', 'files/index')->name('files.index');
    Route::inertia('tasks', 'tasks/index')->name('tasks.index');
    Route::get('skills', [SkillController::class, 'index'])->name('skills.index');
});

require __DIR__.'/settings.php';
