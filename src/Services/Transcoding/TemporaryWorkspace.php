<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Services\Transcoding;

use Illuminate\Support\Facades\File;

/**
 * Tracks temporary directories and files for reliable cleanup.
 */
final class TemporaryWorkspace
{
    /** @var list<string> */
    private array $directories = [];

    /** @var list<string> */
    private array $files = [];

    /**
     * Create a unique working directory under the system temp folder.
     */
    public function makeDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.'-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($path);
        $this->directories[] = $path;

        return $path;
    }

    /**
     * Register an existing file for later deletion.
     */
    public function trackFile(string $path): string
    {
        $this->files[] = $path;

        return $path;
    }

    /**
     * Register a directory for later recursive deletion.
     */
    public function trackDirectory(string $path): string
    {
        $this->directories[] = $path;

        return $path;
    }

    /**
     * Delete all tracked files and directories (best-effort).
     */
    public function cleanup(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        foreach (array_reverse($this->directories) as $directory) {
            if (is_dir($directory)) {
                File::deleteDirectory($directory);
            }
        }

        $this->files = [];
        $this->directories = [];
    }
}
