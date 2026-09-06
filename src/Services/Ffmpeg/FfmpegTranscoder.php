<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Services\Ffmpeg;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Martin6363\FilamentVideoEngine\Contracts\TranscoderContract;
use Martin6363\FilamentVideoEngine\Contracts\VideoStorageContract;
use Martin6363\FilamentVideoEngine\DTOs\MediaProbeDto;
use Martin6363\FilamentVideoEngine\DTOs\TranscodingOptionsDto;
use Martin6363\FilamentVideoEngine\DTOs\WatermarkOptionsDto;
use Martin6363\FilamentVideoEngine\Enums\ConversionTypeEnum;
use Martin6363\FilamentVideoEngine\Enums\TranscodingStatusEnum;
use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;
use Martin6363\FilamentVideoEngine\Exceptions\FfmpegException;
use Martin6363\FilamentVideoEngine\Models\VideoConversion;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Martin6363\FilamentVideoEngine\Services\Hls\HlsEncryptor;
use Martin6363\FilamentVideoEngine\Services\Hls\HlsPlaylistBuilder;
use Martin6363\FilamentVideoEngine\Services\Transcoding\TemporaryWorkspace;
use Martin6363\FilamentVideoEngine\Services\Transcoding\TranscodingProgressTracker;
use Martin6363\FilamentVideoEngine\Services\Watermark\WatermarkPathResolver;
use Martin6363\FilamentVideoEngine\Support\QualityLadderPlanner;
use Martin6363\FilamentVideoEngine\Support\RenditionDimensionResolver;
use Throwable;

/**
 * FFmpeg-backed transcoder: probe, poster, multi-bitrate HLS, master playlist.
 */
final class FfmpegTranscoder implements TranscoderContract
{
    public function __construct(
        private readonly FfmpegBinaryResolver $binaries,
        private readonly FfmpegCommander $commander,
        private readonly FfmpegProcessRunner $runner,
        private readonly FfmpegProgressParser $progressParser,
        private readonly VideoStorageContract $storage,
        private readonly HlsPlaylistBuilder $playlistBuilder,
        private readonly HlsEncryptor $encryptor,
        private readonly TranscodingProgressTracker $tracker,
        private readonly QualityLadderPlanner $ladderPlanner,
        private readonly WatermarkPathResolver $watermarkPathResolver,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        if (! (bool) config('filament-video-engine.ffmpeg.enabled', true)) {
            return false;
        }

        return $this->binaries->isAvailable();
    }

