<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('missions', function (Blueprint $table): void {
            // Mission Control budget burn: tokens consumed across all role runs.
            $table->unsignedBigInteger('spent_tokens')->default(0)->after('context_notes');
        });
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table): void {
            $table->dropColumn('spent_tokens');
        });
    }
};
