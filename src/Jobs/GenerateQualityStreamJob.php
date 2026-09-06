<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Martin6363\FilamentVideoEngine\Contracts\VideoStorageContract;
use Martin6363\FilamentVideoEngine\DTOs\MediaProbeDto;
use Martin6363\FilamentVideoEngine\DTOs\WatermarkOptionsDto;
use Martin6363\FilamentVideoEngine\Enums\ConversionTypeEnum;
use Martin6363\FilamentVideoEngine\Enums\TranscodingStatusEnum;
use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;
use Martin6363\FilamentVideoEngine\Models\VideoConversion;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Martin6363\FilamentVideoEngine\Services\Ffmpeg\FfmpegCommander;
use Martin6363\FilamentVideoEngine\Services\Ffmpeg\FfmpegProcessRunner;
use Martin6363\FilamentVideoEngine\Services\Ffmpeg\FfmpegProgressParser;
use Martin6363\FilamentVideoEngine\Services\Hls\HlsPlaylistBuilder;
use Martin6363\FilamentVideoEngine\Services\Transcoding\TemporaryWorkspace;
use Martin6363\FilamentVideoEngine\Services\Transcoding\TranscodingProgressTracker;
use Martin6363\FilamentVideoEngine\Services\Watermark\WatermarkPathResolver;
use Martin6363\FilamentVideoEngine\Support\LicenseGate;
use Martin6363\FilamentVideoEngine\Support\RenditionDimensionResolver;
use Throwable;

/**
 * Rebuild a single HLS quality rendition and refresh the master playlist.
 */
