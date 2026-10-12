<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agent_run_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_profile_id')->nullable()->constrained('agent_role_profiles')->nullOnDelete();
            $table->string('source');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('intent')->nullable();
            $table->string('status');
            $table->string('verdict');
            $table->string('verdict_source')->default('none');
            $table->string('model')->nullable();
            $table->string('failure_type')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('tools_used')->nullable();
            $table->text('summary')->nullable();

            $table->index(['source', 'subject_type', 'subject_id']);
            $table->index(['role_profile_id', 'created_at']);
            $table->index('verdict');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_run_outcomes');
    }
};
