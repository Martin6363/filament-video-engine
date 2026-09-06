<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Services\Watermark;

use Illuminate\Support\Facades\Storage;
use Martin6363\FilamentVideoEngine\Contracts\VideoStorageContract;
use Martin6363\FilamentVideoEngine\DTOs\WatermarkOptionsDto;
use Martin6363\FilamentVideoEngine\Exceptions\FfmpegException;
use Martin6363\FilamentVideoEngine\Services\Transcoding\TemporaryWorkspace;

/**
 * Resolve a watermark image to a local filesystem path for FFmpeg.
 */
final class WatermarkPathResolver
{
    public function __construct(
        private readonly VideoStorageContract $storage,
    ) {}

    /**
     * Return a local path when watermarking should run, otherwise null.
     */
    public function resolve(?WatermarkOptionsDto $watermark, ?TemporaryWorkspace $workspace = null): ?string
    {
        $options = $watermark ?? WatermarkOptionsDto::fromConfig();

        if (! $options->shouldApply() || $options->path === null) {
            return null;
        }

        $path = $options->path;

        if (is_file($path)) {
            return $path;
        }

        foreach ([$this->storage->outputDisk(), $this->storage->inputDisk()] as $disk) {
            if (! Storage::disk($disk)->exists($path)) {
                continue;
            }

            $local = $this->storage->localPath($disk, $path);

            if ($workspace !== null && ! $this->isNativeLocalDiskFile($disk, $path, $local)) {
                $workspace->trackFile($local);
            }

            return $local;
        }

        throw new FfmpegException(sprintf('Watermark file not found: %s', $path));
    }

    /**
     * Whether the resolved path is already a native local disk file.
     */
    private function isNativeLocalDiskFile(string $disk, string $path, string $local): bool
    {
        try {
            $filesystem = Storage::disk($disk);

            if (! method_exists($filesystem, 'path')) {
                return false;
            }

            $native = $filesystem->path($path);

            return is_string($native) && realpath($native) === realpath($local);
        } catch (\Throwable) {
            return false;
        }
    }
}
