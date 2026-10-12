<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_messages', function (Blueprint $table) {
            $table->id();
            $table->string('thread_key')->nullable()->index();
            $table->foreignId('mission_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_agent', 64);
            $table->string('to_agent', 64)->nullable();
            $table->string('kind', 32)->default('discussion');
            $table->text('body');
            $table->timestamps();

            $table->index(['to_agent', 'created_at']);
            $table->index(['from_agent', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_messages');
    }
};
