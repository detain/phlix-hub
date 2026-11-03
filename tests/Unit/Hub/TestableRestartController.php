<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

use Phlix\Hub\Common\Logger\StructuredLogger;

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

    /**
     * Witness handed to the Minor 55 identity gate; null = unreadable
     * /proc entry. Defaults to the honest hub master's argv so the
     * pre-existing success-path laws keep testing what they tested.
     */
    public ?string $cmdline = "php\0/opt/phlix-hub/start.php\0start\0";

    public function __construct(string $pidFile, ?bool $signalResult, StructuredLogger $logger)
    {
        parent::__construct($pidFile, $logger);
        $this->signalResult = $signalResult;
    }

    protected function readProcessCmdline(int $pid): ?string
    {
        return $this->cmdline;
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
