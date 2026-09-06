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
        Schema::create('video_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_media_id')
                ->constrained('video_media')
                ->cascadeOnDelete();
            $table->string('type')->index();
            $table->string('quality')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->decimal('progress_percent', 5, 2)->default(0);
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('playlist_path')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bandwidth')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('meta')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['video_media_id', 'type', 'quality']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_conversions');
    }
};
