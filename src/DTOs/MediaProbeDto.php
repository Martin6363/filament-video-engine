<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\DTOs;

/**
 * FFProbe summary used by the transcoder pipeline.
 */
readonly class MediaProbeDto
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?float $durationSeconds,
        public ?int $width,
        public ?int $height,
        public ?float $frameRate,
        public ?string $videoCodec,
        public ?string $audioCodec,
        public ?int $bitrate,
        public bool $hasAudio,
        public array $raw = [],
    ) {}

    /**
     * Hydrate from ffprobe JSON.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromFfprobeJson(array $data): self
    {
        $duration = isset($data['format']['duration']) ? (float) $data['format']['duration'] : null;
        $bitrate = isset($data['format']['bit_rate']) ? (int) $data['format']['bit_rate'] : null;

        $width = null;
        $height = null;
        $frameRate = null;
        $videoCodec = null;
        $audioCodec = null;
        $hasAudio = false;

        foreach ($data['streams'] ?? [] as $stream) {
            if (! is_array($stream)) {
                continue;
            }

            $type = $stream['codec_type'] ?? null;

            if ($type === 'video' && $width === null) {
                $width = isset($stream['width']) ? (int) $stream['width'] : null;
                $height = isset($stream['height']) ? (int) $stream['height'] : null;
                $videoCodec = isset($stream['codec_name']) ? (string) $stream['codec_name'] : null;
                $frameRate = self::parseFrameRate($stream['avg_frame_rate'] ?? ($stream['r_frame_rate'] ?? null));
            }

            if ($type === 'audio') {
                $hasAudio = true;
                $audioCodec ??= isset($stream['codec_name']) ? (string) $stream['codec_name'] : null;
            }
        }

        return new self(
            durationSeconds: $duration,
            width: $width,
            height: $height,
            frameRate: $frameRate,
            videoCodec: $videoCodec,
            audioCodec: $audioCodec,
            bitrate: $bitrate,
            hasAudio: $hasAudio,
            raw: $data,
        );
    }

    /**
     * Parse ffprobe frame-rate fractions such as "30000/1001".
     */
    private static function parseFrameRate(mixed $value): ?float
    {
        if (! is_string($value) || $value === '' || $value === '0/0') {
            return null;
        }

        if (str_contains($value, '/')) {
            [$num, $den] = array_pad(explode('/', $value, 2), 2, '1');
            $denominator = (float) $den;

            return $denominator > 0.0 ? ((float) $num) / $denominator : null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
