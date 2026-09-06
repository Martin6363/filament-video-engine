<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Enums;

/**
 * Lifecycle states for video media and individual conversion jobs.
 */
enum TranscodingStatusEnum: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Uploading = 'uploading';
    case Processing = 'processing';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Whether the status represents an in-flight operation.
     */
    public function isInProgress(): bool
    {
        return match ($this) {
            self::Queued, self::Uploading, self::Processing => true,
            default => false,
        };
    }

    /**
     * Whether a retry action is meaningful for this status.
     */
    public function canRetry(): bool
    {
        return match ($this) {
            self::Failed, self::Partial, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Filament / UI color hint.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending, self::Queued => 'gray',
            self::Uploading, self::Processing => 'info',
            self::Completed => 'success',
            self::Partial => 'warning',
            self::Failed, self::Cancelled => 'danger',
        };
    }

    /**
     * Human-readable label for forms and tables.
     */
    public function label(): string
    {
        return __('filament-video-engine::messages.status.'.$this->value);
    }
}
