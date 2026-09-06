<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Actions;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Martin6363\FilamentVideoEngine\Contracts\VideoStorageContract;
use Martin6363\FilamentVideoEngine\DTOs\ThumbnailOptionsDto;
use Martin6363\FilamentVideoEngine\Enums\ConversionTypeEnum;
use Martin6363\FilamentVideoEngine\Enums\TranscodingStatusEnum;
use Martin6363\FilamentVideoEngine\Models\VideoConversion;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Martin6363\FilamentVideoEngine\Services\Ffmpeg\FfmpegCommander;
use Martin6363\FilamentVideoEngine\Services\Ffmpeg\FfmpegProcessRunner;
use Martin6363\FilamentVideoEngine\Services\Transcoding\TemporaryWorkspace;

/**
 * Extract or store a poster image for a video.
 */
final class ExtractThumbnailAction
{
    public function __construct(
        private readonly FfmpegCommander $commander,
        private readonly FfmpegProcessRunner $runner,
        private readonly VideoStorageContract $storage,
    ) {}

    /**
     * Apply custom poster or extract a frame at the configured timestamp.
     */
    public function execute(VideoMedia $video, ThumbnailOptionsDto $options): VideoMedia
    {
        $outputDisk = (string) ($video->disk_output ?: $this->storage->outputDisk());
        $format = strtolower($options->format ?? (string) config('filament-video-engine.thumbnail.format', 'jpg'));
        $extension = $format === 'webp' ? 'webp' : 'jpg';
        $posterRelative = sprintf(
            '%s/%s/poster.%s',
            trim((string) config('filament-video-engine.paths.posters'), '/'),
            $video->uuid,
            $extension,
        );

        $workspace = new TemporaryWorkspace;

        try {
            if ($options->usesCustomPoster()) {
                $custom = (string) $options->customPosterPath;
                $contents = Storage::disk($this->storage->inputDisk())->exists($custom)
                    ? (string) Storage::disk($this->storage->inputDisk())->get($custom)
                    : (string) File::get($custom);

                $this->storage->put($outputDisk, $posterRelative, $contents);

                $video->update([
                    'poster_path' => $posterRelative,
                    'poster_is_custom' => true,
                ]);

                $type = ConversionTypeEnum::Poster;
            } else {
                $inputDisk = (string) ($video->disk_input ?: $this->storage->inputDisk());
                $localInput = $this->storage->localPath($inputDisk, (string) $video->original_path);
                $at = $options->extractAtSeconds
                    ?? (float) config('filament-video-engine.thumbnail.default_timestamp', 5.0);

                if ($video->duration_seconds !== null && $video->duration_seconds > 0 && $at >= $video->duration_seconds) {
                    $at = max(0.0, $video->duration_seconds * 0.1);
                }

                $tempDir = $workspace->makeDirectory('ve-thumb');
                $tempPoster = $tempDir.DIRECTORY_SEPARATOR.'poster.'.$extension;

                $this->runner->run(
                    $this->commander->thumbnailCommand($localInput, $tempPoster, $at, $format),
                );

                $this->storage->put($outputDisk, $posterRelative, (string) File::get($tempPoster));

                $video->update([
                    'poster_path' => $posterRelative,
                    'poster_is_custom' => false,
                    'thumbnail_at_seconds' => $at,
                ]);

                $type = ConversionTypeEnum::Thumbnail;
            }

            $size = null;

            try {
                $size = Storage::disk($outputDisk)->size($posterRelative);
            } catch (\Throwable) {
                //
            }

            VideoConversion::query()->updateOrCreate(
                [
                    'video_media_id' => $video->id,
                    'type' => $type,
                    'quality' => null,
                ],
                [
                    'status' => TranscodingStatusEnum::Completed,
                    'progress_percent' => 100,
                    'disk' => $outputDisk,
                    'path' => $posterRelative,
                    'size_bytes' => is_numeric($size) ? (int) $size : null,
                    'finished_at' => now(),
                    'error_message' => null,
                ],
            );
        } finally {
            $workspace->cleanup();
        }

        return $video->refresh();
    }
}
