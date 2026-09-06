<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Services\Hls;

use Illuminate\Support\Facades\File;
use Martin6363\FilamentVideoEngine\Models\VideoMedia;
use Martin6363\FilamentVideoEngine\Services\Storage\VideoStorageManager;

/**
 * Creates AES-128 HLS key info files for FFmpeg -hls_key_info_file.
 */
final class HlsEncryptor
{
    public function __construct(
        private readonly VideoStorageManager $storage,
    ) {}

    /**
     * Write a local key + keyinfo file and persist the key to storage.
     *
     * @return string Absolute path to the keyinfo file for FFmpeg.
     */
    public function createKeyInfoFile(VideoMedia $video, string $workRoot): string
    {
        $key = random_bytes(16);
        $iv = bin2hex(random_bytes(16));

        $keyRelative = sprintf(
            '%s/%s/enc.key',
            trim((string) config('filament-video-engine.hls.encryption.key_path', 'video-engine/keys'), '/'),
            $video->uuid,
        );

        $keyDisk = (string) (config('filament-video-engine.hls.encryption.key_disk')
            ?: $this->storage->outputDisk());

        $this->storage->put($keyDisk, $keyRelative, $key);

        $localKey = $workRoot.DIRECTORY_SEPARATOR.'enc.key';
        File::put($localKey, $key);

        $keyUri = $this->storage->publicUrl($keyDisk, $keyRelative);
        $keyInfoPath = $workRoot.DIRECTORY_SEPARATOR.'enc.keyinfo';

        File::put($keyInfoPath, implode("\n", [
            $keyUri,
            $localKey,
            $iv,
        ])."\n");

        return $keyInfoPath;
    }
}
