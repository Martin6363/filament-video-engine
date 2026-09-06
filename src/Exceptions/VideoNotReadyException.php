<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Exceptions;

/**
 * Raised when an API client requests a stream that is not ready.
 */
final class VideoNotReadyException extends VideoEngineException
{
    /**
     * Create for a given UUID and status label.
     */
    public static function forUuid(string $uuid, string $status): self
    {
        return new self(sprintf(
            'Video [%s] is not ready for streaming (status: %s).',
            $uuid,
            $status,
        ));
    }
}
