<?php

declare(strict_types=1);

namespace Martin6363\FilamentVideoEngine\Services\Ffmpeg;

use Closure;
use Martin6363\FilamentVideoEngine\Exceptions\FfmpegException;
use Symfony\Component\Process\Process;

/**
 * Runs FFmpeg / FFProbe via Symfony Process with optional live progress callbacks.
 */
final class FfmpegProcessRunner
{
    public function __construct(
        private readonly FfmpegProgressParser $progressParser,
    ) {}

    /**
     * Execute a command and return combined stdout.
     *
     * @param  list<string>  $command
     * @param  Closure(float): void|null  $onProgress  Receives elapsed media seconds.
     */
    public function run(array $command, ?Closure $onProgress = null): string
    {
        $timeout = (int) config('filament-video-engine.ffmpeg.timeout', 3600);

        $process = new Process($command);
        $process->setTimeout($timeout > 0 ? $timeout : null);

        $stdout = '';
        $stderr = '';

        $process->run(function (string $type, string $buffer) use (&$stdout, &$stderr, $onProgress): void {
            if ($type === Process::OUT) {
                $stdout .= $buffer;
            } else {
                $stderr .= $buffer;
            }

            if ($onProgress === null) {
                return;
            }

            $elapsed = $this->progressParser->parseTimeSeconds($buffer);

            if ($elapsed !== null) {
                $onProgress($elapsed);
            }
        });

        if (! $process->isSuccessful()) {
            throw FfmpegException::processFailed(
                $this->summarizeCommand($command),
                $process->getExitCode() ?? 1,
                $stderr !== '' ? $stderr : $process->getErrorOutput(),
            );
        }

        return $stdout !== '' ? $stdout : $process->getOutput();
    }

    /**
     * Run with FFmpeg `-progress pipe:1` for denser progress events.
     *
     * @param  list<string>  $command  Must not already contain -progress.
     * @param  Closure(float): void|null  $onProgress
     */
    public function runWithProgress(array $command, ?Closure $onProgress = null): string
    {
        if ($onProgress === null) {
            return $this->run($command);
        }

        $augmented = $this->injectProgressPipe($command);

        return $this->run($augmented, $onProgress);
    }

    /**
     * Insert `-progress pipe:1 -nostats` after the binary for machine-readable progress.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    private function injectProgressPipe(array $command): array
    {
        if ($command === []) {
            return $command;
        }

        $binary = array_shift($command);

        return array_merge([$binary, '-progress', 'pipe:1', '-nostats'], $command);
    }

    /**
     * Shorten a command for exception messages.
     *
     * @param  list<string>  $command
     */
    private function summarizeCommand(array $command): string
    {
        $flat = implode(' ', $command);

        return strlen($flat) > 240 ? substr($flat, 0, 237).'...' : $flat;
    }
}
