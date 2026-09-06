<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Exceptions;

/**
 * Raised when a quality label cannot be mapped to VideoQualityEnum.
 */
final class InvalidVideoQualityException extends VideoEngineException
{
    /**
     * Create for an unknown quality label.
     */
    public static function for(string $label): self
    {
        return new self(sprintf('Invalid video quality [%s].', $label));
    }
}
