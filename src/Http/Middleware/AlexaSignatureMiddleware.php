<?php

/**
 * Phlix hub component: Middleware.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Middleware;

use Phlix\Hub\Alexa\AlexaRejectionAuditorInterface;
use Phlix\Hub\Alexa\CertChainFetcherInterface;
use Phlix\Hub\Alexa\ChainVerification;
use Phlix\Hub\Common\RateLimit\RateLimiterInterface;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Common\Logger\StructuredLogger;

/**
 * Amazon Alexa request-signature verification — the security gate for the whole
 * Alexa integration (S90).
 *
 * Every Alexa skill request arrives at a public HTTPS endpoint that anybody on
 * the internet can POST to. Amazon's *only* proof that a body really came from
 * Amazon is a detached RSA signature over the raw request bytes, made with a
 * certificate published under Amazon's own S3 cert host. This middleware is that
 * proof check. Nothing downstream — intent dispatch (S91), account linking
 * (S92), playback push (S93) — has any authentication of its own, so a
 * spoofable check here defeats every layer above it.
 *
 * ## The algorithm, in the order this class runs it
 *
 *  1. Both headers must be present and the body must be non-empty.
 *  2. **Validate `SignatureCertChainUrl` BEFORE fetching it.** See
 *     {@see rejectCertChainUrl()}.
 *  3. Fetch the chain — bounded and cached; see "Runtime cost" below.
 *  4. Split the PEM chain, check the leaf is currently valid, check its SAN, and
 *     verify the chain anchors to a trusted root.
 *  5. Verify the signature over the **raw** body bytes.
 *  6. Read the timestamp out of the body and reject anything more than
 *     {@see MAX_TIMESTAMP_SKEW_SECONDS} seconds away from now.
 *
 * ## Why the timestamp check is LAST, not first
 *
 * Amazon's own guidance puts the (cheap) timestamp check early as a DoS
 * shortcut. This class deliberately puts it last, for a verification reason:
 * a "stale timestamp is rejected" test that runs before any crypto proves
 * nothing about the crypto — it passes identically against a middleware whose
 * signature check is deleted. Ordering it last means
 * `testStaleTimestampIsRejectedAfterAValidSignature()` can only reach its
 * assertion by having *passed* the URL check, the chain verification, the SAN
 * check and the signature check first. That is the anti-vacuity proof for the
 * whole suite. The DoS concern it trades away is covered by the chain cache
 * (step 3 does no network I/O on the common path) and belongs, properly, to the
 * route's rate limiter in S91.
 *
 * ## Why the raw body, and only the raw body
 *
 * {@see openssl_verify()} is given `$request->rawBody` — the exact bytes off the
 * wire. Verifying a re-encoded body would accept any payload that is merely
 * *semantically* equal to the signed one: reorder the keys, change the unicode
 * escaping, add whitespace, and `json_encode(json_decode($b))` produces a
 * different byte string that would still be "the same request". Step 6 then
 * parses those same already-verified bytes, so the timestamp that is checked is
 * provably the timestamp that Amazon signed.
 *
 * ## Runtime cost: this runs inside a resident worker
 *
 * The cert-chain fetch is network I/O on a Workerman/Swoole worker, where a
 * blocking https call freezes every coroutine on the process. Two mitigations,
 * both required:
 *
 *  - the fetch is bounded by {@see \Phlix\Hub\Alexa\CurlCertChainFetcher}'s
 *    explicit connect AND transfer timeouts;
 *  - a *verified* chain is cached per URL in this (singleton) instance, so the
 *    common path does no network I/O at all.
 *
 * The cache is bounded at {@see CACHE_CAPACITY} entries with oldest-first
 * eviction, because the cert URL is attacker-supplied: without a cap, a caller
 * could mint unlimited distinct `/echo.api/…` URLs and grow a resident worker's
 * memory without bound. Only SUCCESSFUL verifications are cached — a failure is
 * never cached, so a transient S3 outage cannot pin a rejection.
 *
 * ## The rate limiter and the auditor live INSIDE the gate (S91)
 *
 * `Phlix\Hub\Http\Router` middleware is BEFORE-only — a middleware returns
 * `?Response` and there is no "after" hook — so an observer of THIS middleware's
 * rejection cannot be a second middleware. A wrapper would have to REPLACE this
 * class on the route's middleware list, and the route suite pins that list by
 * exact class name precisely so the signature gate cannot be swapped out
 * unnoticed. Both S90 deferrals therefore land here:
 *
 *  - **The limiter runs FIRST**, before the `try` and before any verification, so
 *    a flood cannot amplify into one `audit_logs` INSERT per malicious request.
 *    It is keyed `alexa:<trusted client ip>` via
 *    {@see Request::getTrustedClientIp()}, which walks `X-Forwarded-For`
 *    right-to-left past `TRUSTED_PROXIES` hops. Keying on `remoteIp` instead
 *    would bucket the HAProxy front and collapse every caller on earth into one
 *    window.
 *  - **A spent window returns a 429 {@see Response} directly and MUST NOT
 *    throw.** A `RateLimitException` here would be caught by the fail-closed
 *    `catch (\Throwable)` below and silently relabelled as a 400
 *    `ALEXA_VERIFICATION_ERROR` — the caller would never learn to back off.
 *  - **Every rejection is audited** by {@see reject()} through the injected
 *    {@see AlexaRejectionAuditorInterface}.
 *
 * ## Fails closed, everywhere
 *
 * {@see __invoke()} has exactly one `return null` (the allow), reached only by
 * falling off the end of the happy path. Every other exit returns a 400. The
 * whole body is wrapped in `catch (\Throwable)` which also returns a 400, so an
 * unexpected `ValueError` out of ext/openssl cannot become an allow either.
 *
 * @package Phlix\Hub\Http\Middleware
 */
