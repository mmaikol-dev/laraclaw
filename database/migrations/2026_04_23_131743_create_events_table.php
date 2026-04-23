<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(function () {
                return Str::uuid()->toString();
            });
            $table->string('event_type', 50); // trigger, task, project, error, webhook
            $table->string('entity_type', 50)->nullable(); // trigger, scheduled_task, project
            $table->uuid('entity_id')->nullable(); // reference to related entity
            $table->string('title', 255)->nullable();
            $table->text('message')->nullable();
            $table->jsonb('data')->nullable(); // additional context/data
            $table->string('level', 20)->default('info'); // info, warning, error, success
            $table->jsonb('metadata')->nullable(); // source, user_id, etc.
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
