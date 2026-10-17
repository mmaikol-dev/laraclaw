<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();

            // Identity. Slug is the stable, unique, human-readable handle the
            // agent uses to refer to a clip ("funny-cat-reaction").
            $table->string('slug')->unique();
            $table->string('title')->nullable();
            $table->string('filename');

            // Location. Relative to the media root; platform mirrors the folder
            // (clipped / instagram / youtube / facebook).
            $table->string('path');
            $table->string('platform', 32)->nullable()->index();

            // Posting status: posted / unposted.
            $table->string('status', 20)->default('unposted')->index();
            $table->timestamp('posted_at')->nullable();

            // Where a clip came from and where it was published.
            $table->string('source')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('external_id')->nullable();
            $table->string('external_url')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