final class AlexaSignatureMiddleware
{
    /**
     * Amazon's documented cert host. Compared with {@see strcasecmp()} for
     * EXACT equality — never `str_contains()`/`str_ends_with()`. A suffix test
     * would accept `evil-s3.amazonaws.com` and `s3.amazonaws.com.evil.test`;
     * this program has already shipped that bug shape once
     * (`/api/v1/media/most-watched2` matching `/api/v1/media/most-watched`).
     */
    public const CERT_HOST = 's3.amazonaws.com';

    /** Amazon's documented cert path prefix (case-sensitive, per Amazon). */
    public const CERT_PATH_PREFIX = '/echo.api/';

    /** The only port Amazon's cert host may be addressed on. */
    public const CERT_PORT = 443;

    /**
     * The DNS SAN entry Amazon's Alexa signing leaf must carry. Matched entry by
     * entry against the parsed `subjectAltName`, never as a substring of the
     * whole extension blob — `DNS:echo-api.amazon.com.evil.test` contains
     * `echo-api.amazon.com`.
     */
    public const ECHO_SAN = 'echo-api.amazon.com';

    /** Amazon's documented replay window, in seconds. */
    public const MAX_TIMESTAMP_SKEW_SECONDS = 150;

    /**
     * The SHA-256 signature header. The legacy `Signature` header is SHA-1 and
     * is deliberately NOT accepted: honouring it would let a caller downgrade
     * the whole gate to a broken hash by choosing which header to send.
     */
    public const SIGNATURE_HEADER = 'Signature-256';

    /** The header carrying the (attacker-supplied) cert chain URL. */
    public const CERT_CHAIN_URL_HEADER = 'SignatureCertChainUrl';

    /** Largest number of verified chains held per worker process. */
    public const CACHE_CAPACITY = 8;

    /** Upper bound on how long a verified chain is reused, in seconds. */
    public const CACHE_TTL_SECONDS = 3600;

    /**
     * Prefix of the rate-limit bucket key (S91). The remainder is the TRUSTED
     * client IP, so the surface has its own namespace inside a limiter store that
     * other surfaces may share.
     */
    public const RATE_LIMIT_KEY_PREFIX = 'alexa:';

