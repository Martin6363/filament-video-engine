<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Services\Hls;

use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;

/**
 * Builds HLS master.m3u8 playlist contents linking to `{quality}.m3u8` variants.
 */
final class HlsPlaylistBuilder
{
    /**
     * Build a master playlist from rendition descriptors.
     *
     * Each rendition's `variant` should be a filename relative to master
     * (e.g. "1080p.m3u8", "720p.m3u8").
     *
     * @param  list<array{quality: VideoQualityEnum, variant: string, bandwidth: int, width: int, height: int}>  $renditions
     */
    public function buildMaster(array $renditions): string
    {
        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
        ];

        usort(
            $renditions,
            static fn (array $a, array $b): int => $a['bandwidth'] <=> $b['bandwidth'],
        );

        foreach ($renditions as $rendition) {
            $variant = ltrim(str_replace('\\', '/', $rendition['variant']), '/');

            $lines[] = sprintf(
                '#EXT-X-STREAM-INF:BANDWIDTH=%d,AVERAGE-BANDWIDTH=%d,RESOLUTION=%dx%d,FRAME-RATE=30,CODECS="avc1.4d401f,mp4a.40.2",NAME="%s"',
                $rendition['bandwidth'],
                (int) round($rendition['bandwidth'] * 0.9),
                $rendition['width'],
                $rendition['height'],
                $rendition['quality']->value,
            );
            $lines[] = $variant;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Prefix bare segment filenames so a root-level `{quality}.m3u8` resolves
     * segments stored under `{quality}/seg_xxxxx.ts`.
     *
     * FFmpeg often emits only `seg_00000.ts` when `-hls_segment_filename` uses
     * an absolute path into a subdirectory.
     */
    public function normalizeVariantPlaylist(string $contents, string $qualityFolder): string
    {
        $qualityFolder = trim(str_replace('\\', '/', $qualityFolder), '/');
        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $normalized = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (
                $trimmed !== ''
                && ! str_starts_with($trimmed, '#')
                && preg_match('/\.ts$/i', $trimmed) === 1
            ) {
                $uri = str_replace('\\', '/', $trimmed);

                if (! str_contains($uri, '/')) {
                    $normalized[] = $qualityFolder.'/'.$uri;

                    continue;
                }
            }

            $normalized[] = $line;
        }

        return implode("\n", $normalized)."\n";
    }
}
