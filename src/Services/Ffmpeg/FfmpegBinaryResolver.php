<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Services\Ffmpeg;

use Martin6363\FilamentVideoEngine\Exceptions\FfmpegException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Resolves FFmpeg and FFProbe binary paths from config or PATH.
 */
final class FfmpegBinaryResolver
{
    public function __construct(
        private readonly ExecutableFinder $finder = new ExecutableFinder,
    ) {}

    /**
     * Absolute path to the ffmpeg binary.
     */
    public function ffmpeg(): string
    {
        return $this->resolve(
            config('filament-video-engine.ffmpeg.binary'),
            'ffmpeg',
        );
    }

    /**
     * Absolute path to the ffprobe binary.
     */
    public function ffprobe(): string
    {
        return $this->resolve(
            config('filament-video-engine.ffmpeg.ffprobe'),
            'ffprobe',
        );
    }

    /**
     * Whether both binaries can be resolved without executing them.
     */
    public function isAvailable(): bool
    {
        try {
            $this->ffmpeg();
            $this->ffprobe();

            return true;
        } catch (FfmpegException) {
            return false;
        }
    }

    /**
     * Resolve an explicit path or discover via PATH.
     */
    private function resolve(mixed $configured, string $defaultName): string
    {
        if (is_string($configured) && $configured !== '') {
            if (! is_file($configured) && ! $this->isWindowsCommand($configured)) {
                throw FfmpegException::binaryMissing($configured);
            }

            return $configured;
        }

        $found = $this->finder->find($defaultName);

        if ($found === null) {
            throw FfmpegException::binaryMissing($defaultName);
        }

        return $found;
    }

    /**
     * Allow bare command names on Windows when they exist on PATH.
     */
    private function isWindowsCommand(string $path): bool
    {
        return PHP_OS_FAMILY === 'Windows' && ! str_contains($path, DIRECTORY_SEPARATOR);
    }
}
