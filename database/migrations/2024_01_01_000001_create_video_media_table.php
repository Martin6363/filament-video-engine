<?php

declare(strict_types=1);

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
        Schema::create('video_media', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->nullableMorphs('videoable');
            $table->string('title')->nullable();
            $table->string('disk_input')->nullable();
            $table->string('disk_output')->nullable();
            $table->string('original_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->decimal('duration_seconds', 12, 3)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('master_playlist_path')->nullable();
            $table->string('poster_path')->nullable();
            $table->boolean('poster_is_custom')->default(false);
            $table->decimal('thumbnail_at_seconds', 10, 3)->nullable();
            $table->string('status')->default('pending')->index();
            $table->decimal('progress_percent', 5, 2)->default(0);
            $table->string('current_step')->nullable();
            $table->json('meta')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_media');
    }
};
