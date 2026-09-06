<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Martin6363\FilamentVideoEngine\Enums\ConversionTypeEnum;
use Martin6363\FilamentVideoEngine\Enums\TranscodingStatusEnum;
use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;

/**
 * Individual derived asset (HLS rendition, master playlist, poster, …).
 *
 * @property int $id
 * @property int $video_media_id
 * @property ConversionTypeEnum $type
 * @property string|null $quality
 * @property TranscodingStatusEnum $status
 * @property float $progress_percent
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $playlist_path
 * @property int|null $width
 * @property int|null $height
 * @property int|null $bandwidth
 * @property int|null $size_bytes
 * @property int $attempts
 * @property array<string, mixed>|null $meta
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class VideoConversion extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'video_media_id',
        'type',
        'quality',
        'status',
        'progress_percent',
        'disk',
        'path',
        'playlist_path',
        'width',
        'height',
        'bandwidth',
        'size_bytes',
        'attempts',
        'meta',
        'error_message',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConversionTypeEnum::class,
            'status' => TranscodingStatusEnum::class,
            'progress_percent' => 'float',
            'width' => 'integer',
            'height' => 'integer',
            'bandwidth' => 'integer',
            'size_bytes' => 'integer',
            'attempts' => 'integer',
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Parent video media.
     *
     * @return BelongsTo<VideoMedia, $this>
     */
    public function videoMedia(): BelongsTo
    {
        return $this->belongsTo(VideoMedia::class);
    }

    /**
     * Resolve quality enum when set.
     */
    public function qualityEnum(): ?VideoQualityEnum
    {
        if ($this->quality === null || $this->quality === '') {
            return null;
        }

        return VideoQualityEnum::tryFrom($this->quality);
    }
}
