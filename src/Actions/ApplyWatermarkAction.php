<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Actions;

use Martin6363\FilamentVideoEngine\DTOs\WatermarkOptionsDto;

/**
 * Resolves watermark options for injection into the FFmpeg pipeline.
 */
final class ApplyWatermarkAction
{
    /**
     * Return effective watermark options (config merged with overrides).
     */
    public function execute(?WatermarkOptionsDto $override = null): WatermarkOptionsDto
    {
        if ($override !== null) {
            return $override;
        }

        return WatermarkOptionsDto::fromConfig();
    }
}