    /**
     * Ceiling on the number of characters of an UNVERIFIED `request.requestId`
     * that reaches the audit row.
     *
     * The id is read out of a body whose signature has just been REJECTED, so it
     * is attacker-controlled. It is parameterised into the INSERT and never
     * concatenated, so it is not an injection sink; the cap exists because an
     * unbounded field would let a caller write a megabyte per rejection into
     * `audit_logs.context_json`.
     */
    public const MAX_AUDITED_REQUEST_ID_CHARS = 128;

    /**
     * Verified chains, keyed by the validated cert URL, in insertion order so
     * `array_key_first()` names the eviction victim.
     *
     * @var array<string, array{publicKeyPem: string, validUntil: int}>
     */
    private array $verifiedChains = [];

    /**
     * ⚠ The two S91 parameters are inserted BEFORE the optional ones. Appending
     * them after `$caBundlePaths` would make a REQUIRED parameter follow an
     * OPTIONAL one, which PHP 8.3 deprecates.
     *
     * @param CertChainFetcherInterface     $fetcher     Bounded chain fetcher.
     * @param StructuredLogger              $logger      Rejections are logged here.
     * @param RateLimiterInterface          $rateLimiter Per-worker IP-keyed limiter
     *        for this surface ({@see \Phlix\Hub\Common\RateLimit\RateLimitProfiles::ALEXA}).
     *        Hit on EVERY request, before verification.
     * @param AlexaRejectionAuditorInterface $auditor    Records each rejection to
     *        `audit_logs` + the log channel. Runs AFTER the limiter, so a flood is
     *        not a write amplifier.
     * @param list<string>                  $caBundlePaths CA files/dirs trusted as
     *        chain anchors. Production passes `[]`, which means "the system
     *        trust store"; tests pass their own generated root so the chain
     *        verification can be exercised without Amazon's private key.
     * @param int                           $cacheTtlSeconds How long a verified
     *        chain may be reused. Lowering it only costs fetches; it can never
     *        weaken a verdict, because every cache hit is re-checked against the
     *        leaf's own `notAfter` as well.
     */
    public function __construct(
        private readonly CertChainFetcherInterface $fetcher,
        private readonly StructuredLogger $logger,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly AlexaRejectionAuditorInterface $auditor,
        private readonly array $caBundlePaths = [],
        private readonly int $cacheTtlSeconds = self::CACHE_TTL_SECONDS,
    ) {
    }

    /**
     * Run the gate. Returns null to continue routing, a 429 when the caller's
     * window is spent, or a 400 {@see Response} to short-circuit.
     *
     * The limiter is deliberately OUTSIDE the `try`: see the class docblock —
     * a throw here would be caught below and demoted to a 400.
     */
    public function __invoke(Request $request): ?Response
    {
        $state = $this->rateLimiter->hit(
            self::RATE_LIMIT_KEY_PREFIX . $request->getTrustedClientIp(),
        );
        if ($state->limited) {
            return (new Response())
                ->status(429)
                ->header('Retry-After', (string) $state->retryAfter(time()))
                ->json(['error' => 'Too Many Requests', 'code' => 'rate_limited']);
        }

        try {
            return $this->verify($request);
        } catch (\Throwable $e) {
            // Fail closed. An unexpected throw is a rejection, never an allow.
            return $this->reject($request, 'ALEXA_VERIFICATION_ERROR', $e->getMessage());
        }
    }

