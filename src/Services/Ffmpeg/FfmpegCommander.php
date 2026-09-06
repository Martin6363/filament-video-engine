<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Services\Ffmpeg;

use Martin6363\FilamentVideoEngine\DTOs\MediaProbeDto;
use Martin6363\FilamentVideoEngine\DTOs\WatermarkOptionsDto;
use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;
use Martin6363\FilamentVideoEngine\Support\RenditionDimensionResolver;

/**
 * Builds FFmpeg argument lists for HLS, thumbnails, and watermarks.
 */
final class FfmpegCommander
{
    public function __construct(
        private readonly FfmpegBinaryResolver $binaries,
    ) {}

    /**
     * Probe media metadata as JSON.
     *
     * @return list<string>
     */
    public function probeCommand(string $inputPath): array
    {
        return [
            $this->binaries->ffprobe(),
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $inputPath,
        ];
    }

    /**
     * Extract a single high-quality poster frame (jpg or webp).
     *
     * @return list<string>
     */
    public function thumbnailCommand(string $inputPath, string $outputPath, float $atSeconds, ?string $format = null): array
    {
        $format = strtolower($format ?? (string) config('filament-video-engine.thumbnail.format', 'jpg'));
        $quality = (int) config('filament-video-engine.thumbnail.quality', 3);

        $command = [
            $this->binaries->ffmpeg(),
            '-hide_banner',
            '-y',
            '-ss', $this->formatTimestamp($atSeconds),
            '-i', $inputPath,
            '-frames:v', '1',
            '-an',
        ];

        if ($format === 'webp') {
            $webpQuality = max(1, min(100, $quality <= 31 ? (100 - (($quality - 1) * 3)) : $quality));

            $command = array_merge($command, [
                '-c:v', 'libwebp',
                '-quality', (string) $webpQuality,
            ]);
        } else {
            $command = array_merge($command, [
                '-q:v', (string) max(1, min(31, $quality)),
            ]);
        }

        $command[] = $outputPath;

        return $command;
    }

