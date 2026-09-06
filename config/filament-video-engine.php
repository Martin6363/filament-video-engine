<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Package switch
    |--------------------------------------------------------------------------
    */

    'enabled' => (bool) env('FILAMENT_VIDEO_ENGINE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | License (Lemon Squeezy)
    |--------------------------------------------------------------------------
    |
    | Set FILAMENT_VIDEO_ENGINE_LICENSE_KEY in .env. The package activates /
    | validates the key against Lemon Squeezy and caches the result locally.
    |
    */

    'license_key' => env('FILAMENT_VIDEO_ENGINE_LICENSE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Storage disks
    |--------------------------------------------------------------------------
    |
    | Separate input (temp uploads / chunk assembly) from output (CDN / public).
    | Compatible with local, s3, R2, and DigitalOcean Spaces disks.
    |
    */

    'disks' => [
        'input' => env('FILAMENT_VIDEO_ENGINE_INPUT_DISK', 'local'),
        'output' => env('FILAMENT_VIDEO_ENGINE_OUTPUT_DISK', 'public'),
        'temp' => env('FILAMENT_VIDEO_ENGINE_TEMP_DISK', 'local'),
    ],

    'paths' => [
        'uploads' => 'video-engine/uploads',
        'originals' => 'video-engine/originals',
        'hls' => 'video-engine/hls',
        'posters' => 'video-engine/posters',
        'watermarks' => 'video-engine/watermarks',
        'chunks' => 'video-engine/chunks',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */

    'queue' => [
        'connection' => env('FILAMENT_VIDEO_ENGINE_QUEUE_CONNECTION'),
        'queue' => env('FILAMENT_VIDEO_ENGINE_QUEUE', 'video-engine'),
    ],

    /*
    |--------------------------------------------------------------------------
    | FFmpeg
    |--------------------------------------------------------------------------
    */

    'ffmpeg' => [
        'enabled' => (bool) env('FILAMENT_VIDEO_ENGINE_FFMPEG_ENABLED', true),
        'binary' => env('FFMPEG_BINARY'),
        'ffprobe' => env('FFPROBE_BINARY'),
        'timeout' => (int) env('FFMPEG_TIMEOUT', 3600),
        'threads' => env('FFMPEG_THREADS'),
        'video_codec' => env('FFMPEG_VIDEO_CODEC', 'libx264'),
        'audio_codec' => env('FFMPEG_AUDIO_CODEC', 'aac'),
        'crf' => (int) env('FFMPEG_CRF', 23),
        'preset' => env('FFMPEG_PRESET', 'medium'),
        'pixel_format' => env('FFMPEG_PIXEL_FORMAT', 'yuv420p'),
        'audio_bitrate' => env('FFMPEG_AUDIO_BITRATE', '128k'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Qualities (multi-resolution)
    |--------------------------------------------------------------------------
    */

    'qualities' => [
        '240p' => ['height' => 240, 'max_bitrate' => '400k', 'audio_bitrate' => '64k'],
        '360p' => ['height' => 360, 'max_bitrate' => '800k', 'audio_bitrate' => '96k'],
        '480p' => ['height' => 480, 'max_bitrate' => '1400k', 'audio_bitrate' => '128k'],
        '720p' => ['height' => 720, 'max_bitrate' => '2800k', 'audio_bitrate' => '128k'],
        '1080p' => ['height' => 1080, 'max_bitrate' => '5000k', 'audio_bitrate' => '192k'],
        '4k' => ['height' => 2160, 'max_bitrate' => '15000k', 'audio_bitrate' => '192k'],
    ],

    'default_qualities' => ['360p', '480p', '720p', '1080p'],

    /*
    |--------------------------------------------------------------------------
    | HLS
    |--------------------------------------------------------------------------
    */

    'hls' => [
        'segment_duration' => (int) env('FILAMENT_VIDEO_ENGINE_HLS_SEGMENT', 6),
        'playlist_type' => 'vod',
        'encryption' => [
            'enabled' => (bool) env('FILAMENT_VIDEO_ENGINE_HLS_ENCRYPTION', false),
            'key_disk' => env('FILAMENT_VIDEO_ENGINE_HLS_KEY_DISK'),
            'key_path' => 'video-engine/keys',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Thumbnails / posters
    |--------------------------------------------------------------------------
    */

    'thumbnail' => [
        'default_timestamp' => (float) env('FILAMENT_VIDEO_ENGINE_THUMB_AT', 5.0),
        'format' => env('FILAMENT_VIDEO_ENGINE_THUMB_FORMAT', 'jpg'),
        'quality' => (int) env('FILAMENT_VIDEO_ENGINE_THUMB_QUALITY', 3),
        'accepted_mimetypes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Watermark
    |--------------------------------------------------------------------------
    */

    'watermark' => [
        'enabled' => (bool) env('FILAMENT_VIDEO_ENGINE_WATERMARK', false),
        'path' => env('FILAMENT_VIDEO_ENGINE_WATERMARK_PATH'),
        'position' => env('FILAMENT_VIDEO_ENGINE_WATERMARK_POSITION', 'bottom-right'),
        'opacity' => (float) env('FILAMENT_VIDEO_ENGINE_WATERMARK_OPACITY', 0.6),
        'margin' => (int) env('FILAMENT_VIDEO_ENGINE_WATERMARK_MARGIN', 20),
        'max_width_percent' => (float) env('FILAMENT_VIDEO_ENGINE_WATERMARK_MAX_WIDTH_PERCENT', 12),
        'accepted_mimetypes' => ['image/png', 'image/webp', 'image/jpeg'],
        'editor_aspect_ratios' => [
            null,
            '1:1',
            '4:3',
            '16:9',
            '3:1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Failure cleanup
    |--------------------------------------------------------------------------
    */

    'cleanup' => [
        'delete_temp_on_failure' => (bool) env('FILAMENT_VIDEO_ENGINE_CLEANUP_ON_FAILURE', true),
        'delete_remote_temp_copies' => true,
        'delete_files_on_force_delete' => (bool) env('FILAMENT_VIDEO_ENGINE_DELETE_FILES_ON_FORCE_DELETE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    'logging' => [
        'channel' => env('FILAMENT_VIDEO_ENGINE_LOG_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads (chunked)
    |--------------------------------------------------------------------------
    */

    'uploads' => [
        'chunk_size_kilobytes' => (int) env('FILAMENT_VIDEO_ENGINE_CHUNK_KB', 5120),
        'max_upload_kilobytes' => (int) env('FILAMENT_VIDEO_ENGINE_MAX_UPLOAD_KB', 5242880),
        'accepted_mimetypes' => [
            'video/mp4',
            'video/quicktime',
            'video/x-msvideo',
            'video/webm',
            'video/x-matroska',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP API
    |--------------------------------------------------------------------------
    */

    'api' => [
        'enabled' => (bool) env('FILAMENT_VIDEO_ENGINE_API_ENABLED', true),
        'prefix' => env('FILAMENT_VIDEO_ENGINE_API_PREFIX', 'api/v1'),
        'middleware' => ['api'],

        /*
        | When security.enabled is true, requests must satisfy the configured
        | driver (signed URL, Sanctum, or Passport). Set enabled to false for
        | local/public manifests only.
        */
        'security' => [
            'enabled' => (bool) env('FILAMENT_VIDEO_ENGINE_API_SECURITY', true),
            'driver' => env('FILAMENT_VIDEO_ENGINE_API_AUTH', 'signed'), // signed|sanctum|passport|none
            'signed_ttl_minutes' => (int) env('FILAMENT_VIDEO_ENGINE_SIGNED_TTL', 60),
        ],

        // Legacy alias — prefer api.security.* going forward.
        'auth' => [
            'driver' => env('FILAMENT_VIDEO_ENGINE_API_AUTH', 'signed'),
            'signed_ttl_minutes' => (int) env('FILAMENT_VIDEO_ENGINE_SIGNED_TTL', 60),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Player
    |--------------------------------------------------------------------------
    */

    'player' => [
        'driver' => env('FILAMENT_VIDEO_ENGINE_PLAYER', 'plyr'), // plyr|videojs
        'speeds' => [0.5, 0.75, 1.0, 1.25, 1.5, 1.75, 2.0],
        'default_quality' => 'auto',
        'keyboard' => true,
        'pip' => true,
        'theme' => 'filament',
    ],

    /*
    |--------------------------------------------------------------------------
    | Filament
    |--------------------------------------------------------------------------
    */

    'filament' => [
        'register_resource' => (bool) env('FILAMENT_VIDEO_ENGINE_REGISTER_RESOURCE', true),
        'navigation_group' => 'Video Engine Demo',
        'navigation_sort' => 20,
        'navigation_icon' => 'heroicon-o-film',
        'polling_interval' => '3s',
    ],

];
