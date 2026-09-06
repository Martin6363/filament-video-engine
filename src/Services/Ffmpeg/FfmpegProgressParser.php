<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Services\Ffmpeg;

/**
 * Parses FFmpeg stderr / -progress output for elapsed encode time.
 */
final class FfmpegProgressParser
{
    /**
     * Extract the latest media time in seconds from an FFmpeg output chunk.
     */
    public function parseTimeSeconds(string $chunk): ?float
    {
        if (preg_match_all('/out_time_ms=(\d+)/', $chunk, $matches) && $matches[1] !== []) {
            $micro = (int) end($matches[1]);

            return max(0.0, $micro / 1_000_000);
        }

        if (preg_match_all('/out_time=(\d{2}):(\d{2}):(\d{2}(?:\.\d+)?)/', $chunk, $matches, PREG_SET_ORDER) && $matches !== []) {
            $last = end($matches);

            return ((int) $last[1] * 3600) + ((int) $last[2] * 60) + (float) $last[3];
        }

        if (preg_match_all('/time=(\d{2}):(\d{2}):(\d{2}(?:\.\d+)?)/', $chunk, $matches, PREG_SET_ORDER) && $matches !== []) {
            $last = end($matches);

            return ((int) $last[1] * 3600) + ((int) $last[2] * 60) + (float) $last[3];
        }

        return null;
    }

    /**
     * Map elapsed encode time to a 0–100 percentage of the source duration.
     */
    public function percentOfDuration(float $elapsedSeconds, ?float $durationSeconds): float
    {
        if ($durationSeconds === null || $durationSeconds <= 0.0) {
            return 0.0;
        }

        return min(100.0, max(0.0, ($elapsedSeconds / $durationSeconds) * 100.0));
    }
}
