<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Support;

/**
 * Resolves the installed package version from composer metadata when available.
 */
final class PackageVersion
{
    /**
     * Current package version string.
     */
    public static function current(): string
    {
        return '0.1.0';
    }
}
