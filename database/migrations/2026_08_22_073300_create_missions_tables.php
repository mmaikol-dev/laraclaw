<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->text('goal');
            $table->enum('status', ['scoping', 'active', 'paused', 'completed', 'failed'])->default('scoping');
            // Droid Whispering: model per role
            $table->string('orchestrator_model')->nullable();
            $table->string('worker_model')->nullable();
            $table->string('validator_model')->nullable();
            // Validation Contract: assertions defining "done" before implementation
            $table->json('validation_contract')->nullable();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('current_feature_id')->nullable();
            $table->unsignedInteger('milestone_count')->default(0);
            $table->text('context_notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'current_feature_id']);
        });

        Schema::create('mission_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mission_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('milestone')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->enum('status', ['pending', 'in_progress', 'implemented', 'validated', 'blocked'])->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['mission_id', 'sort_order']);
        });

        Schema::create('mission_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mission_feature_id')->nullable()->constrained('mission_features')->nullOnDelete();
            $table->string('role', 20)->default('worker');
            $table->text('summary');
            $table->text('completed_work')->nullable();
            $table->text('undone_work')->nullable();
            $table->json('execution_log')->nullable();
            $table->text('discovered_issues')->nullable();
            $table->boolean('procedure_adhered')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_handoffs');
        Schema::dropIfExists('mission_features');
        Schema::dropIfExists('missions');
    }
};
