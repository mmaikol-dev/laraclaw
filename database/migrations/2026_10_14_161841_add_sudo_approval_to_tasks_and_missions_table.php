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
        foreach (['tasks', 'missions'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('sudo_status', 20)->default('none');
                $blueprint->text('sudo_reason')->nullable();
                $blueprint->timestamp('sudo_requested_at')->nullable();
                $blueprint->timestamp('sudo_resolved_at')->nullable();
                $blueprint->unsignedBigInteger('sudo_resolved_by')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['tasks', 'missions'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn([
                    'sudo_status',
                    'sudo_reason',
                    'sudo_requested_at',
                    'sudo_resolved_at',
                    'sudo_resolved_by',
                ]);
            });
        }
    }
};
