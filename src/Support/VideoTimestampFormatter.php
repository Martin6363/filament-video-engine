<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Support;

use Carbon\CarbonInterface;

/**
 * Convert between video positions (seconds) and HH:MM:SS time strings.
 */
final class VideoTimestampFormatter
{
    /**
     * Format seconds as a time string for Filament time pickers.
     */
    public static function toTimeString(float $seconds): string
    {
        $seconds = max(0.0, $seconds);
        $whole = (int) floor($seconds);
        $hours = intdiv($whole, 3600);
        $minutes = intdiv($whole % 3600, 60);
        $secs = $whole % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    /**
     * Parse a time picker / legacy numeric value into seconds.
     */
    public static function toSeconds(mixed $state): ?float
    {
        if ($state === null || $state === '') {
            return null;
        }

        if (is_numeric($state)) {
            return max(0.0, (float) $state);
        }

        if ($state instanceof CarbonInterface) {
            return (float) (
                ($state->hour * 3600)
                + ($state->minute * 60)
                + $state->second
                + ($state->micro / 1_000_000)
            );
        }

        if (! is_string($state)) {
            return null;
        }

        $state = trim($state);

        if ($state === '') {
            return null;
        }

        if (preg_match('/^(?P<hours>\d+):(?P<minutes>\d{2}):(?P<seconds>\d{2})$/', $state, $matches) === 1) {
            return (float) (
                ((int) $matches['hours']) * 3600
                + ((int) $matches['minutes']) * 60
                + (int) $matches['seconds']
            );
        }

        if (preg_match('/^(?P<minutes>\d+):(?P<seconds>\d{2})$/', $state, $matches) === 1) {
            return (float) (((int) $matches['minutes']) * 60 + (int) $matches['seconds']);
        }

        return null;
    }
}
