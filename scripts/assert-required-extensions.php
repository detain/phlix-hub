<?php

/**
 * S258 — assert that every PHP extension the hub's SECURITY path calls into is
 * actually present, and fail loudly when one is not.
 *
 * ## The defect this closes
 *
 * `.github/workflows/ci.yml` declared `extensions: json, pcntl, posix[, swoole]`
 * on its PHP jobs. `openssl` and `curl` — without which
 * {@see \Phlix\Hub\Http\Middleware\AlexaSignatureMiddleware} cannot verify a
 * single Alexa request (no `openssl_verify()`, no `openssl_x509_parse()`, no
 * chain fetch) — were named NOWHERE. They worked only because
 * `shivammathur/setup-php`'s base build happens to ship them. The same was true
 * of `sodium` (the Ed25519 enrollment JWT), `mbstring`, `hash`, `filter` and
 * `ctype`.
 *
 * An extension present *by accident of the base image* is indistinguishable from
 * one present *by intent* — right up until an upstream image change removes it.
 * At that moment the security gate does not go red for a security reason: its
 * tests error out, or skip, or the middleware simply starts throwing
 * `Error: Call to undefined function openssl_verify()` inside its own
 * fail-closed `catch (\Throwable)` and every Alexa request turns into a 400 that
 * looks exactly like a rejected signature.
 *
 * ## Why naming the extension in the workflow is NOT sufficient on its own
 *
 * Measured against the pinned action
 * (`shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240`):
 * `src/scripts/unix.sh` reads `fail_fast="${fail_fast:-${FAIL_FAST:-false}}"`,
 * and `add_log()` only does `[ "$fail_fast" = "true" ] && exit 1`. So on a
 * default `ubuntu-latest` run an extension setup-php could not install is
 * reported as a red cross in the log and the STEP STILL SUCCEEDS.
 *
 * Naming `openssl` in `extensions:` therefore records the intent — it is the
 * difference between an accident and a contract, and it makes setup-php try to
 * install the extension rather than assume it — but it cannot, by itself, turn
 * an absence into a build failure. This script is what does that. It is run as
 * its own workflow step in every PHP job, it takes no arguments in CI, and it
 * exits 1 naming each missing extension.
 *
 * ## How the list was derived
 *
 * From the CALL SITES, not from the environment. A list computed from "what
 * happens to be loaded" self-adjusts and can never fail. Every entry below names
 * the concrete symbol the hub calls and the file that calls it, and
 * {@see \Phlix\Hub\Tests\Unit\Support\RequiredPhpExtensionsTest} asserts that
 * the symbol is STILL called in that file — so an entry that stops being
 * justified goes red and must be removed deliberately, and a call site that is
 * added is not silently covered by an over-broad list.
 *
 * `pcntl` and `swoole` are deliberately NOT listed here. They are required by
 * Workerman/the event loop rather than by hub source, so this gate cannot cite a
 * call site for them; they keep their existing `extensions:` entries and the
 * phpunit job's existing "Verify swoole + uv loaded" step.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

/**
 * The contract. Every entry is a security-path dependency with a named symbol
 * and the file that calls it.
 *
 * ⚠ Editing this list is editing a security contract. `RequiredPhpExtensionsTest`
 * holds an identical copy and pins it against this file, so both sides must be
 * changed together and the diff is visible in review.
 *
 * @var array<string, array{symbol: string, source: string, why: string}>
 */
