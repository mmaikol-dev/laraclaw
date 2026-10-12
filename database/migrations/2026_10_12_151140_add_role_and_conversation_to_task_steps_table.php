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
        Schema::table('task_steps', function (Blueprint $table) {
            $table->foreignId('role_profile_id')->nullable()->after('task_id')->constrained('agent_role_profiles')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->after('role_profile_id')->constrained('conversations')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conversation_id');
            $table->dropConstrainedForeignId('role_profile_id');
        });
    }
};
