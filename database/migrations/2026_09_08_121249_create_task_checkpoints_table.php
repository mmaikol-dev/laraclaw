<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('step_sort_order')->nullable();

            // What happened
            $table->text('action_taken');
            $table->text('action_result')->nullable();

            // Agent state snapshot
            $table->string('task_status', 20)->default('running');
            $table->unsignedInteger('current_step')->default(0);
            $table->json('execution_state')->nullable();

            // Context for resume
            $table->text('summary')->nullable();
            $table->json('tool_calls_summary')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_checkpoints');
    }
};
