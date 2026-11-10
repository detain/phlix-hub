<?php

/**
 * Phlix hub component: Relay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Relay\RelayWorker;
use Phlix\Hub\Relay\TlsMaterialWatch;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Audit §3.2 (wildcard-rotation cure) laws for {@see TlsMaterialWatch}.
 *
 *   1. classify() splits each configured path into OK / MISSING / UNREADABLE
 *      (probe-injectable — root can read 0000 files, so the unreadable arm
 *      can NOT be proven with real chmod everywhere; the seam proves it
 *      deterministically on every venue);
 *   2. bootDecision() keeps the historical fail-fast contract for MISSING
 *      files BYTE-IDENTICAL to RelayWorker::assertTlsMaterialReadable()'s
 *      throw (the cross-check test below pins the two together forever —
 *      the existing env-naming/cure-text pins survive the refactor at the
 *      exact same strings) and downgrades exists-but-unreadable to a loud
 *      WARNING instead of a boot-killing throw (off-pipeline perms must
 *      never brick hub boot; silence was the reviewed defect, so both the
 *      boot line and the runtime lines name the phlix-tls 640 contract);
 *   3. the runtime state machine is rate-gated: first bad event emits
 *      immediately, repeats inside the window are counted, the next
 *      out-of-window line carries the suppressed count, the bad->good
 *      transition emits a recovery line and re-arms the immediate gate;
 *   4. material notices and handshake-failure notices share ONE window
 *      (a scanner cannot turn a broken listener into a log flood).
 *
 * @package Phlix\Hub\Tests\Unit\Relay
 */
final class TlsMaterialWatchTest extends TestCase
{
    /**
     * @return array<string, string> env-name => fake path map used across tests
     */
    private static function envPaths(): array
    {
        return [
            'HUB_RELAY_TLS_CERT' => '/fake/live/fullchain.pem',
            'HUB_RELAY_TLS_KEY' => '/fake/live/privkey.pem',
        ];
    }

    /**
     * @param array<string, string> $states path => probe state
     *
     * @return \Closure(string): string
     */
    private static function fakeProbe(array $states): \Closure
    {
        return static function (string $path) use ($states): string {
            return $states[$path] ?? TlsMaterialWatch::MISSING;
        };
    }

    // ---- 1. classify -------------------------------------------------------

    public function testClassifyReturnsOnlyProblemsInConfiguredOrder(): void
    {
        $probe = self::fakeProbe([
            '/fake/live/fullchain.pem' => TlsMaterialWatch::UNREADABLE,
            '/fake/live/privkey.pem' => TlsMaterialWatch::OK,
        ]);

        $problems = TlsMaterialWatch::classify(self::envPaths(), $probe);

        self::assertSame(['HUB_RELAY_TLS_CERT'], array_keys($problems));
        self::assertSame(TlsMaterialWatch::UNREADABLE, $problems['HUB_RELAY_TLS_CERT']);
    }

    public function testClassifyHealthyMapIsEmpty(): void
    {
        $probe = self::fakeProbe([
            '/fake/live/fullchain.pem' => TlsMaterialWatch::OK,
            '/fake/live/privkey.pem' => TlsMaterialWatch::OK,
        ]);

        self::assertSame([], TlsMaterialWatch::classify(self::envPaths(), $probe));
    }

    public function testDefaultProbeSeesRealReadableFileAsOkAndGhostAsMissing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tlsmat');
        self::assertIsString($path);

