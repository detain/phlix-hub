<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Hub;

/**
 * Lightweight test double for {@see \Phlix\Hub\Http\Controllers\HubRestartController}
 * that overrides sendSignal to return a controlled result.
 *
 * @package Phlix\Hub\Tests\Unit\Hub
 */
final class TestableRestartController extends \Phlix\Hub\Http\Controllers\HubRestartController
{
    private ?bool $signalResult;

    public function __construct(string $pidFile, ?bool $signalResult)
    {
        parent::__construct($pidFile);
        $this->signalResult = $signalResult;
    }

    protected function sendSignal(int $pid, int $signal): bool
    {
        return $this->signalResult ?? parent::sendSignal($pid, $signal);
    }
}
