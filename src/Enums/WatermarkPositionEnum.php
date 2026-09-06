<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Enums;

/**
 * Watermark overlay anchor positions for FFmpeg overlay filter.
 */
enum WatermarkPositionEnum: string
{
    case TopLeft = 'top-left';
    case TopRight = 'top-right';
    case BottomLeft = 'bottom-left';
    case BottomRight = 'bottom-right';
    case Center = 'center';

    private const REFERENCE_WIDTH = 1920;

    private const REFERENCE_HEIGHT = 1080;

    /**
     * FFmpeg overlay expression for x,y given margin in pixels at 1080p reference.
     *
     * Margins scale with the encoded rendition so spacing stays visually consistent.
     */
    public function overlayExpression(int $margin = 20): string
    {
        $marginX = sprintf('main_w*%d/%d', $margin, self::REFERENCE_WIDTH);
        $marginY = sprintf('main_h*%d/%d', $margin, self::REFERENCE_HEIGHT);

        return match ($this) {
            self::TopLeft => "{$marginX}:{$marginY}",
            self::TopRight => "main_w-overlay_w-{$marginX}:{$marginY}",
            self::BottomLeft => "{$marginX}:main_h-overlay_h-{$marginY}",
            self::BottomRight => "main_w-overlay_w-{$marginX}:main_h-overlay_h-{$marginY}",
            self::Center => '(main_w-overlay_w)/2:(main_h-overlay_h)/2',
        };
    }
}
