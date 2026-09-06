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
        Schema::table('video_media', function (Blueprint $table): void {
            $table->boolean('watermark_enabled')->default(false)->after('thumbnail_at_seconds');
            $table->string('watermark_path')->nullable()->after('watermark_enabled');
            $table->string('watermark_position')->nullable()->after('watermark_path');
            $table->decimal('watermark_opacity', 3, 2)->nullable()->after('watermark_position');
            $table->unsignedSmallInteger('watermark_margin')->nullable()->after('watermark_opacity');
            $table->decimal('watermark_scale_percent', 5, 2)->nullable()->after('watermark_margin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_media', function (Blueprint $table): void {
            $table->dropColumn([
                'watermark_enabled',
                'watermark_path',
                'watermark_position',
                'watermark_opacity',
                'watermark_margin',
                'watermark_scale_percent',
            ]);
        });
    }
};
