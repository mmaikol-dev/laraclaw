<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->text('waiting_reason')->nullable()->after('last_error');
            $table->timestamp('waiting_since')->nullable()->after('waiting_reason');
            $table->json('resume_condition')->nullable()->after('waiting_since');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['waiting_reason', 'waiting_since', 'resume_condition']);
        });
    }
};
