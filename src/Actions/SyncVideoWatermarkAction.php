<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Martin6363\FilamentVideoEngine\DTOs\WatermarkOptionsDto;
use Martin6363\FilamentVideoEngine\Enums\WatermarkPositionEnum;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;

/**
 * Persist per-video watermark settings from the VideoEnginePicker.
 */
final class SyncVideoWatermarkAction
{
    /**
     * @param  array{
     *     enabled: bool,
     *     image: mixed,
     *     position: ?string,
     *     opacity: ?float,
     *     margin: ?int,
     *     scale_percent: ?float,
     * }  $settings
     */
    public function execute(VideoMedia $video, array $settings): VideoMedia
    {
        $enabled = (bool) ($settings['enabled'] ?? false);
        $position = WatermarkPositionEnum::tryFrom((string) ($settings['position'] ?? ''));
        $opacity = isset($settings['opacity']) && is_numeric($settings['opacity'])
            ? max(0.0, min(1.0, (float) $settings['opacity']))
            : null;
        $margin = isset($settings['margin']) && is_numeric($settings['margin'])
            ? max(0, (int) $settings['margin'])
            : null;
        $scalePercent = isset($settings['scale_percent']) && is_numeric($settings['scale_percent'])
            ? WatermarkOptionsDto::normalizeMaxWidthPercent((float) $settings['scale_percent'])
            : null;

        $path = $video->watermark_path;

        if ($enabled) {
            $resolvedPath = $this->resolveWatermarkImagePath($settings['image'] ?? null);

            if ($resolvedPath !== null) {
                $path = $resolvedPath;
            }
        }

        $video->fill([
            'watermark_enabled' => $enabled,
            'watermark_path' => $enabled ? $path : null,
            'watermark_position' => $enabled && $position !== null ? $position->value : null,
            'watermark_opacity' => $enabled ? $opacity : null,
            'watermark_margin' => $enabled ? $margin : null,
            'watermark_scale_percent' => $enabled ? $scalePercent : null,
        ]);

        $video->save();

        return $video->refresh();
    }

    /**
     * Normalize a watermark upload into a storage-relative path.
     */
    private function resolveWatermarkImagePath(mixed $image): ?string
    {
        if (is_array($image)) {
            $first = reset($image);

            return $this->resolveWatermarkImagePath($first === false ? null : $first);
        }

        $file = $this->resolveUploadedFile($image);

        if ($file !== null) {
            return $file->store(
                trim((string) config('filament-video-engine.paths.watermarks'), '/'),
                (string) config('filament-video-engine.disks.output', 'public'),
            );
        }

        if (! is_string($image) || $image === '') {
            return null;
        }

        $outputDisk = (string) config('filament-video-engine.disks.output', 'public');
        $inputDisk = (string) config('filament-video-engine.disks.input', 'local');

        if (Storage::disk($outputDisk)->exists($image)) {
            return $image;
        }

        if (Storage::disk($inputDisk)->exists($image)) {
            return $image;
        }

        return null;
    }

    /**
     * Normalize Filament / Livewire upload values into an UploadedFile.
     */
    private function resolveUploadedFile(mixed $upload): ?UploadedFile
    {
        if ($upload instanceof TemporaryUploadedFile || $upload instanceof UploadedFile) {
            return $upload;
        }

        if (is_string($upload) && $upload !== '' && is_file($upload)) {
            return new UploadedFile($upload, basename($upload), null, null, true);
        }

        return null;
    }
}
