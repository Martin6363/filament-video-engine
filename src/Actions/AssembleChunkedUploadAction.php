<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Actions;

use Martin6363\FilamentVideoEngine\Services\Storage\VideoStorageManager;

/**
 * Assemble chunked uploads into a single original on the input disk.
 */
final class AssembleChunkedUploadAction
{
    public function __construct(
        private readonly VideoStorageManager $storage,
    ) {}

    /**
     * Merge ordered chunk paths into a destination object.
     *
     * @param  list<string>  $chunkPaths
     */
    public function execute(array $chunkPaths, string $destinationPath): string
    {
        return $this->storage->assembleChunks($chunkPaths, $destinationPath);
    }
}
