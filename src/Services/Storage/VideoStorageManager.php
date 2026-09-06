<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Services\Storage;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Martin6363\FilamentVideoEngine\Contracts\VideoStorageContract;
use RuntimeException;

/**
 * Multi-disk storage manager for input (temp) and output (CDN) video assets.
 */
final class VideoStorageManager implements VideoStorageContract
{
    /**
     * {@inheritdoc}
     */
    public function inputDisk(): string
    {
        return (string) config('filament-video-engine.disks.input', 'local');
    }

    /**
     * {@inheritdoc}
     */
    public function outputDisk(): string
    {
        return (string) config('filament-video-engine.disks.output', 'public');
    }

    /**
     * {@inheritdoc}
     */
    public function tempDisk(): string
    {
        return (string) config('filament-video-engine.disks.temp', 'local');
    }

    /**
     * {@inheritdoc}
     */
    public function publicUrl(string $disk, string $path): string
    {
        return Storage::disk($disk)->url($path);
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $disk, string $path, string $contents): bool
    {
        return Storage::disk($disk)->put($path, $contents);
    }

    /**
     * {@inheritdoc}
     */
    public function localPath(string $disk, string $path): string
    {
        $filesystem = Storage::disk($disk);

        if (method_exists($filesystem, 'path')) {
            try {
                $local = $filesystem->path($path);

                if (is_string($local) && is_file($local)) {
                    return $local;
                }
            } catch (RuntimeException) {
                // Non-local disk — fall through to temp download.
            }
        }

        $temp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ve-'.md5($disk.$path).'-'.basename($path);
        File::put($temp, (string) $filesystem->get($path));

        return $temp;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $disk, string $path): bool
    {
        if (! Storage::disk($disk)->exists($path)) {
            return false;
        }

        return Storage::disk($disk)->delete($path);
    }

    /**
     * Assemble chunked uploads into a single object on the input disk.
     *
     * @param  list<string>  $chunkPaths  Ordered chunk paths on the temp disk.
     */
    public function assembleChunks(array $chunkPaths, string $destinationPath): string
    {
        $tempDisk = $this->tempDisk();
        $inputDisk = $this->inputDisk();
        $assembled = '';

        foreach ($chunkPaths as $chunkPath) {
            $assembled .= (string) Storage::disk($tempDisk)->get($chunkPath);
        }

        Storage::disk($inputDisk)->put($destinationPath, $assembled);

        foreach ($chunkPaths as $chunkPath) {
            Storage::disk($tempDisk)->delete($chunkPath);
        }

        return $destinationPath;
    }
}
