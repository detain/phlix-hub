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
 * ⚠ The reason recorded here by S91 is **obsolete and has been replaced** — read
 * the new one, not the old one. S91 wrote that `AuditLogger` was bound in
 * `AuthServicesProvider` as `new AuditLogger(LoggerFactory::get(LogChannels::AUDIT))`
 * with its optional `?AuditLogRepository` never passed, so no `AuditLogger` event
 * had ever reached the `audit_logs` table and routing through it would have
 * persisted nothing. That was true and is the defect S269 fixed: the binding now
 * passes the repository, and `AuditLogger` writes rows like everything else.
 *
 * The direct injection stays anyway, on its own merits:
 *
 *  - `AuditLogger` exposes one method per FIXED event slug, and none of them can
 *    emit {@see self::EVENT} (`alexa_rejected`), which is what the admin console
 *    filters this surface on.
 *  - None of them accepts `ipAddress` or `userAgent`. A signature rejection is
 *    only useful with the caller's address attached, and
 *    {@see AuditLogRepository::log()} is the only writer that takes it.
 *
 * Adding such a method to `AuditLogger` would be introducing a new audit event,
 * which S269 held out of scope deliberately. So this is a second WRITER to one
 * table, not a second MECHANISM competing with `AuditLogger`.
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
