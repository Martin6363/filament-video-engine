<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Enums;

use Martin6363\FilamentVideoEngine\Exceptions\InvalidVideoQualityException;

/**
 * Named resolution targets for multi-bitrate HLS renditions.
 */
enum VideoQualityEnum: string
{
    case P240 = '240p';
    case P360 = '360p';
    case P480 = '480p';
    case P720 = '720p';
    case P1080 = '1080p';
    case P4K = '4k';

    /**
     * Parse a developer or API label into a quality enum.
     */
    public static function fromLabel(string $label): self
    {
        $normalized = strtolower(trim($label));

        return self::tryFrom($normalized)
            ?? throw InvalidVideoQualityException::for($label);
    }

    /**
     * Qualities enabled in package config by default.
     *
     * @return list<self>
     */
    public static function defaults(): array
    {
        /** @var list<string> $configured */
        $configured = config('filament-video-engine.default_qualities', ['360p', '480p', '720p', '1080p']);

        $qualities = [];

        foreach ($configured as $label) {
            $quality = self::tryFrom(strtolower($label));

            if ($quality instanceof self) {
                $qualities[] = $quality;
            }
        }

        return $qualities !== [] ? $qualities : [self::P360, self::P480, self::P720, self::P1080];
    }

    /**
     * Target height for this quality ladder step.
     */
    public function height(): int
    {
        return match ($this) {
            self::P240 => 240,
            self::P360 => 360,
            self::P480 => 480,
            self::P720 => 720,
            self::P1080 => 1080,
            self::P4K => 2160,
        };
    }

    /**
     * 16:9 reference width used as a maximum bounding box.
     */
    public function maxWidth(): int
    {
        return match ($this) {
            self::P240 => 426,
            self::P360 => 640,
            self::P480 => 854,
            self::P720 => 1280,
            self::P1080 => 1920,
            self::P4K => 3840,
        };
    }

    /**
     * Configured max video bitrate string (e.g. "2800k").
     */
    public function maxBitrate(): string
    {
        /** @var array<string, mixed>|null $preset */
        $preset = config('filament-video-engine.qualities.'.$this->value);

        return is_array($preset) && isset($preset['max_bitrate'])
            ? (string) $preset['max_bitrate']
            : '2800k';
    }

    /**
     * Configured audio bitrate string.
     */
    public function audioBitrate(): string
    {
        /** @var array<string, mixed>|null $preset */
        $preset = config('filament-video-engine.qualities.'.$this->value);

        return is_array($preset) && isset($preset['audio_bitrate'])
            ? (string) $preset['audio_bitrate']
            : '128k';
    }

    /**
     * Bandwidth estimate (bits/s) for HLS master playlist EXT-X-STREAM-INF.
     */
    public function bandwidth(): int
    {
        $bitrate = strtolower($this->maxBitrate());

        if (str_ends_with($bitrate, 'k')) {
            return (int) ((float) rtrim($bitrate, 'k') * 1000);
        }

        if (str_ends_with($bitrate, 'm')) {
            return (int) ((float) rtrim($bitrate, 'm') * 1_000_000);
        }

        return (int) $bitrate;
    }
}
