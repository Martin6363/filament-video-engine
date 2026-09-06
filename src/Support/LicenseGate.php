<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Support;

use Illuminate\Support\Facades\Log;
use Martin6363\FilamentVideoEngine\Enums\TranscodingStatusEnum;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Martin6363\FilamentVideoEngine\Services\LicenseManager;

/**
 * Shared license gate for dispatch actions and queue jobs.
 */
final class LicenseGate
{
    private const WARNING = 'Filament Video Engine: Processing halted due to missing or invalid license key.';

    /**
     * Whether processing may continue. On failure, marks the video as failed.
     */
    public static function allowOrFail(VideoMedia $video): bool
    {
        if (LicenseManager::isLicensed()) {
            return true;
        }

        Log::warning(self::WARNING, [
            'video_media_id' => $video->id,
            'uuid' => $video->uuid,
        ]);

        $video->forceFill([
            'status' => TranscodingStatusEnum::Failed,
            'current_step' => 'license',
            'error_message' => self::WARNING,
            'progress_percent' => 0,
        ])->save();

        return false;
    }
}
