<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Contracts;

/**
 * Abstraction over input/output disks for video assets.
 */
interface VideoStorageContract
{
    /**
     * Input (temp upload) disk name.
     */
    public function inputDisk(): string;

    /**
     * Output (CDN) disk name.
     */
    public function outputDisk(): string;

    /**
     * Temporary working disk name.
     */
    public function tempDisk(): string;

    /**
     * Public or temporary URL for a path on a disk.
     */
    public function publicUrl(string $disk, string $path): string;

    /**
     * Store binary contents on a disk.
     */
    public function put(string $disk, string $path, string $contents): bool;

    /**
     * Absolute local path when the disk is local; otherwise downloads to temp.
     */
    public function localPath(string $disk, string $path): string;

    /**
     * Delete a path from a disk if it exists.
     */
    public function delete(string $disk, string $path): bool;
}
