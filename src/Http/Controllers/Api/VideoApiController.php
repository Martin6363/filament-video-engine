<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Martin6363\FilamentVideoEngine\Services\VideoEngineManager;

/**
 * Headless API endpoints for Next.js / React / Mobile clients.
 */
final class VideoApiController extends Controller
{
    public function __construct(
        private readonly VideoEngineManager $engine,
    ) {}

    /**
     * GET /api/v1/videos/{uuid}/manifest
     */
    public function manifest(string $uuid): JsonResponse
    {
        $video = $this->findOrFail($uuid);

        return response()->json([
            'data' => $this->engine->manifest($video)->toArray(),
        ]);
    }

    /**
     * GET /api/v1/videos/{uuid}/qualities/{quality}
     */
    public function quality(string $uuid, string $quality): JsonResponse
    {
        $video = $this->findOrFail($uuid);

        return response()->json([
            'data' => $this->engine->qualityStream($video, $quality)->toArray(),
        ]);
    }

    /**
     * GET /api/v1/videos/{uuid}/progress — Filament polling / client status.
     */
    public function progress(string $uuid): JsonResponse
    {
        $video = $this->findOrFail($uuid);

        return response()->json([
            'data' => $this->engine->progress($video->load('conversions'))->toArray(),
        ]);
    }

    /**
     * Resolve VideoMedia by UUID.
     */
    private function findOrFail(string $uuid): VideoMedia
    {
        return VideoMedia::query()
            ->where('uuid', $uuid)
            ->with('conversions')
            ->firstOrFail();
    }
}
