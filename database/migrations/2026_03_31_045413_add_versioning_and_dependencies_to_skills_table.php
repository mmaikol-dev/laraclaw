<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('usage_count');
            $table->json('dependencies')->nullable()->after('version');
            $table->string('template')->nullable()->after('dependencies');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table): void {
            $table->dropColumn(['version', 'dependencies', 'template']);
        });
    }
};
