<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Exceptions;

/**
 * Raised when FFmpeg / FFProbe execution fails.
 */
final class FfmpegException extends VideoEngineException
{
    /**
     * Create an exception for a non-zero FFmpeg exit.
     */
    public static function processFailed(string $command, int $exitCode, string $stderr): self
    {
        return new self(sprintf(
            'FFmpeg process failed (exit %d) for [%s]: %s',
            $exitCode,
            $command,
            trim($stderr) !== '' ? trim($stderr) : 'no stderr',
        ));
    }

    /**
     * Create an exception when the binary cannot be resolved.
     */
    public static function binaryMissing(string $binary): self
    {
        return new self(sprintf('FFmpeg binary not found: %s', $binary));
    }
}
