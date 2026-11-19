<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Relay;

use InvalidArgumentException;
use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;

/**
 * The ONE boot-time TLS material decision for the hub's inbound WebSocket
 * listeners (F-12, dupe-scan-1119).
 *
 * Before this seam existed, all four listeners — :8802 relay, :8803 client
 * relay, :8804 SyncPlay, :8805 federation — ran a ~18-line copy of the same
 * decision inside their start() methods: null-material throw, the audit §3.2
 * TlsMaterialWatch::bootDecision() split (MISSING PEM fails fast pre-bind;
 * exists-but-UNREADABLE degrades to a loud boot warning and still binds),
 * the RELAY-channel warning emission, the per-connection watch
 * construction, and the hardened listen context. The four copies' POLICY
 * was already identical on live disk — the surfaces differ only in wording
 * inputs (worker label, env-var prefix, watch surface string, port) — so
 * this class collapses the block without unifying anything the workers own:
 *
 *  - the require_tls refusal/advisory posture stays in each worker (the
 *    posture suites pin per-surface text there);
 *  - what a throw MEANS at boot stays decided by each construction site:
 *    relay/client/SyncPlay throws are unwrapped in Application::boot() and
 *    kill the boot (refuse-boot posture), while the federation listener is
 *    constructed inside a catch(\Throwable) skip in the federation provider
 *    (fail-open posture: refusal degrades to no :8805 listener, hub keeps
 *    serving). Same helper exceptions, different outer policy — untouched.
 *
 * Why a new home rather than a method on {@see WsTlsContext}: that class is
 * lawed as "Pure value builders: no I/O, no state" (its header), and the
 * boot decision stats the filesystem, logs, and constructs a watch. This
 * class owns exactly that impure part and delegates the context value back
 * to the SSOT builder (F-11): post-migration every listener's bind context
 * comes from WsTlsContext::forListener(), byte-for-byte the value all four
 * builders produced at the base tree (pinned by WsTlsBootTest).
 *
 * Env-naming law: the four surfaces all conform to the shape
 * `{prefix}_CERT` / `{prefix}_KEY` (e.g. HUB_RELAY_TLS_CERT). A future
 * surface that cannot is REQUIRED to keep its own explicit env names by
 * extending this signature — not by quietly forking the decision again.
 *
 * @package Phlix\Hub\Relay
 */
final class WsTlsBoot
{
    /**
     * Run the shared TLS boot decision for one listener surface.
     *
     * @param bool        $useTls     whether the operator configured wss:// for this surface.
     * @param string|null $certPath   the `{prefix}_CERT` value (null => not set).
     * @param string|null $keyPath    the `{prefix}_KEY` value (null => not set).
     * @param string      $workerLabel human label inside the throw/warning texts, e.g. 'relay worker'.
     * @param string      $envPrefix   plaintext opt-out env AND cert/key stem, e.g. 'HUB_RELAY_TLS'.
     * @param string      $surface     watch/notice surface id WITHOUT the port, e.g. 'relay-ws'.
     * @param int         $port        listener port (appended to the surface as ' :PORT').
     *
     * @return array{0: array<string, mixed>, 1: TlsMaterialWatch|null}
     *         Positional [sslContext, materialWatch]: [] + null on the
     *         non-TLS path (Workerman's Worker::__construct() types
     *         $socketContext as a NON-NULLABLE array — an empty context is
     *         its own default for "no options"; passing null throws a
     *         TypeError); the hardened {ssl: ...} context + armed watch on
     *         the TLS path.
     *
     * @throws InvalidArgumentException When TLS is enabled but cert or key env is unset
     *                                  (message byte-ident to the four historical copies),
     *                                  or when either PEM is MISSING (TlsMaterialWatch::failFast law).
     */
    public static function forListener(
        bool $useTls,
        ?string $certPath,
        ?string $keyPath,
        string $workerLabel,
        string $envPrefix,
        string $surface,
        int $port
    ): array {
        if (!$useTls) {
            return [[], null];
        }

        $certEnv = $envPrefix . '_CERT';
        $keyEnv = $envPrefix . '_KEY';
        if ($certPath === null || $keyPath === null) {
            throw new InvalidArgumentException(
                'TLS enabled for ' . $workerLabel . ' but ' . $certEnv . ' and/or ' . $keyEnv . ' env vars are not set',
            );
        }

        $envPaths = [$certEnv => $certPath, $keyEnv => $keyPath];
        // Audit §3.2 (wildcard-rotation cure): a MISSING PEM still fails fast
        // pre-bind (byte-ident message); an exists-but-UNREADABLE PEM degrades
        // to a loud boot warning plus the per-connection watch below — an
        // off-pipeline delivery must never kill the hub's boot, and it must
        // never be silent either. Identical policy at all four surfaces.
        $decision = TlsMaterialWatch::bootDecision($envPaths, $workerLabel, $envPrefix);
        if ($decision['fatal'] !== null) {
            throw TlsMaterialWatch::failFast($decision['fatal']);
        }

        foreach ($decision['warnings'] as $bootWarning) {
            LoggerFactory::get(LogChannels::RELAY)->warning($bootWarning);
        }

        $materialWatch = new TlsMaterialWatch($surface . ' :' . $port, $envPaths);

        return [WsTlsContext::forListener($certPath, $keyPath), $materialWatch];
    }

    /**
     * This class exists so no worker may own the decision again; there is
     * nothing to instantiate.
     */
    private function __construct()
    {
    }
}
