<?php

/**
 * Phlix hub component: Alexa.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Alexa;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Hub\AuditLogRepository;
use Throwable;

/**
 * Writes Alexa gate rejections to the `audit_logs` table and the log (S91).
 *
 * ## Why it takes the repository and not `AuditLogger`
 *
 * 🐛 `AuditLogger` is bound in `AuthServicesProvider` as
 * `new AuditLogger(LoggerFactory::get(LogChannels::AUDIT))` — its optional
 * `?AuditLogRepository` argument is never passed. There is exactly ONE
 * `AuditLogger::class` definition in the container and it is an explicit
 * `factory()`, so nothing autowires the repository in either. The consequence is
 * that **no `AuditLogger` event has ever reached the `audit_logs` table on the
 * hub**; the comment in `HubServicesProvider` claiming PHP-DI auto-injects it is
 * wrong. That is a pre-existing, estate-wide finding reported by S91 and NOT
 * fixed here — repointing the shared logger is a change with its own blast
 * radius. This auditor injects {@see AuditLogRepository} directly so the one
 * thing this step promises to persist actually is persisted, rather than
 * inheriting a silent no-op.
 *
 * ## Both sinks, deliberately
 *
 * A row in `audit_logs` is queryable by the admin console and survives log
 * rotation; a `StructuredLogger` warning is visible to an operator tailing the
 * auth channel during an incident. Neither replaces the other, and writing only
 * the row would make the gate silent in exactly the situation an operator is
 * watching the logs.
 *
 * ## Never throws
 *
 * Both sinks are wrapped. This runs inside the signature gate's fail-closed path,
 * where an exception would be swallowed by the gate's own `catch (\Throwable)`
 * and would relabel a precise rejection code (`ALEXA_SIGNATURE_INVALID`) as the
 * generic `ALEXA_VERIFICATION_ERROR` — an audit sink that can corrupt the verdict
 * it is auditing. A database that is down must degrade the record, not the gate.
 *
 * @package Phlix\Hub\Alexa
 * @since   S91 (Alexa skill controller + Q&A intent tier)
 */
final class AuditLogAlexaRejectionAuditor implements AlexaRejectionAuditorInterface
{
    /** The `audit_logs.event` slug every Alexa rejection is filed under. */
    public const EVENT = 'alexa_rejected';

    /**
     * @param AuditLogRepository $auditLogs Persists the row. Uses
     *        `Workerman\MySQL\Connection` with named `:param` placeholders; this
     *        class only CALLS it and adds no SQL of its own.
     * @param StructuredLogger   $logger    Operator-visible warning channel.
     */
    public function __construct(
        private readonly AuditLogRepository $auditLogs,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function record(
        string $code,
        string $detail,
        string $clientIp,
        ?string $userAgent,
        ?string $requestId,
    ): void {
        $context = ['code' => $code, 'detail' => $detail];
        if ($requestId !== null) {
            $context['alexa_request_id'] = $requestId;
        }

        try {
            $this->auditLogs->log(
                event: self::EVENT,
                resource: '/alexa/skill',
                action: 'alexa.signature_rejected',
                success: false,
                reason: $code,
                ipAddress: $clientIp !== '' ? $clientIp : null,
                userAgent: $userAgent,
                context: $context,
            );
        } catch (Throwable $e) {
            // The row is lost; the gate's verdict is not. Say so on the log
            // channel rather than letting the throw reach the middleware.
            $this->safeWarn('Alexa rejection could not be persisted to audit_logs', [
                'code' => $code,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->safeWarn('Alexa request rejected', $context + ['ip' => $clientIp]);
    }

    /**
     * Log a warning without letting a broken handler escape.
     *
     * @param array<string, string> $context
     */
    private function safeWarn(string $message, array $context): void
    {
        try {
            $this->logger->warning($message, $context);
        } catch (Throwable) {
            // A logging backend that throws must not be able to change a
            // security verdict. There is nowhere left to report this to.
        }
    }
}
