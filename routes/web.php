<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\EmployeeController;
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

    // Employee (autonomous agent) page
    Route::get('employee', [EmployeeController::class, 'index'])->name('employee.index');
    Route::get('employee/overview', [EmployeeController::class, 'overview'])->name('employee.overview');
    Route::post('employee/scheduled-tasks/{scheduledTask}/run', [EmployeeController::class, 'runTask'])->name('employee.tasks.run');
    Route::patch('employee/scheduled-tasks/{scheduledTask}/toggle', [EmployeeController::class, 'toggleTask'])->name('employee.tasks.toggle');
    Route::delete('employee/scheduled-tasks/{scheduledTask}', [EmployeeController::class, 'deleteTask'])->name('employee.tasks.delete');
    Route::post('employee/projects/{project}/continue', [EmployeeController::class, 'continueProject'])->name('employee.projects.continue');
    Route::patch('employee/triggers/{trigger}/toggle', [EmployeeController::class, 'toggleTrigger'])->name('employee.triggers.toggle');
    Route::delete('employee/triggers/{trigger}', [EmployeeController::class, 'deleteTrigger'])->name('employee.triggers.delete');
    Route::delete('employee/memories/{agentMemory}', [EmployeeController::class, 'deleteMemory'])->name('employee.memories.delete');
});

require __DIR__.'/settings.php';
