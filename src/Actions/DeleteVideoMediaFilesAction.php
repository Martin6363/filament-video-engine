<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Actions;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Throwable;

/**
 * Permanently remove all storage artifacts for a VideoMedia record.
 */
final class DeleteVideoMediaFilesAction
{
    /**
     * Delete originals, posters, HLS trees, keys, and conversion paths.
     *
     * @return list<string> Human-readable summary of deleted targets.
     */
    public function execute(VideoMedia $video): array
    {
        $deleted = [];

        $inputDisk = (string) ($video->disk_input ?: config('filament-video-engine.disks.input', 'local'));
        $outputDisk = (string) ($video->disk_output ?: config('filament-video-engine.disks.output', 'public'));

        if (filled($video->original_path)) {
            $this->deletePath($inputDisk, (string) $video->original_path, $deleted);
        }

        if (filled($video->poster_path)) {
            $this->deletePath($outputDisk, (string) $video->poster_path, $deleted);
        }

        if (filled($video->watermark_path)) {
            $this->deletePath($outputDisk, (string) $video->watermark_path, $deleted);
        }

        if (filled($video->master_playlist_path)) {
            $this->deletePath($outputDisk, (string) $video->master_playlist_path, $deleted);
        }

        $video->loadMissing('conversions');

        foreach ($video->conversions as $conversion) {
            $disk = (string) ($conversion->disk ?: $outputDisk);

            if (filled($conversion->playlist_path)) {
                $this->deletePath($disk, (string) $conversion->playlist_path, $deleted);
            }

            if (filled($conversion->path)) {
                $path = (string) $conversion->path;

                if ($this->looksLikeDirectory($disk, $path)) {
                    $this->deleteDirectory($disk, $path, $deleted);
                } else {
                    $this->deletePath($disk, $path, $deleted);
                }
            }
        }

        // UUID-scoped trees produced by the transcoder.
        $uuid = $video->uuid;

        if (filled($uuid)) {
            $hlsRoot = trim((string) config('filament-video-engine.paths.hls'), '/').'/'.$uuid;
            $posterRoot = trim((string) config('filament-video-engine.paths.posters'), '/').'/'.$uuid;
            $watermarkRoot = trim((string) config('filament-video-engine.paths.watermarks'), '/').'/'.$uuid;
            $keyRoot = trim((string) config('filament-video-engine.hls.encryption.key_path', 'video-engine/keys'), '/').'/'.$uuid;

            $this->deleteDirectory($outputDisk, $hlsRoot, $deleted);
            $this->deleteDirectory($outputDisk, $posterRoot, $deleted);
            $this->deleteDirectory($outputDisk, $watermarkRoot, $deleted);

            $keyDisk = (string) (config('filament-video-engine.hls.encryption.key_disk') ?: $outputDisk);
            $this->deleteDirectory($keyDisk, $keyRoot, $deleted);
        }

        return array_values(array_unique($deleted));
    }

    /**
     * Delete a single file when it exists.
     *
     * @param  list<string>  $deleted
     */
    private function deletePath(string $disk, string $path, array &$deleted): void
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if ($path === '') {
            return;
        }

        try {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
                $deleted[] = $disk.':'.$path;
            }
        } catch (Throwable $exception) {
            Log::warning('Filament Video Engine failed to delete storage file.', [
                'disk' => $disk,
                'path' => $path,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Recursively delete a directory prefix on a disk.
     *
     * @param  list<string>  $deleted
     */
    private function deleteDirectory(string $disk, string $directory, array &$deleted): void
    {
        $directory = trim(str_replace('\\', '/', $directory), '/');

        if ($directory === '') {
            return;
        }

        try {
            $filesystem = Storage::disk($disk);

            if (method_exists($filesystem, 'deleteDirectory')) {
                $exists = (method_exists($filesystem, 'directoryExists') && $filesystem->directoryExists($directory))
                    || $this->directoryHasFiles($disk, $directory);

                if ($exists) {
                    $filesystem->deleteDirectory($directory);
                    $deleted[] = $disk.':'.$directory.'/';
                }

                return;
            }

            foreach ($filesystem->allFiles($directory) as $file) {
                $filesystem->delete($file);
                $deleted[] = $disk.':'.$file;
            }
        } catch (Throwable $exception) {
            Log::warning('Filament Video Engine failed to delete storage directory.', [
                'disk' => $disk,
                'directory' => $directory,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Whether the path is a directory (or conversion folder) rather than a file.
     */
    private function looksLikeDirectory(string $disk, string $path): bool
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        try {
            $filesystem = Storage::disk($disk);

            if (method_exists($filesystem, 'directoryExists') && $filesystem->directoryExists($path)) {
                return true;
            }

            return $this->directoryHasFiles($disk, $path) && ! $filesystem->exists($path);
        } catch (Throwable) {
            return ! str_contains(basename($path), '.');
        }
    }

    /**
     * Whether any files exist under the directory prefix.
     */
    private function directoryHasFiles(string $disk, string $directory): bool
    {
        try {
            return Storage::disk($disk)->allFiles($directory) !== [];
        } catch (Throwable) {
            return false;
        }
    }
}
