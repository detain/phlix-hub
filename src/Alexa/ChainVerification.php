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
 * Outcome of verifying one fetched Alexa certificate chain.
 *
 * A dedicated result type rather than a `string|array` union so that "verified"
 * and "rejected" are impossible to confuse at a call site: there is no value of
 * this class that is both, and {@see publicKeyPem()} is only readable once
 * {@see isVerified()} has been asserted. The alternative — returning the PEM on
 * success and an error tuple on failure — puts the two outcomes in the same slot
 * and makes the fail-closed property depend on the caller getting an `is_string`
 * test the right way round.
 *
 * @package Phlix\Hub\Alexa
 */
final class ChainVerification
{
    /**
     * @param string|null $publicKeyPem Leaf public key PEM when verified.
     * @param int         $validUntil   Unix time this result may be cached until.
     * @param string|null $errorCode    Machine-readable rejection code, or null.
     * @param string      $detail       Human-readable rejection detail (log only).
     */
    private function __construct(
        private readonly ?string $publicKeyPem,
        private readonly int $validUntil,
        private readonly ?string $errorCode,
        private readonly string $detail,
    ) {
    }

    /**
     * A verified chain.
     *
     * @param string $publicKeyPem Leaf public key PEM.
     * @param int    $validUntil   Unix time this result may be cached until.
     */
    public static function verified(string $publicKeyPem, int $validUntil): self
    {
        return new self($publicKeyPem, $validUntil, null, '');
    }

    /**
     * A rejected chain.
     *
     * @param string $code   Machine-readable rejection code.
     * @param string $detail Human-readable detail, logged but never returned to
     *                       the caller of the HTTP endpoint.
     */
    public static function rejected(string $code, string $detail): self
    {
        return new self(null, 0, $code, $detail);
    }

    public function isVerified(): bool
    {
        return $this->publicKeyPem !== null;
    }

    /**
     * The leaf public key PEM.
     *
     * @throws \LogicException When called on a rejection — a programming error,
     *         and one that must not silently produce an empty "key".
     */
    public function publicKeyPem(): string
    {
        if ($this->publicKeyPem === null) {
            throw new \LogicException('publicKeyPem() read from a rejected chain verification');
        }

        return $this->publicKeyPem;
    }

    public function validUntil(): int
    {
        return $this->validUntil;
    }

    /**
     * The rejection code, or `ALEXA_CERT_CHAIN_MALFORMED` if somehow read from a
     * verified result (which the middleware never does).
     */
    public function errorCode(): string
    {
        return $this->errorCode ?? 'ALEXA_CERT_CHAIN_MALFORMED';
    }

    public function detail(): string
    {
        return $this->detail;
    }
}