    /**
     * Transcode one quality ladder step to `{quality}.m3u8` + segment files.
     *
     * @return list<string>
     */
    public function hlsRenditionCommand(
        string $inputPath,
        string $playlistPath,
        string $segmentPattern,
        VideoQualityEnum $quality,
        int $segmentDuration,
        ?WatermarkOptionsDto $watermark = null,
        ?string $localWatermarkPath = null,
        ?string $encryptionKeyInfo = null,
        bool $hasAudio = true,
        ?MediaProbeDto $probe = null,
    ): array {
        $height = $quality->height();
        $videoCodec = (string) config('filament-video-engine.ffmpeg.video_codec', 'libx264');
        $audioCodec = (string) config('filament-video-engine.ffmpeg.audio_codec', 'aac');
        $crf = (int) config('filament-video-engine.ffmpeg.crf', 23);
        $preset = (string) config('filament-video-engine.ffmpeg.preset', 'medium');
        $pixelFormat = (string) config('filament-video-engine.ffmpeg.pixel_format', 'yuv420p');

        $scaleFilter = sprintf(
            'scale=-2:%d:force_original_aspect_ratio=decrease,scale=trunc(iw/2)*2:trunc(ih/2)*2',
            $height,
        );

        $command = [
            $this->binaries->ffmpeg(),
            '-hide_banner',
            '-y',
            '-i', $inputPath,
        ];

        $applyWatermark = $watermark !== null
            && $watermark->shouldApply()
            && $localWatermarkPath !== null
            && $localWatermarkPath !== '';

        if ($applyWatermark) {
            $command[] = '-i';
            $command[] = $localWatermarkPath;

            $command[] = '-filter_complex';
            $command[] = $this->watermarkFilterComplex(
                $scaleFilter,
                $watermark,
                $probe instanceof MediaProbeDto
                    ? RenditionDimensionResolver::watermarkWidth(
                        $quality,
                        $probe,
                        $watermark->maxWidthPercent,
                    )
                    : RenditionDimensionResolver::watermarkWidth(
                        $quality,
                        new MediaProbeDto(null, null, $quality->height(), null, null, null, null, $hasAudio),
                        $watermark->maxWidthPercent,
                    ),
            );
            $command[] = '-map';
            $command[] = '[v]';

            if ($hasAudio) {
                $command[] = '-map';
                $command[] = '0:a:0?';
            }
        } else {
            $command[] = '-vf';
            $command[] = $scaleFilter;
            $command[] = '-map';
            $command[] = '0:v:0';

            if ($hasAudio) {
                $command[] = '-map';
                $command[] = '0:a:0?';
            }
        }

        $command = array_merge($command, [
            '-c:v', $videoCodec,
            '-crf', (string) $crf,
            '-preset', $preset,
            '-pix_fmt', $pixelFormat,
            '-profile:v', 'main',
            '-sc_threshold', '0',
            '-g', '48',
            '-keyint_min', '48',
            '-maxrate', $quality->maxBitrate(),
            '-bufsize', $this->bufferSize($quality->maxBitrate()),
        ]);

        if ($hasAudio) {
            $command = array_merge($command, [
                '-c:a', $audioCodec,
                '-b:a', $quality->audioBitrate(),
                '-ac', '2',
                '-ar', '48000',
            ]);
        } else {
            $command[] = '-an';
        }

        $command = array_merge($command, [
            '-f', 'hls',
            '-hls_time', (string) $segmentDuration,
            '-hls_playlist_type', 'vod',
            '-hls_flags', 'independent_segments',
            '-hls_segment_filename', $segmentPattern,
        ]);

        if ($encryptionKeyInfo !== null) {
            $command[] = '-hls_key_info_file';
            $command[] = $encryptionKeyInfo;
        }

        $threads = config('filament-video-engine.ffmpeg.threads');

        if ($threads !== null && $threads !== '') {
            $command[] = '-threads';
            $command[] = (string) $threads;
        }

        $command[] = $playlistPath;

        return $command;
    }

    /**
     * Build a filter graph that scales the video and overlays a proportional watermark.
     */
    public function watermarkFilterComplex(
        string $videoScaleFilter,
        WatermarkOptionsDto $watermark,
        int $watermarkPixelWidth,
    ): string {
        $opacity = max(0.0, min(1.0, $watermark->opacity));
        $overlay = $watermark->position->overlayExpression($watermark->margin);
        $watermarkPixelWidth = max(2, $watermarkPixelWidth - ($watermarkPixelWidth % 2));

        return sprintf(
            '[0:v]%s[base];[1:v]format=rgba,colorchannelmixer=aa=%s,scale=%d:-1:force_original_aspect_ratio=decrease[wm];[base][wm]overlay=%s[v]',
            $videoScaleFilter,
            $opacity,
            $watermarkPixelWidth,
            $overlay,
        );
    }

    /**
     * Format seconds as HH:MM:SS.mmm for -ss.
     */
    public function formatTimestamp(float $seconds): string
    {
        $seconds = max(0.0, $seconds);
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(fmod($seconds, 3600) / 60);
        $secs = $seconds - ($hours * 3600) - ($minutes * 60);

        return sprintf('%02d:%02d:%06.3f', $hours, $minutes, $secs);
    }

    /**
     * Derive an HLS buffer size from a bitrate string.
     */
    private function bufferSize(string $bitrate): string
    {
        $normalized = strtolower(trim($bitrate));

        if (str_ends_with($normalized, 'k')) {
            $value = (float) rtrim($normalized, 'k');

            return ((int) ($value * 2)).'k';
        }

        if (str_ends_with($normalized, 'm')) {
            $value = (float) rtrim($normalized, 'm');

            return ((int) ($value * 2)).'M';
        }

        return $bitrate;
    }
}
