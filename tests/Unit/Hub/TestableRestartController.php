<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

/**
 * Lightweight test double for {@see \Phlix\Hub\Http\Controllers\HubRestartController}.
 *
 * Overrides {@see sendSignal()} to return a controlled result and records every
 * `[pid, signal]` pair, and overrides {@see scheduleSignal()} so the deferred
 * reload is captured as a closure instead of arming a real Workerman timer
 * (there is no event loop under PHPUnit). Tests can then assert BOTH that the
 * ack is produced before the signal fires and that the signal actually sent is
 * the graceful SIGUSR2.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class TestableRestartController extends \Phlix\Hub\Http\Controllers\HubRestartController
{
    private ?bool $signalResult;

    /** @var list<array{int, int}> Every [pid, signal] passed to sendSignal(). */
    public array $signals = [];

    /** @var list<int> PIDs handed to scheduleSignal(), in call order. */
    public array $scheduled = [];

    /** @var (callable(): void)|null The deferred reload, pending manual firing. */
    private $pendingSignal = null;

    public function __construct(string $pidFile, ?bool $signalResult)
    {
        parent::__construct($pidFile);
        $this->signalResult = $signalResult;
    }

    protected function sendSignal(int $pid, int $signal): bool
    {
        $this->signals[] = [$pid, $signal];

        return $this->signalResult ?? parent::sendSignal($pid, $signal);
    }

    protected function scheduleSignal(int $pid): void
    {
        $this->scheduled[] = $pid;
        $this->pendingSignal = function () use ($pid): void {
            $this->sendSignal($pid, SIGUSR2);
        };
    }

    /**
     * Run the deferred reload, standing in for the one-shot Workerman timer.
     *
     * @return bool True when a deferred signal was pending and has now fired.
     */
    public function fireScheduledSignal(): bool
    {
        if ($this->pendingSignal === null) {
            return false;
        }

        ($this->pendingSignal)();
        $this->pendingSignal = null;

        return true;
    }
}
