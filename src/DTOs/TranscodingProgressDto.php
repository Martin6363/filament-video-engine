<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\DTOs;

use Martin6363\FilamentVideoEngine\Enums\TranscodingStatusEnum;

/**
 * Live polling payload for Filament VideoEnginePicker.
 */
readonly class TranscodingProgressDto
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        public string $uuid,
        public TranscodingStatusEnum $status,
        public float $progressPercent,
        public ?string $currentStep = null,
        public ?string $queueStatus = null,
        public array $errors = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'progress_percent' => round($this->progressPercent, 2),
            'current_step' => $this->currentStep,
            'queue_status' => $this->queueStatus,
            'errors' => $this->errors,
            'is_in_progress' => $this->status->isInProgress(),
            'can_retry' => $this->status->canRetry(),
        ];
    }
}
