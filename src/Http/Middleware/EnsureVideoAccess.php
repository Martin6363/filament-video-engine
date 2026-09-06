<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces signed URLs or Sanctum/Passport token auth for private video APIs.
 */
final class EnsureVideoAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->securityEnabled()) {
            return $next($request);
        }

        $driver = $this->driver();

        return match ($driver) {
            'none' => $next($request),
            'signed' => $this->assertSigned($request, $next),
            'sanctum' => $this->assertAuthenticated($request, $next, 'sanctum'),
            'passport' => $this->assertAuthenticated($request, $next, 'api'),
            default => $this->assertSigned($request, $next),
        };
    }

    /**
     * Whether API security checks are active.
     */
    private function securityEnabled(): bool
    {
        if (config()->has('filament-video-engine.api.security.enabled')) {
            return (bool) config('filament-video-engine.api.security.enabled');
        }

        return (string) config('filament-video-engine.api.auth.driver', 'signed') !== 'none';
    }

    /**
     * Resolve the auth driver from security or legacy auth config.
     */
    private function driver(): string
    {
        $driver = config('filament-video-engine.api.security.driver');

        if (is_string($driver) && $driver !== '') {
            return $driver;
        }

        return (string) config('filament-video-engine.api.auth.driver', 'signed');
    }

    /**
     * Require a valid temporary signed URL.
     *
     * @param  Closure(Request): Response  $next
     */
    private function assertSigned(Request $request, Closure $next): Response
    {
        if (! $request->hasValidSignature()) {
            abort(403, __('filament-video-engine::messages.api.invalid_signature'));
        }

        return $next($request);
    }

    /**
     * Require an authenticated user via the given guard.
     *
     * @param  Closure(Request): Response  $next
     */
    private function assertAuthenticated(Request $request, Closure $next, string $guard): Response
    {
        if ($request->user($guard) === null) {
            abort(401, __('filament-video-engine::messages.api.unauthenticated'));
        }

        return $next($request);
    }
}