    /**
     * The verification pipeline. Split out of {@see __invoke()} so the
     * fail-closed `catch` wraps EVERY branch below, including the ones that call
     * into ext/openssl.
     */
    private function verify(Request $request): ?Response
    {
        $certChainUrl = $request->getHeader(self::CERT_CHAIN_URL_HEADER);
        if ($certChainUrl === null || $certChainUrl === '') {
            return $this->reject($request, 'ALEXA_MISSING_CERT_CHAIN_URL');
        }

        $signatureHeader = $request->getHeader(self::SIGNATURE_HEADER);
        if ($signatureHeader === null || $signatureHeader === '') {
            return $this->reject($request, 'ALEXA_MISSING_SIGNATURE_HEADER');
        }

        $rawBody = $request->rawBody;
        if ($rawBody === '') {
            return $this->reject($request, 'ALEXA_EMPTY_BODY');
        }

        // (2) SSRF gate — runs BEFORE the fetcher is ever touched.
        $urlProblem = $this->rejectCertChainUrl($certChainUrl);
        if ($urlProblem !== null) {
            return $this->reject($request, 'ALEXA_CERT_URL_REJECTED', $urlProblem);
        }

        // (3)+(4) Cached, verified leaf public key.
        $verification = $this->cachedVerification($certChainUrl);
        if (!$verification->isVerified()) {
            return $this->reject($request, $verification->errorCode(), $verification->detail());
        }

        // (5) Signature over the RAW bytes.
        $signature = base64_decode($signatureHeader, true);
        if ($signature === false || $signature === '') {
            return $this->reject($request, 'ALEXA_SIGNATURE_INVALID', 'signature is not base64');
        }

        $publicKey = openssl_pkey_get_public($verification->publicKeyPem());
        if ($publicKey === false) {
            return $this->reject($request, 'ALEXA_CERT_CHAIN_MALFORMED', 'leaf public key unreadable');
        }

        if (openssl_verify($rawBody, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            return $this->reject($request, 'ALEXA_SIGNATURE_INVALID', 'body signature did not verify');
        }

        // (6) Replay window, read from the bytes just verified.
        return $this->rejectTimestamp($request, $rawBody);
    }

    /**
     * Validate the caller-supplied cert chain URL against Amazon's documented
     * rules. Returns a human-readable reason to reject, or null to accept.
     *
     * This is an SSRF sink: whatever survives this method is a URL the hub's own
     * worker will then request. The checks, in Amazon's documented order plus
     * three hardening rules of our own:
     *
     * | rule                       | rejects                                    |
     * | -------------------------- | ------------------------------------------ |
     * | scheme is https            | `http://`, `file://`, `gopher://`          |
     * | host EXACTLY the cert host | `evil-s3.amazonaws.com`, `s3.amazonaws.com.evil.test` |
     * | explicit port is 443       | `https://s3.amazonaws.com:8443/echo.api/x` |
     * | no userinfo (ours)         | `https://evil@s3.amazonaws.com/echo.api/x` |
     * | no query/fragment (ours)   | cache-busting cert-URL variants            |
     * | path already CANONICAL (ours) | `/echo.api/../evil`, `//echo.api/x`,
     *                                `/echo.api/./x`, `/evil/../echo.api/x`   |
     * | no percent-encoding (ours) | `/echo.api/%2e%2e/evil`                    |
     * | path starts with prefix    | `/other/echo-api-cert.pem`                 |
     *
     * The canonical-form rule is stated as "the path must ALREADY equal its
     * normalised form", not "normalise it and then prefix-check". Both catch
     * `/echo.api/../evil`, but only the former also catches `//echo.api/x`,
     * whose normalised form *does* start with the prefix while the bytes sent to
     * S3 name a different object. Requiring canonicality means there is exactly
     * one spelling of any acceptable URL, so the cache key and the fetched
     * object cannot disagree.
     *
     * @return string|null Reason to reject, or null when the URL is acceptable.
     */
    private function rejectCertChainUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return 'unparseable url';
        }

        $scheme = $parts['scheme'] ?? null;
        if (!is_string($scheme) || strcasecmp($scheme, 'https') !== 0) {
            return 'scheme is not https';
        }

        $host = $parts['host'] ?? null;
        if (!is_string($host) || strcasecmp($host, self::CERT_HOST) !== 0) {
            return 'host is not ' . self::CERT_HOST;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'url carries userinfo';
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && $port !== self::CERT_PORT) {
            return 'explicit port is not ' . self::CERT_PORT;
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            return 'url carries a query string or fragment';
        }

        $path = $parts['path'] ?? null;
        if (!is_string($path) || $path === '') {
            return 'url has no path';
        }

        if (str_contains($path, '%')) {
            return 'path is percent-encoded';
        }

        if (str_contains($path, '\\')) {
            return 'path contains a backslash';
        }

        if ($path !== self::canonicalisePath($path)) {
            return 'path is not in canonical form';
        }

        if (!str_starts_with($path, self::CERT_PATH_PREFIX)) {
            return 'path does not start with ' . self::CERT_PATH_PREFIX;
        }

