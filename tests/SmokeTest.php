<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Smoke test so CI always has an executable suite.
 */
final class SmokeTest extends TestCase
{
    /**
     * Package namespace and composer metadata stay aligned.
     */
    public function test_package_name_is_defined(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__).'/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('martin6363/filament-video-engine', $composer['name'] ?? null);
    }
}