    /**
     * {@inheritdoc}
     */
    public function transcode(VideoMedia $video, TranscodingOptionsDto $options): VideoMedia
    {
        if (! $this->isAvailable()) {
            throw FfmpegException::binaryMissing('ffmpeg');
        }

        $workspace = new TemporaryWorkspace;
        $inputDisk = (string) ($video->disk_input ?: $this->storage->inputDisk());
        $outputDisk = (string) ($video->disk_output ?: $this->storage->outputDisk());
        $downloadedTemp = false;

        $this->tracker->videoProgress($video, 0, 'starting');

        try {
            $localInput = $this->resolveLocalInput($video, $inputDisk, $workspace, $downloadedTemp);

            $probe = $this->probeAndPersist($video, $localInput);
            $this->tracker->videoProgress($video, 5, 'probe');

            $this->handlePoster($video, $localInput, $options, $outputDisk, $workspace);
            $this->tracker->videoProgress($video, 10, 'poster');

            $encodeResult = $this->transcodeQualities(
                $video,
                $localInput,
                $options,
                $outputDisk,
                $workspace,
                $probe,
            );

            $renditions = $encodeResult['renditions'];

            if ($renditions === []) {
                throw new FfmpegException('No HLS renditions were produced (all qualities skipped or failed).');
            }

            $masterRelative = $this->writeMasterPlaylist($video, $renditions, $outputDisk);
            $this->tracker->videoProgress($video, 98, 'master_playlist');

            $status = $this->ladderPlanner->resolveStatus(
                plannedCount: count($encodeResult['planned']),
                producedCount: count($renditions),
                failedCount: $encodeResult['failed_count'],
            );

            $sourceResolution = $this->ladderPlanner->sourceResolutionLabel($probe->width, $probe->height);
            $skippedLabels = array_map(
                static fn (VideoQualityEnum $quality): string => $quality->value,
                $encodeResult['skipped'],
            );

            $meta = array_merge($video->meta ?? [], [
                'qualities' => array_map(
                    static fn (array $r): string => $r['quality']->value,
                    $renditions,
                ),
                'skipped_qualities' => $skippedLabels,
                'skip_reason' => $encodeResult['skip_reason'],
                'source_resolution' => $sourceResolution,
                'probe' => [
                    'duration_seconds' => $probe->durationSeconds,
                    'width' => $probe->width,
                    'height' => $probe->height,
                    'bitrate' => $probe->bitrate,
                    'video_codec' => $probe->videoCodec,
                    'audio_codec' => $probe->audioCodec,
                ],
            ]);

            if ($skippedLabels === []) {
                unset($meta['skipped_qualities'], $meta['skip_reason']);
            }

            $this->tracker->updateVideo($video, [
                'master_playlist_path' => $masterRelative,
                'status' => $status,
                'progress_percent' => 100,
                'current_step' => 'done',
                'error_message' => $encodeResult['failed_count'] > 0
                    ? __('filament-video-engine::messages.transcoding.partial_qualities_failed')
                    : null,
                'processed_at' => now(),
                'meta' => $meta,
            ]);
        } catch (Throwable $exception) {
            $this->markFailed($video, $exception);
            $this->cleanupInputArtifacts($video, $inputDisk, $downloadedTemp);

            throw $exception;
        } finally {
            $workspace->cleanup();
        }

        return $video->refresh()->load('conversions');
    }

    /**
     * Resolve a local filesystem path for the original, tracking remote downloads.
     */
    private function resolveLocalInput(
        VideoMedia $video,
        string $inputDisk,
        TemporaryWorkspace $workspace,
        bool &$downloadedTemp,
    ): string {
        if ($video->original_path === null || $video->original_path === '') {
            throw new FfmpegException('Video media has no original_path to transcode.');
        }

        $before = sys_get_temp_dir();
        $local = $this->storage->localPath($inputDisk, $video->original_path);

        // VideoStorageManager downloads non-local disks into sys temp.
        if (str_starts_with($local, $before) && ! $this->isNativeLocalDiskFile($inputDisk, $video->original_path, $local)) {
            $workspace->trackFile($local);
            $downloadedTemp = true;
        }

        return $local;
    }

