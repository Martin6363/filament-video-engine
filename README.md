# Filament Video Engine

Self-hosted HLS transcoding and adaptive playback for Laravel + Filament.

Upload a source video once. A queue worker runs FFmpeg, builds a multi-bitrate HLS ladder, extracts (or accepts) a poster, and optionally burns in a watermark. Filament shows live progress. A Plyr + hls.js player plays the result. Everything stays on your disks and your servers.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/martin6363/filament-video-engine.svg?style=flat-square)](https://packagist.org/packages/martin6363/filament-video-engine)
[![License](https://img.shields.io/packagist/l/martin6363/filament-video-engine.svg?style=flat-square)](https://github.com/martin6363/filament-video-engine/blob/main/LICENSE.md)
[![PHP Version](https://img.shields.io/packagist/php-v/martin6363/filament-video-engine.svg?style=flat-square)](https://packagist.org/packages/martin6363/filament-video-engine)
[![Watch the demo](https://img.shields.io/badge/YouTube-Watch%20demo-FF0000?style=flat-square&logo=youtube&logoColor=white)](https://youtu.be/RbWqhhzSdm8?si=pyVtjWNsGyLJlEin)

<p align="center">
  <strong>
    <a href="https://youtu.be/RbWqhhzSdm8?si=pyVtjWNsGyLJlEin" target="_blank" rel="noopener noreferrer">▶ Watch the plugin demo on YouTube</a>
  </strong>
  <br />
  <sub>Opens in a new browser tab</sub>
</p>

---

## Table of contents

- [Demo](#demo)
- [What you get](#what-you-get)
- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [License key](#license-key)
- [Quick start](#quick-start)
- [VideoEnginePicker](#videoenginepicker)
- [Posters](#posters)
- [Watermarks](#watermarks)
- [Videos admin](#videos-admin)
- [Front-end player](#front-end-player)
- [Queue worker](#queue-worker)
- [Programmatic usage](#programmatic-usage)
- [HTTP API](#http-api)
- [Configuration](#configuration)
- [Smart resolution](#smart-resolution)
- [Storage and cleanup](#storage-and-cleanup)
- [Security](#security)
- [Software license](#software-license)
- [Support](#support)

---

## Demo

See Filament Video Engine in action — upload, live progress, watermark, and HLS playback:

<p align="center">
  <a href="https://youtu.be/RbWqhhzSdm8?si=pyVtjWNsGyLJlEin" target="_blank" rel="noopener noreferrer"><strong>▶ Watch the demo on YouTube</strong></a>
  <br />
  <sub>Opens in a new browser tab</sub>
</p>

---

## What you get

| Area | What it does |
|------|----------------|
| Transcoding | Multi-bitrate HLS (240p–4K) via FFmpeg |
| Filament | `VideoEnginePicker` — upload, poster, watermark, live progress |
| Admin | Videos resource — preview, retry, regenerate, watermark re-apply, trash |
| Posters | Custom image upload, or auto frame extract; restore via queue when custom is cleared |
| Watermarks | Per-video image upload + layout controls (config supplies defaults only) |
| License | Lemon Squeezy key — required to unlock picker + queue processing |
| Player | Blade `<x-video-engine-player>` with ABR, quality, speed |
| API | Manifest, progress, quality endpoints (optional signed URLs) |
| Storage | Separate input / output disks (local, S3, R2, Spaces, …) |

No third-party transcoding SaaS. You run FFmpeg on a worker.

---

## How it works

```
Upload source video (Filament picker or your code)
        │
        ▼
VideoMedia created (UUID, status: queued)
        │
        ▼
Queue worker (video-engine)
  • Probe source (width, height, duration)
  • Poster: custom upload or extract a frame
  • Encode each allowed quality (never above source height)
  • Burn watermark into each rendition (if enabled + image set)
  • Write HLS segments + master.m3u8
        │
        ▼
Status: completed  →  Player / API serve streams
```

**Important:** Watermark and HLS segments are baked in at encode time. Changing the watermark image later updates the database only — use **Apply watermark to streams** (or regenerate qualities) to refresh existing playback.

---

## Requirements

| Dependency | Version |
|------------|---------|
| PHP | 8.3+ |
| Laravel | 11 / 12 / 13 |
| Filament | v4 / v5 |
| FFmpeg + FFprobe | On the queue worker `PATH`, or set in config |
| Queue | Redis / database / SQS recommended in production |

Optional: Sanctum or Passport for API tokens; Flysystem S3 for cloud disks.

---

## Installation

### 1. Require the package

```bash
composer require martin6363/filament-video-engine
```

### 2. Publish config and assets

```bash
php artisan vendor:publish --tag=filament-video-engine-config
php artisan vendor:publish --tag=filament-video-engine-assets
```

Optional:

```bash
php artisan vendor:publish --tag=filament-video-engine-translations
php artisan vendor:publish --tag=filament-video-engine-views
```

### 3. Migrate

```bash
php artisan migrate
```

Creates `video_media` and `video_conversions`.

### 4. Register the Filament plugin

In your panel provider (e.g. `AdminPanelProvider`):

```php
use Martin6363\FilamentVideoEngine\FilamentVideoEnginePlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugins([
            FilamentVideoEnginePlugin::make(),
        ]);
}
```

Hide the built-in Videos resource if you manage media yourself:

```php
FilamentVideoEnginePlugin::make()
    ->registerResource(false);
```

### 5. Add your license key

Purchase a license, then set the key in `.env`:

```env
FILAMENT_VIDEO_ENGINE_LICENSE_KEY=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
```

Without a valid key the package stays installed, but the picker and encoding pipeline stay locked (see [License key](#license-key)).

### 6. Link storage and check FFmpeg

```bash
php artisan storage:link
ffmpeg -version
ffprobe -version
```

Useful `.env` keys:

```env
FFMPEG_BINARY=/usr/bin/ffmpeg
FFPROBE_BINARY=/usr/bin/ffprobe
FFMPEG_TIMEOUT=3600
FILAMENT_VIDEO_ENGINE_LICENSE_KEY=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
FILAMENT_VIDEO_ENGINE_QUEUE=video-engine
FILAMENT_VIDEO_ENGINE_INPUT_DISK=local
FILAMENT_VIDEO_ENGINE_OUTPUT_DISK=public
```

### 7. Start the worker

```bash
php artisan queue:work --queue=video-engine --timeout=3600
```

Keep this process running wherever videos are encoded.

---

## License key

Commercial use is gated with a **Lemon Squeezy** license key.

### Setup

1. Buy a license for Filament Video Engine.
2. Copy the key into `.env`:

```env
FILAMENT_VIDEO_ENGINE_LICENSE_KEY=your-lemonsqueezy-license-key
```

3. The value is read as `config('filament-video-engine.license_key')`.

### What the package does with the key

| Step | Behaviour |
|------|-----------|
| First check | Activates the key via Lemon Squeezy (`instance_name` = your app host) |
| Later checks | Validates the stored instance (avoids burning extra activation slots) |
| Cache | Successful validation is cached locally for **30 days** |
| API outage | If Lemon Squeezy is unreachable, the last known **valid** status is used (grace) so production does not go down mid-encode |

### When the key is missing or invalid

| Surface | Behaviour |
|---------|-----------|
| `VideoEnginePicker` | Only a warning banner is shown — upload / poster / watermark controls are hidden |
| Queue jobs | Transcoding, quality regenerate, watermark re-apply, and poster restore are halted |
| `VideoMedia` | Failed attempts are marked `failed` with a license error message |

Banner copy:

> Filament Video Engine license key is missing or invalid. Please set FILAMENT_VIDEO_ENGINE_LICENSE_KEY in your .env file.

### After rotating a key

Clear the local license cache (or wait for TTL), then set the new env value and reload config:

```bash
php artisan config:clear
php artisan cache:clear
```

Or call `app(\Martin6363\FilamentVideoEngine\Services\LicenseManager::class)->forget();`

---

## Quick start

### 1. Trait on your model

```php
use Martin6363\FilamentVideoEngine\Concerns\HasVideoEngine;

class Movie extends Model
{
    use HasVideoEngine;
}
```

### 2. Picker on the Filament form

```php
use Martin6363\FilamentVideoEngine\Filament\Forms\Components\VideoEnginePicker;

VideoEnginePicker::make('video')
    ->label('Video')
    ->qualities(['1080p', '720p', '480p', '360p'])
    ->posterControls()
    ->watermarkControls()
    ->columnSpanFull()
    ->titleFromRecord(fn ($record) => $record?->title);
```

### 3. Save in admin

1. Create or edit the record.
2. Choose a source video (optional poster / watermark).
3. Save — upload + queue start automatically.
4. Watch progress in the picker until `completed`.

### 4. Play

```blade
<x-video-engine-player :video="$movie" />
```

```php
if ($movie->hasHlsStream()) {
    $manifest = $movie->getVideoManifestUrl();
    $poster = $movie->getPosterUrl();
}
```

---

## VideoEnginePicker

One field for source upload, poster, watermark, progress, and polymorphic attach.

- Form state is the video UUID (`string|null`).
- The field is not dehydrated; work runs on parent form save.
- Disks, mime types, and size limits come from package config — you do not wire separate `FileUpload` disks.

### Fluent API

| Method | Default | Purpose |
|--------|---------|---------|
| `qualities([...])` | `default_qualities` in config | Ladder to encode |
| `posterControls()` | `true` | Poster upload + extract time |
| `posterControls(false)` | — | Hide UI; auto-extract still runs |
| `watermarkControls()` | `false` | Per-video watermark upload + layout |
| `watermarkControls(true, ['1:1', '16:9'])` | — | Crop ratios in the image editor |
| `pollingInterval('3s')` | Config | Poll while processing |
| `titleFromRecord(fn)` | `null` | Title on `VideoMedia` |
| `columnSpanFull()` | — | Full-width layout in sections |

### On save

1. Source file → input disk  
2. `VideoMedia` created/updated and linked  
3. Poster / watermark settings stored  
4. Job(s) dispatched to `video-engine`  
5. Picker polls until done or failed  

---

## Posters

| Mode | Behaviour |
|------|-----------|
| **Auto** | FFmpeg extracts a frame at `thumbnail.default_timestamp` (or the time chosen in the picker) |
| **Custom** | Admin uploads an image; `poster_is_custom = true` |
| **Clear custom** | Restore is **queued** on `video-engine` — save stays fast; a new frame is extracted from the source |
| **Replace custom** | New file is stored; the previous poster file is deleted from the output disk |

Videos admin **View** shows the poster image and an HLS preview player (lazy segment load). **Edit** allows updating the poster image only.

---

## Watermarks

Watermarks are **per video**, driven by an **image upload** in the picker — not a single fixed file path in day-to-day use.

### Recommended flow (Filament)

1. Enable controls: `->watermarkControls()` on `VideoEnginePicker`.
2. Toggle watermark on.
3. **Upload** a PNG/WebP/JPEG (image editor + optional crop ratios).
4. Set position, opacity, margin, and size (% of frame width).
5. Save the form — settings are stored on that `VideoMedia`.
6. On first encode, FFmpeg burns the uploaded image into every HLS quality.

### Config defaults (fallback only)

Config / `.env` supply defaults when a video has no override yet (or when you encode without picker UI):

```env
FILAMENT_VIDEO_ENGINE_WATERMARK=false
FILAMENT_VIDEO_ENGINE_WATERMARK_PATH=   # optional global fallback image path on the output disk
FILAMENT_VIDEO_ENGINE_WATERMARK_POSITION=bottom-right
FILAMENT_VIDEO_ENGINE_WATERMARK_OPACITY=0.6
FILAMENT_VIDEO_ENGINE_WATERMARK_MARGIN=20
FILAMENT_VIDEO_ENGINE_WATERMARK_MAX_WIDTH_PERCENT=12
```

Prefer uploading in the picker for flexibility. A global `WATERMARK_PATH` is only a fallback, not the primary workflow.

### After you change an existing watermark

Saving the new image/settings does **not** rewrite old HLS files by itself.

1. Use **Apply watermark to streams** in the picker footer or Videos admin row menu, **or**
2. **Regenerate quality** for selected renditions.

Both re-encode from the original source — no re-upload of the video file.

---

## Videos admin

Enabled when `filament.register_resource` is `true` (default).

```env
FILAMENT_VIDEO_ENGINE_REGISTER_RESOURCE=true
```

```php
'filament' => [
    'navigation_group' => 'Media',
    'navigation_sort' => 20,
    'polling_interval' => '3s',
],
```

| Action | Use when |
|--------|----------|
| **View** | Preview player + poster + metadata |
| **Edit** | Title / replace poster image |
| **Apply watermark to streams** | Watermark image or layout changed |
| **Retry transcoding** | Failed / partial / cancelled |
| **Regenerate quality** | Re-encode selected qualities (above-source heights disabled) |
| **Trash / Force delete** | Soft delete keeps files; force delete can purge storage |

Row actions are grouped in Filament’s `ActionGroup` (⋮ menu).

---

## Front-end player

```blade
{{-- Model with HasVideoEngine, VideoMedia, or UUID --}}
<x-video-engine-player :video="$movie" />

{{-- Admin-style lazy preview (segments after play) --}}
<x-video-engine-player :video="$movie" mode="preview" />
```

- Adaptive bitrate (hls.js; native HLS on Safari)  
- Quality + speed in Plyr settings  
- Poster, keyboard, PiP, fullscreen  
- Assets under `public/vendor/filament-video-engine/`  

### Model helpers (`HasVideoEngine`)

| Method | Returns |
|--------|---------|
| `getPrimaryVideoMedia()` | Latest `VideoMedia` |
| `hasHlsStream()` | Master playlist ready |
| `getVideoManifestUrl()` | Manifest API URL |
| `getVideoQuality('720p')` | Single quality URL |
| `getPosterUrl()` | Poster URL |
| `videoMedia()` | MorphMany |

---

## Queue worker

All heavy work runs asynchronously on the configured queue (default name `video-engine`):

- Full transcoding  
- Per-quality regenerate / watermark re-apply  
- Poster restore after clearing a custom image  

```bash
php artisan queue:work --queue=video-engine --timeout=3600
```

```env
FILAMENT_VIDEO_ENGINE_QUEUE=video-engine
FILAMENT_VIDEO_ENGINE_QUEUE_CONNECTION=redis
```

Use a dedicated worker in production. Set `--timeout` ≥ `ffmpeg.timeout`.

---

## Programmatic usage

```php
use Martin6363\FilamentVideoEngine\Actions\UploadVideoAction;
use Martin6363\FilamentVideoEngine\Actions\DispatchTranscodingAction;
use Martin6363\FilamentVideoEngine\Actions\RegenerateQualityAction;
use Martin6363\FilamentVideoEngine\Actions\ReapplyWatermarkAction;
use Martin6363\FilamentVideoEngine\Actions\RestoreExtractedVideoPosterAction;
use Martin6363\FilamentVideoEngine\Services\VideoEngineManager;

$media = app(UploadVideoAction::class)->execute(
    file: $request->file('video'),
    videoable: $movie,
);

app(DispatchTranscodingAction::class)->execute($media);

app(RegenerateQualityAction::class)->executeMany($media, ['720p', '1080p']);
app(ReapplyWatermarkAction::class)->execute($media);

// Clear custom poster → queue FFmpeg extract (same as admin)
app(RestoreExtractedVideoPosterAction::class)->queue($media);

$manifest = app(VideoEngineManager::class)->manifest($media);
$progress = app(VideoEngineManager::class)->progress($media);
```

---

## HTTP API

Prefix: `/api/v1` (configurable).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/videos/{uuid}/progress` | Status, %, step, errors |
| GET | `/api/v1/videos/{uuid}/manifest` | Master URL, poster, qualities |
| GET | `/api/v1/videos/{uuid}/qualities/{quality}` | One rendition |

When `api.security.enabled` is `true`, use signed URLs or Sanctum/Passport.

```php
use Martin6363\FilamentVideoEngine\Services\Security\SignedUrlGenerator;

$url = app(SignedUrlGenerator::class)->manifestUrl($videoMedia);
```

Local / public demo:

```env
FILAMENT_VIDEO_ENGINE_API_SECURITY=false
```

---

## Configuration

Published file: `config/filament-video-engine.php`.

| Key | Purpose |
|-----|---------|
| `enabled` | Package on/off |
| `license_key` | Lemon Squeezy license key (`FILAMENT_VIDEO_ENGINE_LICENSE_KEY`) |
| `disks.*` | `input`, `output`, `temp` |
| `paths.*` | Uploads, HLS, posters, watermarks, chunks |
| `queue.*` | Connection + queue name |
| `ffmpeg.*` | Binaries, codec, CRF, preset, timeout |
| `qualities` / `default_qualities` | Ladder |
| `hls.*` | Segment length, optional AES-128 |
| `thumbnail.*` | Default extract time, format |
| `watermark.*` | Defaults + accepted mime types (upload is primary) |
| `uploads.*` | Max size, chunk size, video mime types |
| `api.*` | Routes + security |
| `player.*` | Speeds, theme, PiP |
| `filament.*` | Resource, nav, polling |
| `cleanup.*` | Delete files on force delete / failure |

```env
FILAMENT_VIDEO_ENGINE_ENABLED=true
FILAMENT_VIDEO_ENGINE_LICENSE_KEY=your-lemonsqueezy-license-key
FILAMENT_VIDEO_ENGINE_INPUT_DISK=local
FILAMENT_VIDEO_ENGINE_OUTPUT_DISK=public
FILAMENT_VIDEO_ENGINE_QUEUE=video-engine
FILAMENT_VIDEO_ENGINE_API_SECURITY=true
FILAMENT_VIDEO_ENGINE_API_AUTH=signed
FILAMENT_VIDEO_ENGINE_SIGNED_TTL=60
FILAMENT_VIDEO_ENGINE_DELETE_FILES_ON_FORCE_DELETE=true
FFMPEG_PRESET=medium
FFMPEG_CRF=23
```

Without a valid `FILAMENT_VIDEO_ENGINE_LICENSE_KEY`, the picker stays locked and encoding jobs will not run. Details: [License key](#license-key).

---

## Smart resolution

Source height is probed before encode.

**Example:** Upload is 480p; defaults include 720p and 1080p.

1. Dimensions stored on `VideoMedia`  
2. Higher targets skipped (`source_resolution_lower`) — no upscaling  
3. Allowed qualities encode → status `completed`  
4. Manifest / admin expose source and skipped list  

`partial` means real encode failures, not skipped upscales. The same rule disables invalid options in **Regenerate quality**.

---

## Storage and cleanup

| Action | Database | Files |
|--------|----------|-------|
| Soft delete | Trashed | Kept (restorable) |
| Force delete | Removed | Purged if `cleanup.delete_files_on_force_delete` is true |

Force delete removes originals, posters, HLS tree, per-video watermark uploads, and encryption keys for that video.

Tip: keep originals on a private `input` disk; serve HLS + posters from `public` or a CDN `output` disk.

---

## Security

Please report vulnerabilities privately — see [SECURITY.md](SECURITY.md).

Do **not** open a public GitHub issue for security reports. Email **martin.khachatryan.2024@gmail.com**.

---

## Software license

This plugin is a **paid commercial product**. A Lemon Squeezy license key is required to activate and use it.

See [LICENSE.md](LICENSE.md) and [License key](#license-key).

---

## Support

| | |
|---|---|
| Author | Martin Khachatryan — [martin.khachatryan.2024@gmail.com](mailto:martin.khachatryan.2024@gmail.com) |

Built with Laravel, Filament, FFmpeg, Plyr, and hls.js.
