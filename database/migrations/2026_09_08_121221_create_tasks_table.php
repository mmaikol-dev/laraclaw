<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Core state
            $table->string('status', 20)->default('pending');
            $table->text('goal');
            $table->text('plan')->nullable();

            // Progress
            $table->unsignedInteger('current_step')->default(0);
            $table->unsignedInteger('total_steps')->default(0);

            // Execution state
            $table->json('execution_state')->nullable();

            // Attempts & retry
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->string('failure_type', 30)->nullable();
            $table->text('failure_reason')->nullable();

            // Heartbeat
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->unsignedInteger('heartbeat_interval_seconds')->default(90);

            // Tracking
            $table->text('last_action')->nullable();
            $table->text('last_result')->nullable();
            $table->text('last_error')->nullable();

            // Verification
            $table->string('verification_status', 20)->nullable();
            $table->json('acceptance_criteria')->nullable();
            $table->json('verification_results')->nullable();
            $table->timestamp('last_verified_at')->nullable();

            // Model routing
            $table->string('model')->nullable();
            $table->string('complexity_level', 20)->nullable();

            // Timestamps
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