    /**
     * Whether localPath returned the disk's native path (not a temp copy).
     */
    private function isNativeLocalDiskFile(string $disk, string $relative, string $local): bool
    {
        try {
            $native = Storage::disk($disk)->path($relative);

            return is_string($native) && realpath($native) === realpath($local);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Probe source media and persist duration / resolution.
     */
    private function probeAndPersist(VideoMedia $video, string $localInput): MediaProbeDto
    {
        $json = $this->runner->run($this->commander->probeCommand($localInput));
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $probe = MediaProbeDto::fromFfprobeJson($data);

        $this->tracker->updateVideo($video, [
            'duration_seconds' => $probe->durationSeconds,
            'width' => $probe->width,
            'height' => $probe->height,
            'meta' => array_merge($video->meta ?? [], [
                'source_bitrate' => $probe->bitrate,
                'source_video_codec' => $probe->videoCodec,
                'source_audio_codec' => $probe->audioCodec,
                'source_frame_rate' => $probe->frameRate,
                'has_audio' => $probe->hasAudio,
                'source_resolution' => $this->ladderPlanner->sourceResolutionLabel($probe->width, $probe->height),
            ]),
        ]);

        return $probe;
    }

    /**
     * Prefer custom poster upload; otherwise extract a frame at the configured timestamp.
     */
    private function handlePoster(
        VideoMedia $video,
        string $localInput,
        TranscodingOptionsDto $options,
        string $outputDisk,
        TemporaryWorkspace $workspace,
    ): void {
        $format = strtolower($options->thumbnail->format ?? (string) config('filament-video-engine.thumbnail.format', 'jpg'));
        $posterRelative = sprintf(
            '%s/%s/poster.%s',
            trim((string) config('filament-video-engine.paths.posters'), '/'),
            $video->uuid,
            $format === 'webp' ? 'webp' : 'jpg',
        );

        $conversion = $this->beginConversion(
            $video,
            $options->thumbnail->usesCustomPoster() ? ConversionTypeEnum::Poster : ConversionTypeEnum::Thumbnail,
            null,
        );

        try {
            if ($options->thumbnail->usesCustomPoster()) {
                $custom = (string) $options->thumbnail->customPosterPath;
                $contents = $this->readCustomPoster($custom);
                $this->storage->put($outputDisk, $posterRelative, $contents);

                $this->tracker->updateVideo($video, [
                    'poster_path' => $posterRelative,
                    'poster_is_custom' => true,
                ]);
            } else {
                $at = $options->thumbnail->extractAtSeconds
                    ?? (float) config('filament-video-engine.thumbnail.default_timestamp', 5.0);

                if ($video->duration_seconds !== null && $video->duration_seconds > 0 && $at >= $video->duration_seconds) {
                    $at = max(0.0, $video->duration_seconds * 0.1);
                }

                $tempDir = $workspace->makeDirectory('ve-poster');
                $tempPoster = $workspace->trackFile(
                    $tempDir.DIRECTORY_SEPARATOR.'poster.'.($format === 'webp' ? 'webp' : 'jpg'),
                );

                $this->runner->run(
                    $this->commander->thumbnailCommand($localInput, $tempPoster, $at, $format),
                );

                $this->storage->put($outputDisk, $posterRelative, (string) File::get($tempPoster));

                $this->tracker->updateVideo($video, [
                    'poster_path' => $posterRelative,
                    'poster_is_custom' => false,
                    'thumbnail_at_seconds' => $at,
                ]);
            }

            $size = $this->remoteSize($outputDisk, $posterRelative);

            $this->completeConversion($conversion, $outputDisk, $posterRelative, null, null, null, null, $size);
        } catch (Throwable $exception) {
            $this->failConversion($conversion, $exception);

            throw $exception;
        }
    }

    /**
     * Read a custom poster from a storage-relative path or absolute filesystem path.
     */
    private function readCustomPoster(string $custom): string
    {
        $outputDisk = $this->storage->outputDisk();
        $inputDisk = $this->storage->inputDisk();

        if (Storage::disk($outputDisk)->exists($custom)) {
            return (string) Storage::disk($outputDisk)->get($custom);
        }

        if (Storage::disk($inputDisk)->exists($custom)) {
            return (string) Storage::disk($inputDisk)->get($custom);
        }

        if (is_file($custom)) {
            return (string) File::get($custom);
        }

        throw new FfmpegException(sprintf('Custom poster not found: %s', $custom));
    }

    /**
     * Transcode each planned quality into `{quality}.m3u8` + `.ts` segments.
     *
     * Higher-than-source targets are skipped when skip-upscale is enabled.
     * Individual encode failures are recorded; remaining qualities continue so
     * a Partial status can be returned when at least one rendition succeeds.
     *
     * @return array{
     *     renditions: list<array{quality: VideoQualityEnum, variant: string, playlist: string, bandwidth: int, width: int, height: int}>,
     *     planned: list<VideoQualityEnum>,
     *     skipped: list<VideoQualityEnum>,
     *     skip_reason: string|null,
     *     failed_count: int
     * }
     */
    private function transcodeQualities(
        VideoMedia $video,
        string $localInput,
        TranscodingOptionsDto $options,
        string $outputDisk,
        TemporaryWorkspace $workspace,
        MediaProbeDto $probe,
    ): array {
        $qualities = $options->qualities !== [] ? $options->qualities : VideoQualityEnum::defaults();
        $sourceHeight = $video->height ?? $probe->height;
        $plan = $this->ladderPlanner->plan($qualities, $sourceHeight, $options->skipUpscale);
        $planned = $plan['planned'];
        $skipped = $plan['skipped'];

        if ($skipped !== []) {
            $this->logger()->info('Filament Video Engine skipped higher qualities (no upscale).', [
                'video_media_id' => $video->id,
                'uuid' => $video->uuid,
                'source_height' => $sourceHeight,
                'skipped' => array_map(static fn (VideoQualityEnum $q): string => $q->value, $skipped),
                'planned' => array_map(static fn (VideoQualityEnum $q): string => $q->value, $planned),
            ]);
        }

        if ($planned === []) {
            return [
                'renditions' => [],
                'planned' => [],
                'skipped' => $skipped,
                'skip_reason' => $plan['skip_reason'],
                'failed_count' => 0,
            ];
        }

        $workRoot = $workspace->makeDirectory('ve-hls-'.$video->uuid);
        $keyInfo = null;

        if ($options->hls->encryptionEnabled) {
            $keyInfo = $this->encryptor->createKeyInfoFile($video, $workRoot);
        }

        $localWatermark = $this->watermarkPathResolver->resolve($options->watermark, $workspace);
        $renditions = [];
        $failedCount = 0;
        $bandStart = 10.0;
        $bandSpan = 85.0;
        $perQuality = $bandSpan / count($planned);

        foreach ($planned as $index => $quality) {
            $qualityBandStart = $bandStart + ($index * $perQuality);
            $qualityBandEnd = $qualityBandStart + $perQuality;

            $this->tracker->videoProgress($video, $qualityBandStart, 'hls:'.$quality->value);

            $conversion = $this->beginConversion($video, ConversionTypeEnum::HlsRendition, $quality->value);
            $conversion->forceFill([
                'width' => RenditionDimensionResolver::targetWidth($quality, $probe),
                'height' => $quality->height(),
                'bandwidth' => $quality->bandwidth(),
                'meta' => [
                    'max_bitrate' => $quality->maxBitrate(),
                    'audio_bitrate' => $quality->audioBitrate(),
                ],
            ])->save();

            try {
                $segmentDir = $workRoot.DIRECTORY_SEPARATOR.$quality->value;
                File::ensureDirectoryExists($segmentDir);

                $playlistFile = $workRoot.DIRECTORY_SEPARATOR.$quality->value.'.m3u8';
                $segmentPattern = $segmentDir.DIRECTORY_SEPARATOR.'seg_%05d.ts';

                $this->runner->runWithProgress(
                    $this->commander->hlsRenditionCommand(
                        inputPath: $localInput,
                        playlistPath: $playlistFile,
                        segmentPattern: $segmentPattern,
                        quality: $quality,
                        segmentDuration: $options->hls->segmentDuration,
                        watermark: $options->watermark,
                        localWatermarkPath: $localWatermark,
                        encryptionKeyInfo: $keyInfo,
                        hasAudio: $probe->hasAudio,
                        probe: $probe,
                    ),
                    function (float $elapsed) use ($video, $conversion, $probe, $qualityBandStart, $qualityBandEnd): void {
                        $local = $this->progressParser->percentOfDuration($elapsed, $probe->durationSeconds);
                        $overall = $this->tracker->mapBand($local, $qualityBandStart, $qualityBandEnd);

                        $this->tracker->conversionProgress($conversion, $local);
                        $this->tracker->videoProgress($video, $overall, 'hls:'.$conversion->quality);
                    },
                );

                if (! is_file($playlistFile)) {
                    throw new FfmpegException(sprintf('Variant playlist missing after encode: %s', $playlistFile));
                }

                $relativeBase = sprintf(
                    '%s/%s',
                    trim((string) config('filament-video-engine.paths.hls'), '/'),
                    $video->uuid,
                );

                $variantName = $quality->value.'.m3u8';
                $playlistRelative = $relativeBase.'/'.$variantName;
                $playlistContents = $this->playlistBuilder->normalizeVariantPlaylist(
                    (string) File::get($playlistFile),
                    $quality->value,
                );
                $totalBytes = 0;

                $this->storage->put($outputDisk, $playlistRelative, $playlistContents);
                $totalBytes += strlen($playlistContents);

                foreach (File::files($segmentDir) as $file) {
                    $relative = $relativeBase.'/'.$quality->value.'/'.$file->getFilename();
                    $contents = (string) File::get($file->getPathname());
                    $this->storage->put($outputDisk, $relative, $contents);
                    $totalBytes += strlen($contents);
                }

                // FFmpeg often emits bare seg_*.ts names; normalizeVariantPlaylist
                // prefixes them with `{quality}/` to match stored segment paths.

                $width = RenditionDimensionResolver::targetWidth($quality, $probe);

                $this->completeConversion(
                    $conversion,
                    $outputDisk,
                    $relativeBase.'/'.$quality->value,
                    $playlistRelative,
                    $width,
                    $quality->height(),
                    $quality->bandwidth(),
                    $totalBytes,
                    [
                        'max_bitrate' => $quality->maxBitrate(),
                        'audio_bitrate' => $quality->audioBitrate(),
                        'segment_count' => count(File::files($segmentDir)),
                    ],
                );

                $renditions[] = [
                    'quality' => $quality,
                    'variant' => $variantName,
                    'playlist' => $playlistRelative,
                    'bandwidth' => $quality->bandwidth(),
                    'width' => $width,
                    'height' => $quality->height(),
                ];

                $this->tracker->videoProgress($video, $qualityBandEnd, 'hls:'.$quality->value.':done');
            } catch (Throwable $exception) {
                $failedCount++;
                $this->failConversion($conversion, $exception);
                $this->logger()->error('Filament Video Engine quality encode failed; continuing remaining ladder.', [
                    'video_media_id' => $video->id,
                    'uuid' => $video->uuid,
                    'quality' => $quality->value,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'renditions' => $renditions,
            'planned' => $planned,
            'skipped' => $skipped,
            'skip_reason' => $plan['skip_reason'],
            'failed_count' => $failedCount,
        ];
    }

    /**
     * Build and store master.m3u8 linking to `{quality}.m3u8` variants.
     *
     * @param  list<array{quality: VideoQualityEnum, variant: string, playlist: string, bandwidth: int, width: int, height: int}>  $renditions
     */
    private function writeMasterPlaylist(VideoMedia $video, array $renditions, string $outputDisk): string
    {
        $conversion = $this->beginConversion($video, ConversionTypeEnum::HlsMaster, null);

        $contents = $this->playlistBuilder->buildMaster($renditions);
        $relative = sprintf(
            '%s/%s/master.m3u8',
            trim((string) config('filament-video-engine.paths.hls'), '/'),
            $video->uuid,
        );

        $this->storage->put($outputDisk, $relative, $contents);

        $this->completeConversion(
            $conversion,
            $outputDisk,
            $relative,
            $relative,
            null,
            null,
            null,
            strlen($contents),
            [
                'variants' => array_map(
                    static fn (array $r): array => [
                        'quality' => $r['quality']->value,
                        'variant' => $r['variant'],
                    ],
                    $renditions,
                ),
            ],
        );

        return $relative;
    }

    /**
     * Mark video failed and log the exception.
     */
    private function markFailed(VideoMedia $video, Throwable $exception): void
    {
        $this->logger()->error('Filament Video Engine transcoding failed.', [
            'video_media_id' => $video->id,
            'uuid' => $video->uuid,
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $this->tracker->updateVideo($video, [
            'status' => TranscodingStatusEnum::Failed,
            'error_message' => $exception->getMessage(),
            'current_step' => 'failed',
        ]);
    }

    /**
     * Remove temporary input artifacts after a hard failure when configured.
     */
    private function cleanupInputArtifacts(VideoMedia $video, string $inputDisk, bool $downloadedTemp): void
    {
        if (! (bool) config('filament-video-engine.cleanup.delete_temp_on_failure', true)) {
            return;
        }

        $path = $video->original_path;

        if ($path === null || $path === '') {
            return;
        }

        $chunksPrefix = trim((string) config('filament-video-engine.paths.chunks'), '/');
        $uploadsPrefix = trim((string) config('filament-video-engine.paths.uploads'), '/');

        $isTempPath = str_starts_with($path, $chunksPrefix.'/')
            || str_starts_with($path, $uploadsPrefix.'/');

        if ($isTempPath || ($downloadedTemp && (bool) config('filament-video-engine.cleanup.delete_remote_temp_copies', true))) {
            try {
                if ($isTempPath) {
                    $this->storage->delete($inputDisk, $path);
                }
            } catch (Throwable $exception) {
                $this->logger()->warning('Filament Video Engine failed to cleanup input artifact.', [
                    'path' => $path,
                    'disk' => $inputDisk,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Resolve the configured log writer.
     */
    private function logger(): \Psr\Log\LoggerInterface
    {
        $channel = config('filament-video-engine.logging.channel');

        return is_string($channel) && $channel !== ''
            ? Log::channel($channel)
            : Log::getFacadeRoot();
    }

    /**
     * Start a conversion row in Processing state.
     */
    private function beginConversion(VideoMedia $video, ConversionTypeEnum $type, ?string $quality): VideoConversion
    {
        $conversion = VideoConversion::query()->updateOrCreate(
            [
                'video_media_id' => $video->id,
                'type' => $type,
                'quality' => $quality,
            ],
            [
                'status' => TranscodingStatusEnum::Processing,
                'progress_percent' => 0,
                'error_message' => null,
                'started_at' => now(),
                'finished_at' => null,
            ],
        );

        $conversion->increment('attempts');

        return $conversion->refresh();
    }

    /**
     * Mark a conversion completed with output metadata.
     *
     * @param  array<string, mixed>  $meta
     */
    private function completeConversion(
        VideoConversion $conversion,
        string $disk,
        string $path,
        ?string $playlistPath,
        ?int $width,
        ?int $height,
        ?int $bandwidth,
        ?int $sizeBytes,
        array $meta = [],
    ): void {
        $conversion->forceFill([
            'status' => TranscodingStatusEnum::Completed,
            'progress_percent' => 100,
            'disk' => $disk,
            'path' => $path,
            'playlist_path' => $playlistPath,
            'width' => $width ?? $conversion->width,
            'height' => $height ?? $conversion->height,
            'bandwidth' => $bandwidth ?? $conversion->bandwidth,
            'size_bytes' => $sizeBytes,
            'meta' => array_merge($conversion->meta ?? [], $meta),
            'error_message' => null,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Mark a conversion failed.
     */
    private function failConversion(VideoConversion $conversion, Throwable $exception): void
    {
        $conversion->forceFill([
            'status' => TranscodingStatusEnum::Failed,
            'error_message' => $exception->getMessage(),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Best-effort size lookup on the output disk.
     */
    private function remoteSize(string $disk, string $path): ?int
    {
        try {
            $size = Storage::disk($disk)->size($path);

            return is_numeric($size) ? (int) $size : null;
        } catch (Throwable) {
            return null;
        }
    }
}
