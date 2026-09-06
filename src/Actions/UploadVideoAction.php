<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Martin6363\FilamentVideoEngine\Enums\TranscodingStatusEnum;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Martin6363\FilamentVideoEngine\Services\Storage\VideoStorageManager;

/**
 * Persist an uploaded original video and create a VideoMedia row.
 */
final class UploadVideoAction
{
    public function __construct(
        private readonly VideoStorageManager $storage,
    ) {}

    /**
     * Store the upload on the input disk and attach optionally to a model.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function execute(
        UploadedFile $file,
        ?Model $videoable = null,
        array $attributes = [],
    ): VideoMedia {
        $disk = $this->storage->inputDisk();
        $directory = trim((string) config('filament-video-engine.paths.originals'), '/');
        $path = $file->store($directory, $disk);

        $media = new VideoMedia(array_merge([
            'disk_input' => $disk,
            'disk_output' => $this->storage->outputDisk(),
            'original_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize() ?: null,
            'status' => TranscodingStatusEnum::Pending,
            'progress_percent' => 0,
        ], $attributes));

        if ($videoable !== null) {
            $media->videoable()->associate($videoable);
        }

        $media->save();

        return $media;
    }

    /**
     * Finalize a chunked upload already assembled on the input disk.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function fromAssembledPath(
        string $path,
        string $originalFilename,
        ?string $mimeType = null,
        ?Model $videoable = null,
        array $attributes = [],
    ): VideoMedia {
        $disk = $this->storage->inputDisk();
        $size = Storage::disk($disk)->size($path);

        $media = new VideoMedia(array_merge([
            'disk_input' => $disk,
            'disk_output' => $this->storage->outputDisk(),
            'original_path' => $path,
            'original_filename' => $originalFilename,
            'mime_type' => $mimeType,
            'size_bytes' => $size ?: null,
            'status' => TranscodingStatusEnum::Pending,
            'progress_percent' => 0,
        ], $attributes));

        if ($videoable !== null) {
            $media->videoable()->associate($videoable);
        }

        $media->save();

        return $media;
    }
}
