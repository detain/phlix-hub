<?php

/**
 * Phlix hub component: Alexa.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Alexa;

/**
 * Observer of {@see \Phlix\Hub\Http\Middleware\AlexaSignatureMiddleware}
 * rejections (S91).
 *
 * ## Why this is an interface, and why it lives INSIDE the middleware
 *
 * `Phlix\Hub\Http\Router` middleware is BEFORE-only: a middleware returns
 * `?Response` and there is no "after" hook. An observer of a middleware's own
 * rejection therefore cannot be a second middleware — a wrapper would have to
 * REPLACE `AlexaSignatureMiddleware` on the route's middleware list, and the
 * route suite pins that list by exact class name precisely so the signature gate
 * cannot be swapped out unnoticed. So the auditor is a collaborator the gate
 * calls, and it is an interface so the DB write is substitutable in a unit test
 * that has no database.
 *
 * ## The ordering constraint the implementation depends on
 *
 * The middleware runs its IP-keyed rate limiter BEFORE the verification pipeline,
 * so a request that is already over budget is answered 429 and never reaches
 * {@see record()}. That ordering is what stops a flood from amplifying into one
 * `audit_logs` INSERT per malicious request — an unlimited public endpoint whose
 * rejection path writes a row is a write amplifier pointed at the hub's own
 * database.
 *
 * @package Phlix\Hub\Alexa
 * @since   S91 (Alexa skill controller + Q&A intent tier)
 */
interface AlexaRejectionAuditorInterface
{
    /**
     * Record one rejected Alexa request.
     *
     * Implementations MUST NOT throw: this is called from inside the gate's
     * fail-closed path, and an exception here would be caught by the gate's own
     * `catch (\Throwable)` and silently relabel a precise rejection code as a
     * generic verification error.
     *
     * @param string      $code      The machine-readable rejection code, e.g.
     *        `ALEXA_SIGNATURE_INVALID`. Same value the 400 body carries.
     * @param string      $detail    Human-readable detail. Never returned to the
     *        caller — it exists so an operator can tell WHICH rule fired without
     *        the endpoint handing that information to whoever is probing it.
     * @param string      $clientIp  Trusted client IP (resolved through
     *        `TRUSTED_PROXIES`, so it is the caller and not the front proxy).
     * @param string|null $userAgent Inbound `User-Agent`, or null.
     * @param string|null $requestId Amazon's request id when the body was
     *        readable enough to carry one, else null.
     */
    public function record(
        string $code,
        string $detail,
        string $clientIp,
        ?string $userAgent,
        ?string $requestId,
    ): void;
}