        return null;
    }

    /**
     * Resolve a URL path's `.` / `..` / empty segments.
     *
     * Used only for the equality test in {@see rejectCertChainUrl()} — the
     * result is never fetched, so a path that needed normalising is rejected
     * rather than silently rewritten.
     */
    private static function canonicalisePath(string $path): string
    {
        $resolved = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($resolved);
                continue;
            }
            $resolved[] = $segment;
        }

        return '/' . implode('/', $resolved);
    }

    /**
     * Return the verification result for `$url`, fetching and verifying the
     * chain on a cache miss.
     */
    private function cachedVerification(string $url): ChainVerification
    {
        $now = time();

        $cached = $this->verifiedChains[$url] ?? null;
        if ($cached !== null) {
            if ($cached['validUntil'] > $now) {
                return ChainVerification::verified($cached['publicKeyPem'], $cached['validUntil']);
            }
            // Expired entry: drop it and re-verify from scratch rather than
            // extending trust in a leaf that may since have been rotated.
            unset($this->verifiedChains[$url]);
        }

        $pem = $this->fetcher->fetch($url);
        if ($pem === null) {
            return ChainVerification::rejected('ALEXA_CERT_FETCH_FAILED', 'chain could not be retrieved');
        }

        $verified = $this->verifyChain($pem, $now);
        if (!$verified->isVerified()) {
            return $verified;
        }

        while (count($this->verifiedChains) >= self::CACHE_CAPACITY) {
            $oldest = array_key_first($this->verifiedChains);
            if ($oldest === null) {
                break;
            }
            unset($this->verifiedChains[$oldest]);
        }

        $this->verifiedChains[$url] = [
            'publicKeyPem' => $verified->publicKeyPem(),
            'validUntil' => $verified->validUntil(),
        ];

        return $verified;
    }

    /**
     * Verify a fetched PEM chain: leaf currently valid, leaf SAN carries
     * Amazon's Echo name, and the chain anchors to a trusted root.
     *
     * "A chain that merely parses is not a verified chain" — so the anchoring is
     * done by {@see openssl_x509_checkpurpose()}, which walks leaf → supplied
     * intermediates → configured trust store and fails if the walk does not end
     * at a root the host actually trusts. Passing the intermediates as
     * *untrusted* material is what makes them usable as path-building hints
     * without making them trust anchors: a caller cannot bless its own leaf by
     * appending a self-signed CA to the chain it serves.
     *
     * @param string $pem Raw fetched chain.
     * @param int    $now Unix time to judge validity at.
     */
    private function verifyChain(string $pem, int $now): ChainVerification
    {
        $chain = self::splitPemChain($pem);
        if ($chain === []) {
            return ChainVerification::rejected('ALEXA_CERT_CHAIN_MALFORMED', 'no PEM certificate blocks found');
        }

        $leaf = $chain[0];
        $parsed = openssl_x509_parse($leaf);
        if (!is_array($parsed)) {
            return ChainVerification::rejected('ALEXA_CERT_CHAIN_MALFORMED', 'leaf certificate did not parse');
        }

        $validFrom = $parsed['validFrom_time_t'] ?? null;
        $validTo = $parsed['validTo_time_t'] ?? null;
        if (!is_int($validFrom) || !is_int($validTo)) {
            return ChainVerification::rejected(
                'ALEXA_CERT_CHAIN_MALFORMED',
                'leaf certificate has no validity window',
            );
        }
        if ($now < $validFrom || $now > $validTo) {
            return ChainVerification::rejected(
                'ALEXA_CERT_EXPIRED',
                'leaf certificate is not valid at this time',
            );
        }

        if (!self::leafHasEchoSan($parsed)) {
            return ChainVerification::rejected(
                'ALEXA_CERT_SAN_MISMATCH',
                'leaf SAN does not carry ' . self::ECHO_SAN,
            );
        }

        if (!$this->chainAnchorsToTrustedRoot($leaf, array_slice($chain, 1))) {
            return ChainVerification::rejected(
                'ALEXA_CERT_CHAIN_UNTRUSTED',
                'chain does not anchor to a trusted root',
            );
        }

        $publicKey = openssl_pkey_get_public($leaf);
        if ($publicKey === false) {
            return ChainVerification::rejected('ALEXA_CERT_CHAIN_MALFORMED', 'leaf public key unreadable');
        }

        $exported = openssl_pkey_get_details($publicKey);
        if (!is_array($exported) || !isset($exported['key']) || !is_string($exported['key'])) {
            return ChainVerification::rejected(
                'ALEXA_CERT_CHAIN_MALFORMED',
                'leaf public key could not be exported',
            );
        }

        return ChainVerification::verified($exported['key'], min($validTo, $now + $this->cacheTtlSeconds));
    }

    /**
     * Split a PEM bundle into its certificate blocks, leaf first.
     *
     * The character class is deliberately restricted to base64 and whitespace
     * rather than `.*?` with `/s`, so arbitrary bytes cannot ride along inside a
     * block that ext/openssl might read differently from this regex.
     *
     * @return list<string>
     */
    private static function splitPemChain(string $pem): array
    {
        $pattern = '/-----BEGIN CERTIFICATE-----[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----/';
        $count = preg_match_all($pattern, $pem, $matches);
        if (!is_int($count) || $count < 1) {
            return [];
        }

        return $matches[0];
    }

    /**
     * Does the parsed leaf carry Amazon's Echo DNS SAN?
     *
     * Each `subjectAltName` entry is split off and compared for EXACT equality
     * (case-insensitively, as DNS names are). Comparing against the whole
     * extension string with `str_contains()` would accept a certificate whose
     * SAN is `DNS:echo-api.amazon.com.attacker.test`.
     *
     * @param array<array-key, mixed> $parsed Output of {@see openssl_x509_parse()}.
     */
    private static function leafHasEchoSan(array $parsed): bool
    {
        $extensions = $parsed['extensions'] ?? null;
        if (!is_array($extensions)) {
            return false;
        }

        $san = $extensions['subjectAltName'] ?? null;
        if (!is_string($san) || $san === '') {
            return false;
        }

        foreach (explode(',', $san) as $entry) {
            $entry = trim($entry);
            if (!str_starts_with($entry, 'DNS:')) {
                continue;
            }
            if (strcasecmp(substr($entry, 4), self::ECHO_SAN) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify that `$leaf` chains to a trusted root, using `$intermediates` as
     * untrusted path-building material.
     *
     * ext/openssl only accepts untrusted certificates as a FILE, so the
     * intermediates are written to a private temp file for the duration of the
     * call and unlinked in a `finally`. This happens on a cache MISS only.
     *
     * @param string       $leaf          Leaf certificate PEM.
     * @param list<string> $intermediates Remaining chain PEMs.
     */
    private function chainAnchorsToTrustedRoot(string $leaf, array $intermediates): bool
    {
        $untrustedFile = null;

        try {
            if ($intermediates !== []) {
                $candidate = tempnam(sys_get_temp_dir(), 'phlix-alexa-chain-');
                if ($candidate === false) {
                    return false;
                }
                $untrustedFile = $candidate;
                if (file_put_contents($untrustedFile, implode("\n", $intermediates) . "\n") === false) {
                    return false;
                }
            }

            return openssl_x509_checkpurpose(
                $leaf,
                X509_PURPOSE_ANY,
                $this->caBundlePaths,
                $untrustedFile,
            ) === true;
        } finally {
            if ($untrustedFile !== null) {
                @unlink($untrustedFile);
            }
        }
    }

    /**
     * Enforce the replay window against the timestamp inside the
     * already-signature-verified body.
     *
     * A missing or malformed timestamp REJECTS — it is never skipped. Skipping
     * would mean a request that simply omits `request.timestamp` bypasses the
     * replay window entirely, which is the whole point of the check.
     *
     * Timestamps are matched against a strict ISO-8601 pattern before being
     * handed to {@see \DateTimeImmutable}, because that constructor is a
     * *relative* date parser too: `new DateTimeImmutable('')` and
     * `new DateTimeImmutable('now')` both succeed and both evaluate to the
     * current time, so an unvalidated string is a free pass through this check.
     *
     * Skew is compared with {@see abs()}: a timestamp far in the FUTURE is
     * rejected as well, since otherwise a captured request could be replayed
     * indefinitely by dating it forward.
     *
     * @return Response|null A 400 to reject, or null to allow the request through.
     */
    private function rejectTimestamp(Request $request, string $verifiedRawBody): ?Response
    {
        $decoded = json_decode($verifiedRawBody, true);
        if (!is_array($decoded)) {
            return $this->reject($request, 'ALEXA_TIMESTAMP_MALFORMED', 'body is not a JSON object');
        }

        $envelope = $decoded['request'] ?? null;
        if (!is_array($envelope)) {
            return $this->reject($request, 'ALEXA_TIMESTAMP_MISSING', 'body has no request object');
        }

        $timestamp = $envelope['timestamp'] ?? null;
        if (!is_string($timestamp) || $timestamp === '') {
            return $this->reject($request, 'ALEXA_TIMESTAMP_MISSING', 'request.timestamp is absent');
        }

        $iso8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/';
        if (preg_match($iso8601, $timestamp) !== 1) {
            return $this->reject($request, 'ALEXA_TIMESTAMP_MALFORMED', 'request.timestamp is not ISO-8601');
        }

        try {
            $when = new \DateTimeImmutable($timestamp);
        } catch (\Throwable) {
            return $this->reject($request, 'ALEXA_TIMESTAMP_MALFORMED', 'request.timestamp is not a real instant');
        }

        if (abs(time() - $when->getTimestamp()) > self::MAX_TIMESTAMP_SKEW_SECONDS) {
            return $this->reject(
                $request,
                'ALEXA_TIMESTAMP_STALE',
                'request.timestamp is outside the replay window',
            );
        }

        // The single allow. Everything above this line returns a Response.
        return null;
    }

    /**
     * Build the 400 rejection and record why.
     *
     * The response body carries the machine-readable code but NOT the detail:
     * the detail goes to the log and the audit row only, so probing this endpoint
     * does not hand an attacker a description of which specific rule they
     * tripped.
     *
     * The audited client IP is re-derived from the request rather than threaded
     * down from {@see __invoke()}. {@see Request::getTrustedClientIp()} is a pure
     * function of the request's peer address and headers, so the audited address
     * is provably the same one the limiter bucketed — and the recomputation only
     * happens on a path that is itself rate-limited.
     *
     * The auditor is contractually non-throwing; if a future implementation
     * breaks that, the throw is caught by {@see __invoke()}'s fail-closed
     * `catch`, which still refuses the request.
     */
    private function reject(Request $request, string $code, string $detail = ''): Response
    {
        $this->logger->warning('Alexa request signature rejected', [
            'code' => $code,
            'detail' => $detail,
        ]);

        $this->auditor->record(
            $code,
            $detail,
            $request->getTrustedClientIp(),
            $request->getHeader('User-Agent'),
            self::auditableRequestId($request->rawBody),
        );

        return (new Response())->status(400)->json([
            'error' => 'Bad Request',
            'code' => $code,
        ]);
    }

    /**
     * `request.requestId` out of an UNVERIFIED body, for correlation only.
     *
     * Parsed here rather than through {@see \Phlix\Hub\Alexa\AlexaEnvelope}
     * on purpose: that class documents itself as a reader of bytes whose
     * signature has ALREADY been proven, and this is the opposite situation. The
     * value is treated as hostile — string-checked and clamped to
     * {@see MAX_AUDITED_REQUEST_ID_CHARS} — and is only ever used as a bound
     * parameter in the audit INSERT.
     */
    private static function auditableRequestId(string $rawBody): ?string
    {
        if ($rawBody === '') {
            return null;
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            return null;
        }

        $envelope = $decoded['request'] ?? null;
        if (!is_array($envelope)) {
            return null;
        }

        $requestId = $envelope['requestId'] ?? null;
        if (!is_string($requestId) || $requestId === '') {
            return null;
        }

        return mb_substr($requestId, 0, self::MAX_AUDITED_REQUEST_ID_CHARS);
    }
}
