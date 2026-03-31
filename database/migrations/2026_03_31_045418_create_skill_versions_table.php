<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('instructions');
            $table->string('description');
            $table->string('changed_by')->default('agent');
            $table->string('change_note')->nullable();
            $table->timestamps();

            $table->unique(['skill_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_versions');
    }
};
