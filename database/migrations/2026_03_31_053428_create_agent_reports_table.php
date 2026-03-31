<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_reports', function (Blueprint $table): void {
            $table->id();
            $table->date('report_date');
            $table->enum('type', ['daily', 'project', 'task_summary', 'trigger'])->default('daily');
            $table->string('title');
            $table->text('content');
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_reports');
    }
};
