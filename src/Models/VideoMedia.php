<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Martin6363\FilamentVideoEngine\DTOs\WatermarkOptionsDto;
use Martin6363\FilamentVideoEngine\Enums\ConversionTypeEnum;
use Martin6363\FilamentVideoEngine\Enums\TranscodingStatusEnum;
use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;
use Martin6363\FilamentVideoEngine\Support\QualityLadderPlanner;

/**
 * Polymorphic video media record with HLS outputs and poster metadata.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $videoable_type
 * @property int|null $videoable_id
 * @property string|null $title
 * @property string|null $disk_input
 * @property string|null $disk_output
 * @property string|null $original_path
 * @property string|null $original_filename
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property float|null $duration_seconds
 * @property int|null $width
 * @property int|null $height
 * @property string|null $master_playlist_path
 * @property string|null $poster_path
 * @property bool $poster_is_custom
 * @property float|null $thumbnail_at_seconds
 * @property bool $watermark_enabled
 * @property string|null $watermark_path
 * @property string|null $watermark_position
 * @property float|null $watermark_opacity
 * @property int|null $watermark_margin
 * @property float|null $watermark_scale_percent
 * @property TranscodingStatusEnum $status
 * @property float $progress_percent
 * @property string|null $current_step
 * @property array<string, mixed>|null $meta
 * @property string|null $error_message
 * @property Carbon|null $processed_at
 */
class VideoMedia extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'videoable_type',
        'videoable_id',
        'title',
        'disk_input',
        'disk_output',
        'original_path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'duration_seconds',
        'width',
        'height',
        'master_playlist_path',
        'poster_path',
        'poster_is_custom',
        'thumbnail_at_seconds',
        'watermark_enabled',
        'watermark_path',
        'watermark_position',
        'watermark_opacity',
        'watermark_margin',
        'watermark_scale_percent',
        'status',
        'progress_percent',
        'current_step',
        'meta',
        'error_message',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TranscodingStatusEnum::class,
            'poster_is_custom' => 'boolean',
            'watermark_enabled' => 'boolean',
            'duration_seconds' => 'float',
            'thumbnail_at_seconds' => 'float',
            'watermark_opacity' => 'float',
            'watermark_margin' => 'integer',
            'watermark_scale_percent' => 'float',
            'progress_percent' => 'float',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'meta' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Boot UUID assignment.
     */
    protected static function booted(): void
    {
        static::creating(function (self $media): void {
            if ($media->uuid === null || $media->uuid === '') {
                $media->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Owning polymorphic model (Movie, Lesson, Post, Episode, …).
     *
     * @return MorphTo<Model, $this>
     */
    public function videoable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Derived conversion / rendition records.
     *
     * @return HasMany<VideoConversion, $this>
     */
    public function conversions(): HasMany
    {
        return $this->hasMany(VideoConversion::class);
    }

    /**
     * Whether a master HLS playlist path is present.
     */
    public function hasHlsStream(): bool
    {
        return $this->master_playlist_path !== null
            && $this->master_playlist_path !== ''
            && in_array($this->status, [TranscodingStatusEnum::Completed, TranscodingStatusEnum::Partial], true);
    }

    /**
     * Public poster image URL when a poster path exists.
     */
    public function posterPublicUrl(): ?string
    {
        if ($this->poster_path === null || $this->poster_path === '') {
            return null;
        }

        return app(\Martin6363\FilamentVideoEngine\Services\Storage\VideoStorageManager::class)->publicUrl(
            (string) ($this->disk_output ?: config('filament-video-engine.disks.output', 'public')),
            $this->poster_path,
        );
    }

    /**
     * Find a completed conversion for a given quality.
     */
    public function conversionForQuality(VideoQualityEnum|string $quality): ?VideoConversion
    {
        $label = $quality instanceof VideoQualityEnum ? $quality->value : $quality;

        return $this->conversions
            ->where('type', ConversionTypeEnum::HlsRendition)
            ->where('quality', $label)
            ->first();
    }

    /**
     * Human-readable source resolution, e.g. "480p (854x480)".
     */
    public function sourceResolutionLabel(): ?string
    {
        $fromMeta = $this->meta['source_resolution'] ?? null;

        if (is_string($fromMeta) && $fromMeta !== '') {
            return $fromMeta;
        }

        return app(QualityLadderPlanner::class)
            ->sourceResolutionLabel($this->width, $this->height);
    }

    /**
     * Short source quality badge label, e.g. "480p".
     */
    public function sourceQualityBadge(): ?string
    {
        return app(QualityLadderPlanner::class)
            ->sourceQualityBadge($this->height);
    }

    /**
     * Qualities skipped because the source was lower resolution.
     *
     * @return list<string>
     */
    public function skippedQualities(): array
    {
        $skipped = $this->meta['skipped_qualities'] ?? [];

        if (! is_array($skipped)) {
            return [];
        }

        return array_values(array_filter(
            $skipped,
            static fn (mixed $label): bool => is_string($label) && $label !== '',
        ));
    }

    /**
     * Machine skip reason stored in meta (e.g. source_resolution_lower).
     */
    public function skipReason(): ?string
    {
        $reason = $this->meta['skip_reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * Admin-facing explanation when higher targets were auto-skipped.
     */
    public function skippedQualitiesNotice(): ?string
    {
        $skipped = $this->skippedQualities();

        if ($skipped === []) {
            return null;
        }

        $source = $this->sourceQualityBadge()
            ?? $this->sourceResolutionLabel()
            ?? 'unknown';

        return __('filament-video-engine::messages.resource.helpers.skipped_qualities', [
            'source' => $source,
            'qualities' => implode(', ', $skipped),
        ]);
    }

    /**
     * Whether a target quality can be encoded from the probed source resolution.
     */
    public function canEncodeQuality(VideoQualityEnum|string $quality, bool $skipUpscale = true): bool
    {
        $enum = $quality instanceof VideoQualityEnum
            ? $quality
            : VideoQualityEnum::tryFrom(strtolower(trim((string) $quality)));

        if ($enum === null) {
            return false;
        }

        return app(QualityLadderPlanner::class)
            ->isQualityAllowedForSource($enum, $this->height, $skipUpscale);
    }

    /**
     * Effective watermark options for this video (per-video overrides + config fallback).
     */
    public function watermarkOptions(): WatermarkOptionsDto
    {
        return WatermarkOptionsDto::forVideo($this);
    }

    /**
     * Route key for API lookups.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
