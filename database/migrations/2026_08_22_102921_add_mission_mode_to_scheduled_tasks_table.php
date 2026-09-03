<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_tasks', function (Blueprint $table): void {
            // Mission mode: each fire resumes/advances a linked mission instead
            // of running one ad-hoc agent conversation.
            $table->boolean('run_as_mission')->default(false)->after('use_same_conversation');
            $table->foreignId('mission_id')->nullable()->after('run_as_mission')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('mission_id');
            $table->dropColumn('run_as_mission');
        });
    }
};
