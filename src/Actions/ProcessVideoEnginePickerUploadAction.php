<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Martin6363\FilamentVideoEngine\Concerns\HasVideoEngine;
use Martin6363\FilamentVideoEngine\DTOs\ThumbnailOptionsDto;
use Martin6363\FilamentVideoEngine\DTOs\TranscodingOptionsDto;
use Martin6363\FilamentVideoEngine\Enums\VideoQualityEnum;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Martin6363\FilamentVideoEngine\Services\LicenseManager;

/**
 * Process pending VideoEnginePicker uploads and queue transcoding.
 */
final class ProcessVideoEnginePickerUploadAction
{
    public function __construct(
        private readonly UploadVideoAction $upload,
        private readonly DispatchTranscodingAction $dispatch,
    ) {}

    /**
     * Attach an uploaded video to a model and queue HLS processing.
     *
     * @param  UploadedFile|TemporaryUploadedFile|string|null  $upload
     * @param  UploadedFile|TemporaryUploadedFile|string|null  $customPoster
     * @param  array{
     *     enabled: bool,
     *     image: mixed,
     *     position: ?string,
     *     opacity: ?float,
     *     margin: ?int,
     * }|null  $watermarkSettings
     * @param  list<string>  $qualities
     */
    public function execute(
        ?Model $videoable,
        mixed $upload,
        mixed $customPoster = null,
        ?float $thumbnailAtSeconds = null,
        array $qualities = [],
        ?string $title = null,
        ?array $watermarkSettings = null,
    ): ?VideoMedia {
        if (! LicenseManager::isLicensed()) {
            return null;
        }

        $file = $this->resolveUploadedFile($upload);

        if ($file === null) {
            return null;
        }

        $videoable = $this->resolveVideoable($videoable);
        $posterPath = $this->resolveCustomPosterPath($customPoster);

        $media = $this->upload->execute($file, $videoable, array_filter([
            'title' => $title,
            'thumbnail_at_seconds' => $thumbnailAtSeconds,
            'poster_is_custom' => $posterPath !== null,
            'poster_path' => $posterPath,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        if (is_array($watermarkSettings)) {
            $media = app(SyncVideoWatermarkAction::class)->execute($media, $watermarkSettings);
        }

        $qualityEnums = $this->resolveQualityEnums($qualities);

        $options = TranscodingOptionsDto::fromConfig(
            qualities: $qualityEnums !== [] ? $qualityEnums : null,
            thumbnail: ThumbnailOptionsDto::fromConfig(
                customPosterPath: $posterPath,
                extractAtSeconds: $thumbnailAtSeconds,
            ),
            watermark: $media->watermarkOptions(),
        );

        $this->dispatch->execute($media, $options);

        return $media;
    }

    /**
     * Resolve the polymorphic owner when the record uses HasVideoEngine.
     */
    private function resolveVideoable(?Model $record): ?Model
    {
        if ($record === null) {
            return null;
        }

        if (in_array(HasVideoEngine::class, class_uses_recursive($record), true)) {
            return $record;
        }

        return null;
    }

    /**
     * @param  list<string>  $qualities
     * @return list<VideoQualityEnum>
     */
    private function resolveQualityEnums(array $qualities): array
    {
        if ($qualities === []) {
            return VideoQualityEnum::defaults();
        }

        return array_values(array_filter(array_map(
            static fn (string $label): ?VideoQualityEnum => VideoQualityEnum::tryFrom(strtolower($label)),
            $qualities,
        )));
    }

    /**
     * Normalize a custom poster upload into a storage-relative path.
     */
    private function resolveCustomPosterPath(mixed $customPoster): ?string
    {
        if (is_array($customPoster)) {
            $first = reset($customPoster);

            return $this->resolveCustomPosterPath($first === false ? null : $first);
        }

        $file = $this->resolveUploadedFile($customPoster);

        if ($file !== null) {
            return $this->storeCustomPoster($file);
        }

        if (! is_string($customPoster) || $customPoster === '') {
            return null;
        }

        $outputDisk = (string) config('filament-video-engine.disks.output', 'public');
        $inputDisk = (string) config('filament-video-engine.disks.input', 'local');

        if (Storage::disk($outputDisk)->exists($customPoster)) {
            return $customPoster;
        }

        if (Storage::disk($inputDisk)->exists($customPoster)) {
            return $customPoster;
        }

        return null;
    }

    /**
     * Persist a custom poster upload on the configured output disk.
     */
    private function storeCustomPoster(UploadedFile $file): string
    {
        return $file->store(
            trim((string) config('filament-video-engine.paths.posters'), '/'),
            (string) config('filament-video-engine.disks.output', 'public'),
        );
    }

    /**
     * Normalize Filament / Livewire upload values into an UploadedFile.
     */
    private function resolveUploadedFile(mixed $upload): ?UploadedFile
    {
        if ($upload instanceof TemporaryUploadedFile || $upload instanceof UploadedFile) {
            return $upload;
        }

        if (is_array($upload)) {
            $first = reset($upload);

            return $this->resolveUploadedFile($first === false ? null : $first);
        }

        if (is_string($upload) && $upload !== '' && is_file($upload)) {
            return new UploadedFile($upload, basename($upload), null, null, true);
        }

        return null;
    }
}
