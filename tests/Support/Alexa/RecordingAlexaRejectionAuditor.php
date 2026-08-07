<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Alexa;

use Phlix\Hub\Alexa\AlexaRejectionAuditorInterface;

/**
 * An auditor that touches no database and records every call.
 *
 * The call log is not decoration. The production auditor writes one `audit_logs`
 * row per rejection, and the ORDER it runs in is load-bearing: the middleware's
 * IP-keyed rate limiter runs FIRST precisely so a flood cannot amplify into one
 * INSERT per malicious request. "Exactly one record for one rejection, and none
 * at all once the window is spent" is only observable by counting, so this
 * counts.
 *
 * It is also the no-op collaborator every OTHER Alexa suite needs: the signature
 * tests assert verification verdicts, and a real `AuditLogRepository` there would
 * demand a `Workerman\MySQL\Connection` those tests have no business owning.
 */
final class RecordingAlexaRejectionAuditor implements AlexaRejectionAuditorInterface
{
    /**
     * Every call, in order.
     *
     * @var list<array{
     *     code: string,
     *     detail: string,
     *     clientIp: string,
     *     userAgent: string|null,
     *     requestId: string|null
     * }>
     */
    public array $records = [];

    public function record(
        string $code,
        string $detail,
        string $clientIp,
        ?string $userAgent,
        ?string $requestId,
    ): void {
        $this->records[] = [
            'code' => $code,
            'detail' => $detail,
            'clientIp' => $clientIp,
            'userAgent' => $userAgent,
            'requestId' => $requestId,
        ];
    }

    /** Number of rejections recorded. */
    public function callCount(): int
    {
        return count($this->records);
    }

    /**
     * The rejection codes recorded, in order.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map(
            static fn (array $record): string => $record['code'],
            $this->records,
        );
    }
}
