<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Actions;

use Martin6363\FilamentVideoEngine\Contracts\VideoStorageContract;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;

/**
 * Remove a previous poster file after it has been replaced or cleared.
 */
final class DeleteReplacedVideoPosterAction
{
    public function __construct(
        private readonly VideoStorageContract $storage,
    ) {}

    /**
     * Delete the previous poster path when it differs from the current one.
     */
    public function execute(VideoMedia $video, ?string $previousPath): bool
    {
        if ($previousPath === null || $previousPath === '') {
            return false;
        }

        $previousPath = ltrim(str_replace('\\', '/', $previousPath), '/');
        $currentPath = is_string($video->poster_path)
            ? ltrim(str_replace('\\', '/', $video->poster_path), '/')
            : '';

        if ($previousPath === '' || $previousPath === $currentPath) {
            return false;
        }

        $disk = (string) ($video->disk_output ?: $this->storage->outputDisk());

        return $this->storage->delete($disk, $previousPath);
    }
}