const REQUIRED_EXTENSIONS = [
    'openssl' => [
        'symbol' => 'openssl_verify',
        'source' => 'src/Http/Middleware/AlexaSignatureMiddleware.php',
        'why' => 'Alexa request-signature verification: openssl_verify(), openssl_x509_parse(), '
            . 'openssl_x509_checkpurpose(), openssl_pkey_get_public/details(). Without it the ONLY '
            . 'authentication the whole Alexa surface has cannot run.',
    ],
    'curl' => [
        'symbol' => 'curl_exec',
        'source' => 'src/Alexa/CurlCertChainFetcher.php',
        'why' => 'The bounded https fetch of Amazon\'s signing-certificate chain, including the '
            . 'CURLOPT_* timeout/redirect/protocol options that make that fetch safe in a resident '
            . 'worker. No fetch, no chain, no signature check.',
    ],
    'sodium' => [
        'symbol' => 'sodium_crypto_sign_verify_detached',
        'source' => 'src/Hub/EnrollmentJwtService.php',
        'why' => 'Ed25519 signing and verification of the enrollment JWT that authorises a media '
            . 'server to join the hub (also sodium_crypto_sign_keypair() in Ed25519KeyManager).',
    ],
    'mbstring' => [
        'symbol' => 'mb_substr',
        'source' => 'src/Http/Middleware/AlexaSignatureMiddleware.php',
        'why' => 'Clamping attacker-controlled values before they are persisted — the unverified '
            . 'request id written to audit_logs, and the MCP token prefix. A missing mb_substr() is '
            . 'an unbounded write, not a cosmetic loss.',
    ],
    'json' => [
        'symbol' => 'json_decode',
        'source' => 'src/Http/Middleware/AlexaSignatureMiddleware.php',
        'why' => 'Parsing the already-signature-verified body to enforce the replay window, and '
            . 'every API request/response body in the hub.',
    ],
    'hash' => [
        'symbol' => 'hash_hmac',
        'source' => 'src/Auth/JwtHandler.php',
        'why' => 'HS256 JWT signing plus the constant-time hash_equals() comparison that stops the '
            . 'signature check being a timing oracle.',
    ],
    'filter' => [
        'symbol' => 'filter_var',
        'source' => 'src/Common/Http/TrustedProxyResolver.php',
        'why' => 'FILTER_VALIDATE_IP on the resolved client address. This is on the Alexa gate\'s own '
            . 'path: AlexaSignatureMiddleware keys its rate limiter on '
            . 'Request::getTrustedClientIp(), which is this resolver.',
    ],
    'ctype' => [
        'symbol' => 'ctype_digit',
        'source' => 'src/Common/Http/TrustedProxyResolver.php',
        'why' => 'Validating the prefix length of a TRUSTED_PROXIES CIDR range before it is used to '
            . 'decide how far to trust X-Forwarded-For.',
    ],
    'posix' => [
        'symbol' => 'posix_kill',
        'source' => 'src/Http/Controllers/HubRestartController.php',
        'why' => 'Signalling the resident master process for the admin-gated restart endpoint.',
    ],
];

/**
 * Read extra required extension names from argv.
 *
 * `--also-require=<name>` exists so the gate's FAILURE path can be exercised on
 * a machine where every real requirement is present — you cannot unload an
 * extension from a running PHP. It can only ever ADD a requirement, so it is
 * incapable of making this script pass something it would otherwise fail; that
 * is the whole reason it is safe to ship.
 *
 * @param list<string> $argv
 *
 * @return list<string>
 */
$alsoRequired = /** @return list<string> */ static function (array $argv): array {
    $extra = [];

    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--also-require=')) {
            $name = substr($argument, strlen('--also-require='));
            if ($name !== '') {
                $extra[] = $name;
            }
        }
    }

    return $extra;
};

/** @var list<string> $argvList */
$argvList = array_map('strval', $argv ?? []);

/** @var array<string, array{symbol: string|null, source: string|null, why: string}> $contract */
$contract = REQUIRED_EXTENSIONS;

foreach ($alsoRequired($argvList) as $extra) {
    $contract[$extra] = [
        'symbol' => null,
        'source' => null,
        'why' => 'injected by --also-require to exercise this gate\'s failure path',
    ];
}

$missing = [];
$missingSymbols = [];

foreach ($contract as $extension => $entry) {
    if (!extension_loaded($extension)) {
        $missing[] = $extension;
        printf("  \u{2717} %-10s NOT LOADED — %s\n", $extension, $entry['why']);
        continue;
    }

    $symbol = $entry['symbol'];
    if ($symbol !== null && !function_exists($symbol)) {
        // Loaded but the symbol the hub actually calls is absent: a partial or
        // patched build. Treated exactly like an absent extension.
        $missingSymbols[] = $extension . '::' . $symbol;
        printf("  \u{2717} %-10s loaded but %s() is undefined\n", $extension, $symbol);
        continue;
    }

    printf("  \u{2713} %-10s %s()\n", $extension, (string) $symbol);
}

printf(
    "\nChecked %d required extension(s) against PHP %s (%s).\n",
    count($contract),
    PHP_VERSION,
    PHP_BINARY,
);

if ($missing === [] && $missingSymbols === []) {
    echo "All security-path extensions are present.\n";
    exit(0);
}

foreach ($missing as $extension) {
    printf(
        "::error::Required PHP extension \"%s\" is not loaded. %s\n",
        $extension,
        REQUIRED_EXTENSIONS[$extension]['why'] ?? 'Required by scripts/assert-required-extensions.php.',
    );
}

foreach ($missingSymbols as $pair) {
    printf("::error::Required symbol %s() is undefined despite its extension being loaded.\n", $pair);
}

printf(
    "\nMISSING: %s\n",
    implode(', ', array_merge($missing, $missingSymbols)),
);

exit(1);
