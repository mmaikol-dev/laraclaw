<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('title')->default('New conversation');
            $table->string('model')->default('glm-5:cloud');
            $table->json('system_prompt')->nullable();
            $table->unsignedInteger('total_tokens')->default(0);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant', 'tool', 'system']);
            $table->longText('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->json('tool_result')->nullable();
            $table->string('tool_name')->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->float('tokens_per_second')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();
        });

        Schema::create('task_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool_name');
            $table->json('tool_input');
            $table->longText('tool_output')->nullable();
            $table->enum('status', ['pending', 'running', 'success', 'error']);
            $table->string('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();
        });

        Schema::create('metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->float('tokens_per_second')->default(0);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_duration_ms')->default(0);
            $table->unsignedInteger('load_duration_ms')->default(0);
            $table->string('model');
            $table->string('tool_name')->nullable();
            $table->json('extra')->nullable();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('embedded_documents', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('filepath');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('chunk_count')->default(0);
            $table->boolean('is_indexed')->default(false);
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('embedding_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('embedded_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->json('embedding');
            $table->timestamps();
        });

        Schema::create('agent_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type');
            $table->string('label');
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_settings');
        Schema::dropIfExists('embedding_chunks');
        Schema::dropIfExists('embedded_documents');
        Schema::dropIfExists('metric_snapshots');
        Schema::dropIfExists('task_logs');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