        try {
            self::assertSame(
                [],
                TlsMaterialWatch::classify(['X_CERT' => $path]),
            );
            self::assertSame(
                ['X_CERT' => TlsMaterialWatch::MISSING],
                TlsMaterialWatch::classify(['X_CERT' => $path . '.ghost']),
            );
        } finally {
            unlink($path);
        }
    }

    // ---- 2. bootDecision ---------------------------------------------------

    /**
     * The MISSING fatal must be byte-identical to the historical strict
     * guard's throw, on identical inputs. Red law: any refactor that drifts
     * one character of the operator-facing text reddens here (the
     * RelayWorkerTlsMaterialTest env-naming pins ride the same strings).
     */
    public function testBootDecisionMissingFatalIsByteIdenticalToHistoricalThrow(): void
    {
        $missing = '/definitely/not/here-' . self::class . '.pem';

        try {
            RelayWorker::assertTlsMaterialReadable($missing, $missing);
            self::fail('the strict guard must throw on missing material');
        } catch (InvalidArgumentException $historic) {
            $decision = TlsMaterialWatch::bootDecision(
                ['HUB_RELAY_TLS_CERT' => $missing, 'HUB_RELAY_TLS_KEY' => $missing],
                'relay worker',
                'HUB_RELAY_TLS',
            );
            self::assertNotNull($decision['fatal']);
            self::assertSame($historic->getMessage(), $decision['fatal']);
        }
    }

    public function testBootDecisionUnreadableDegradesToWarningsWithoutFatal(): void
    {
        $probe = self::fakeProbe([
            '/fake/live/fullchain.pem' => TlsMaterialWatch::UNREADABLE,
            '/fake/live/privkey.pem' => TlsMaterialWatch::UNREADABLE,
        ]);

        $decision = TlsMaterialWatch::bootDecision(
            self::envPaths(),
            'relay worker',
            'HUB_RELAY_TLS',
            $probe,
        );

        self::assertNull($decision['fatal']);
        self::assertCount(2, $decision['warnings']);
        self::assertStringContainsString('HUB_RELAY_TLS_CERT', $decision['warnings'][0]);
        self::assertStringContainsString('HUB_RELAY_TLS_KEY', $decision['warnings'][1]);
        foreach ($decision['warnings'] as $warning) {
            self::assertStringContainsString('exists and is not readable', $warning);
            self::assertStringContainsString('phlix-tls', $warning);
        }
    }

    public function testBootDecisionMissingOutranksUnreadable(): void
    {
        $probe = self::fakeProbe([
            '/fake/live/fullchain.pem' => TlsMaterialWatch::MISSING,
            '/fake/live/privkey.pem' => TlsMaterialWatch::UNREADABLE,
        ]);

        $decision = TlsMaterialWatch::bootDecision(
            self::envPaths(),
            'relay worker',
            'HUB_RELAY_TLS',
            $probe,
        );

        self::assertNotNull($decision['fatal']);
        self::assertStringContainsString('HUB_RELAY_TLS_CERT', (string) $decision['fatal']);
        self::assertStringContainsString('does not exist', (string) $decision['fatal']);
    }

    public function testBootDecisionHealthyBootsSilently(): void
    {
        $probe = self::fakeProbe([
            '/fake/live/fullchain.pem' => TlsMaterialWatch::OK,
            '/fake/live/privkey.pem' => TlsMaterialWatch::OK,
        ]);

        $decision = TlsMaterialWatch::bootDecision(
            self::envPaths(),
            'relay worker',
            'HUB_RELAY_TLS',
            $probe,
        );

        self::assertNull($decision['fatal']);
        self::assertSame([], $decision['warnings']);
    }

    // ---- 3/4. runtime state machine ----------------------------------------

    public function testFirstBadMaterialNoticeEmitsImmediatelyWithBothEnvNames(): void
    {
        $watch = new TlsMaterialWatch(
            'relay-ws :8802',
            self::envPaths(),
            60,
            self::fakeProbe(['/fake/live/fullchain.pem' => TlsMaterialWatch::UNREADABLE]),
        );

        $line = $watch->materialNotice(1_000);
        self::assertNotNull($line);
        self::assertStringContainsString('relay-ws :8802', $line);
        self::assertStringContainsString('HUB_RELAY_TLS_CERT', $line);
        self::assertStringContainsString('is not readable', $line);
        self::assertTrue($watch->isDegraded());
    }

    public function testRepeatsInsideWindowAreCountedAndTheNextLineCarriesTheCount(): void
    {
        $watch = new TlsMaterialWatch(
            'relay-ws :8802',
            self::envPaths(),
            60,
            self::fakeProbe(['/fake/live/fullchain.pem' => TlsMaterialWatch::MISSING]),
        );

        self::assertNotNull($watch->materialNotice(1_000));
        self::assertNull($watch->materialNotice(1_010));
        self::assertNull($watch->materialNotice(1_020));

        $line = $watch->materialNotice(1_060);
        self::assertNotNull($line);
        self::assertStringContainsString('+2 similar notices suppressed', $line);
    }

    public function testRecoveryLineClearsStateAndReArmsImmediateGate(): void
    {
        $bad = self::fakeProbe(['/fake/live/fullchain.pem' => TlsMaterialWatch::UNREADABLE]);
        $good = self::fakeProbe([
            '/fake/live/fullchain.pem' => TlsMaterialWatch::OK,
            '/fake/live/privkey.pem' => TlsMaterialWatch::OK,
        ]);

        // Mutable probe: first classification bad, then good.
        /** @var array{0: string} $phase */
        $phase = ['bad'];
        $probe = static function (string $path) use ($bad, $good, &$phase): string {
            return $phase[0] === 'bad' ? $bad($path) : $good($path);
        };

        $watch = new TlsMaterialWatch('relay-ws :8802', self::envPaths(), 60, $probe);
        self::assertNotNull($watch->materialNotice(1_000));

        $phase[0] = 'good';
        $recovery = $watch->materialNotice(1_005);
        self::assertNotNull($recovery);
        self::assertStringContainsString('readable again', $recovery);
        self::assertFalse($watch->isDegraded());

        // Back to bad: immediate again (gate re-armed), not suppressed.
        $phase[0] = 'bad';
        self::assertNotNull($watch->materialNotice(1_006));
    }

    public function testHandshakeFailureNoticeCarriesTheVendorReason(): void
    {
        $watch = new TlsMaterialWatch(
            'relay-ws :8802',
            self::envPaths(),
            60,
            self::fakeProbe([
                '/fake/live/fullchain.pem' => TlsMaterialWatch::OK,
                '/fake/live/privkey.pem' => TlsMaterialWatch::OK,
            ]),
        );

        $line = $watch->handshakeFailureNotice(2_000, 'SSL handshake with tcp://1.2.3.4:5 failed: bad key');
        self::assertNotNull($line);
        self::assertStringContainsString('SSL handshake with tcp://1.2.3.4:5 failed', $line);
        self::assertStringContainsString('relay-ws :8802', $line);
        self::assertStringContainsString('phlix-tls', $line);
    }

    public function testMaterialAndHandshakeNoticesShareOneWindow(): void
    {
        // Healthy files, but the handshake fails (the perms-cure edge where
        // the cert path is fine yet enable_crypto still fails): material
        // recovery first, then two failures inside the shared window.
        $probe = self::fakeProbe([
            '/fake/live/fullchain.pem' => TlsMaterialWatch::OK,
            '/fake/live/privkey.pem' => TlsMaterialWatch::OK,
        ]);
        $watch = new TlsMaterialWatch('relay-ws :8802', self::envPaths(), 60, $probe);

        self::assertNotNull($watch->handshakeFailureNotice(5_000, 'boom one'));
        self::assertNull($watch->handshakeFailureNotice(5_010, 'boom two'));
        $line = $watch->handshakeFailureNotice(5_061, 'boom three');
        self::assertNotNull($line);
        self::assertStringContainsString('+1 similar notices suppressed', $line);

        // A material transition inside the same window is ALSO gated — the
        // stream is one incident, one line per window.
        $shared = new TlsMaterialWatch('relay-ws :8802', self::envPaths(), 60, static function (string $path): string {
            return $path === '/fake/live/fullchain.pem'
                ? TlsMaterialWatch::MISSING
                : TlsMaterialWatch::OK;
        });
        self::assertNotNull($shared->handshakeFailureNotice(9_000, 'first'));
        self::assertNull($shared->materialNotice(9_005));
        self::assertTrue($shared->isDegraded());
    }
}
