<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lightweight Lemon Squeezy license activation / validation with local cache.
 *
 * Successful results are cached for 30 days. Network failures fall back to the
 * last known valid status so production sites are not taken down by API outages.
 */
final class LicenseManager
{
    private const ACTIVATE_URL = 'https://api.lemonsqueezy.com/v1/licenses/activate';

    private const VALIDATE_URL = 'https://api.lemonsqueezy.com/v1/licenses/validate';

    private const CACHE_TTL_SECONDS = 60 * 60 * 24 * 30;

    private const NEGATIVE_CACHE_TTL_SECONDS = 60 * 60;

    private const HTTP_TIMEOUT_SECONDS = 8;

    /**
     * Static entry-point used by jobs, actions, and Filament UI.
     */
    public static function isLicensed(): bool
    {
        return app(self::class)->check();
    }

    /**
     * Whether the configured license key is valid for this instance.
     */
    public function check(): bool
    {
        $licenseKey = $this->licenseKey();

        if ($licenseKey === null) {
            return false;
        }

        $cacheKey = $this->cacheKey($licenseKey);
        $graceKey = $this->graceCacheKey($licenseKey);

        $cached = Cache::get($cacheKey);

        if (is_bool($cached)) {
            return $cached;
        }

        try {
            $valid = $this->verifyWithLemonSqueezy($licenseKey);

            if ($valid) {
                Cache::put($cacheKey, true, self::CACHE_TTL_SECONDS);
                Cache::forever($graceKey, true);

                return true;
            }

            Cache::put($cacheKey, false, self::NEGATIVE_CACHE_TTL_SECONDS);

            return false;
        } catch (Throwable $exception) {
            Log::warning('Filament Video Engine: License check failed; using grace cache if available.', [
                'message' => $exception->getMessage(),
            ]);

            return (bool) Cache::get($graceKey, false);
        }
    }

    /**
     * Clear cached license status (e.g. after rotating a key).
     */
    public function forget(): void
    {
        $licenseKey = $this->licenseKey();

        if ($licenseKey === null) {
            return;
        }

        Cache::forget($this->cacheKey($licenseKey));
        Cache::forget($this->instanceCacheKey($licenseKey));
        Cache::forget($this->graceCacheKey($licenseKey));
    }

    /**
     * Configured license key or null when missing.
     */
    private function licenseKey(): ?string
    {
        $key = config('filament-video-engine.license_key');

        if (! is_string($key)) {
            return null;
        }

        $key = trim($key);

        return $key !== '' ? $key : null;
    }

    /**
     * Activate (first time) or validate (cached instance) against Lemon Squeezy.
     *
     * @throws Throwable When the HTTP client cannot reach Lemon Squeezy.
     */
    private function verifyWithLemonSqueezy(string $licenseKey): bool
    {
        $instanceId = Cache::get($this->instanceCacheKey($licenseKey));

        if (is_string($instanceId) && $instanceId !== '') {
            return $this->validate($licenseKey, $instanceId);
        }

        return $this->activate($licenseKey);
    }

    /**
     * POST /v1/licenses/activate
     *
     * @throws Throwable
     */
    private function activate(string $licenseKey): bool
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(self::HTTP_TIMEOUT_SECONDS)
            ->post(self::ACTIVATE_URL, [
                'license_key' => $licenseKey,
                'instance_name' => $this->instanceName(),
            ]);

        if ($response->clientError()) {
            Log::warning('Filament Video Engine: License activation rejected.', [
                'status' => $response->status(),
                'error' => $response->json('error'),
            ]);

            return false;
        }

        if ($response->serverError() || $response->failed()) {
            $response->throw();
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        if (($payload['activated'] ?? false) !== true) {
            Log::warning('Filament Video Engine: License activation rejected.', [
                'error' => $payload['error'] ?? null,
            ]);

            return false;
        }

        $instanceId = data_get($payload, 'instance.id');

        if (is_string($instanceId) && $instanceId !== '') {
            Cache::forever($this->instanceCacheKey($licenseKey), $instanceId);
        }

        return true;
    }

    /**
     * POST /v1/licenses/validate — avoids consuming activation slots on refresh.
     *
     * @throws Throwable
     */
    private function validate(string $licenseKey, string $instanceId): bool
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(self::HTTP_TIMEOUT_SECONDS)
            ->post(self::VALIDATE_URL, [
                'license_key' => $licenseKey,
                'instance_id' => $instanceId,
            ]);

        if ($response->serverError() || ($response->failed() && ! $response->clientError())) {
            $response->throw();
        }

        if ($response->clientError()) {
            Cache::forget($this->instanceCacheKey($licenseKey));

            return $this->activate($licenseKey);
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        return ($payload['valid'] ?? false) === true;
    }

    /**
     * Stable instance name for Lemon Squeezy (host / app URL).
     */
    private function instanceName(): string
    {
        try {
            if (app()->bound('request')) {
                $host = request()->getHost();

                if (is_string($host) && $host !== '' && $host !== 'localhost') {
                    return $host;
                }
            }
        } catch (Throwable) {
            // Queue / CLI — fall through.
        }

        $appUrl = (string) config('app.url', '');
        $fromUrl = $appUrl !== '' ? parse_url($appUrl, PHP_URL_HOST) : null;

        if (is_string($fromUrl) && $fromUrl !== '') {
            return $fromUrl;
        }

        return gethostname() ?: 'filament-video-engine';
    }

    /**
     * Cache key unique to this license + domain.
     */
    private function cacheKey(string $licenseKey): string
    {
        return 'fve_license_'.hash('sha256', $this->instanceName().'|'.$licenseKey);
    }

    /**
     * Persistent last-known-good flag for API outage grace.
     */
    private function graceCacheKey(string $licenseKey): string
    {
        return 'fve_license_grace_'.hash('sha256', $this->instanceName().'|'.$licenseKey);
    }

    /**
     * Stored Lemon Squeezy instance id for validate calls.
     */
    private function instanceCacheKey(string $licenseKey): string
    {
        return 'fve_license_instance_'.hash('sha256', $this->instanceName().'|'.$licenseKey);
    }
}