final class GenerateQualityStreamJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $videoMediaId,
        public readonly string $quality,
    ) {}

    /**
     * Handle the job.
     */
    public function handle(
        FfmpegCommander $commander,
        FfmpegProcessRunner $runner,
        FfmpegProgressParser $progressParser,
        VideoStorageContract $storage,
        HlsPlaylistBuilder $playlistBuilder,
        TranscodingProgressTracker $tracker,
        WatermarkPathResolver $watermarkPathResolver,
    ): void {
        $video = VideoMedia::query()->with('conversions')->find($this->videoMediaId);

        if ($video === null || $video->original_path === null) {
            return;
        }

        if (! LicenseGate::allowOrFail($video)) {
            return;
        }

        $quality = VideoQualityEnum::fromLabel($this->quality);
        $outputDisk = (string) ($video->disk_output ?: $storage->outputDisk());
        $inputDisk = (string) ($video->disk_input ?: $storage->inputDisk());
        $workspace = new TemporaryWorkspace;

        $conversion = VideoConversion::query()->updateOrCreate(
            [
                'video_media_id' => $video->id,
                'type' => ConversionTypeEnum::HlsRendition,
                'quality' => $quality->value,
            ],
            [
                'status' => TranscodingStatusEnum::Processing,
                'progress_percent' => 0,
                'started_at' => now(),
                'error_message' => null,
                'finished_at' => null,
            ],
        );
        $conversion->increment('attempts');
        $conversion = $conversion->refresh();

        $tracker->videoProgress($video, 0, 'hls:'.$quality->value.':regen');

        try {
            $localInput = $storage->localPath($inputDisk, $video->original_path);
            $probeJson = $runner->run($commander->probeCommand($localInput));
            /** @var array<string, mixed> $probeData */
            $probeData = json_decode($probeJson, true, 512, JSON_THROW_ON_ERROR);
            $probe = MediaProbeDto::fromFfprobeJson($probeData);

            $workRoot = $workspace->makeDirectory('ve-q-'.$video->uuid.'-'.$quality->value);
            $segmentDir = $workRoot.DIRECTORY_SEPARATOR.$quality->value;
            File::ensureDirectoryExists($segmentDir);

            $playlistFile = $workRoot.DIRECTORY_SEPARATOR.$quality->value.'.m3u8';
            $segmentPattern = $segmentDir.DIRECTORY_SEPARATOR.'seg_%05d.ts';
            $segmentDuration = (int) config('filament-video-engine.hls.segment_duration', 6);
            $watermark = $video->watermarkOptions();
            $localWatermark = $watermarkPathResolver->resolve($watermark, $workspace);

            $runner->runWithProgress(
                $commander->hlsRenditionCommand(
                    inputPath: $localInput,
                    playlistPath: $playlistFile,
                    segmentPattern: $segmentPattern,
                    quality: $quality,
                    segmentDuration: $segmentDuration,
                    watermark: $watermark,
                    localWatermarkPath: $localWatermark,
                    hasAudio: $probe->hasAudio,
                    probe: $probe,
                ),
                function (float $elapsed) use ($conversion, $progressParser, $probe, $tracker, $video): void {
                    $tracker->conversionProgress(
                        $conversion,
                        $progressParser->percentOfDuration($elapsed, $probe->durationSeconds),
                    );
                    $tracker->syncVideoFromHlsConversions($video->refresh());
                },
            );

            $relativeBase = sprintf(
                '%s/%s',
                trim((string) config('filament-video-engine.paths.hls'), '/'),
                $video->uuid,
            );

            $variantName = $quality->value.'.m3u8';
            $playlistRelative = $relativeBase.'/'.$variantName;
            $playlistContents = $playlistBuilder->normalizeVariantPlaylist(
                (string) File::get($playlistFile),
                $quality->value,
            );
            $totalBytes = 0;

            $storage->put($outputDisk, $playlistRelative, $playlistContents);
            $totalBytes += strlen($playlistContents);

            foreach (File::files($segmentDir) as $file) {
                $relative = $relativeBase.'/'.$quality->value.'/'.$file->getFilename();
                $contents = (string) File::get($file->getPathname());
                $storage->put($outputDisk, $relative, $contents);
                $totalBytes += strlen($contents);
            }

            $width = RenditionDimensionResolver::targetWidth($quality, $probe);

            $conversion->forceFill([
                'status' => TranscodingStatusEnum::Completed,
                'progress_percent' => 100,
                'disk' => $outputDisk,
                'path' => $relativeBase.'/'.$quality->value,
                'playlist_path' => $playlistRelative,
                'width' => $width,
                'height' => $quality->height(),
                'bandwidth' => $quality->bandwidth(),
                'size_bytes' => $totalBytes,
                'finished_at' => now(),
                'error_message' => null,
            ])->save();

            $this->refreshMasterPlaylist($video->refresh()->load('conversions'), $storage, $playlistBuilder, $outputDisk, $tracker);
        } catch (Throwable $exception) {
            $conversion->forceFill([
                'status' => TranscodingStatusEnum::Failed,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            $tracker->syncVideoFromHlsConversions($video->refresh());

            Log::error('GenerateQualityStreamJob failed.', [
                'video_media_id' => $this->videoMediaId,
                'quality' => $this->quality,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            $workspace->cleanup();
        }
    }

    /**
     * Rebuild master.m3u8 from completed HLS renditions.
     */
    private function refreshMasterPlaylist(
        VideoMedia $video,
        VideoStorageContract $storage,
        HlsPlaylistBuilder $playlistBuilder,
        string $outputDisk,
        TranscodingProgressTracker $tracker,
    ): void {
        $renditions = [];

        foreach ($video->conversions as $conversion) {
            if ($conversion->type !== ConversionTypeEnum::HlsRendition || $conversion->playlist_path === null) {
                continue;
            }

            if ($conversion->status !== TranscodingStatusEnum::Completed) {
                continue;
            }

            $quality = $conversion->qualityEnum();

            if ($quality === null) {
                continue;
            }

            $renditions[] = [
                'quality' => $quality,
                'variant' => $quality->value.'.m3u8',
                'bandwidth' => (int) ($conversion->bandwidth ?? $quality->bandwidth()),
                'width' => (int) ($conversion->width ?? $quality->maxWidth()),
                'height' => (int) ($conversion->height ?? $quality->height()),
            ];
        }

        if ($renditions === []) {
            return;
        }

        $relative = sprintf(
            '%s/%s/master.m3u8',
            trim((string) config('filament-video-engine.paths.hls'), '/'),
            $video->uuid,
        );

        $storage->put($outputDisk, $relative, $playlistBuilder->buildMaster($renditions));

        $video->update([
            'master_playlist_path' => $relative,
        ]);

        $tracker->syncVideoFromHlsConversions($video->refresh());
    }
}
