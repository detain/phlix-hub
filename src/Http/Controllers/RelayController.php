<?php

/**
 * Phlix hub component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;
use Phlix\Hub\Hub\EnrollmentJwtService;
use Phlix\Hub\Jwt\JwtHeader;

/**
 * Handles the relay tunnel HTTP endpoint and WebSocket upgrade.
 *
 * The server connects to `POST /api/v1/servers/{id}/relay` to:
 *   - Perform HTTP/WebSocket upgrade to establish a persistent relay tunnel
 *   - The hub then multiplexes inbound client requests over this tunnel
 *
 * Error wire codes follow the registry dual-placement emit of the #318
 * follow-up wave — dotted `code` + legacy literal byte-identical in the
 * `error` TEXT, written inline per site for wire-law visibility. See the
 * class docblock of {@see SubdomainController}.
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class RelayController
{
    /** @var EnrollmentJwtService */
    private EnrollmentJwtService $jwtService;

    /**
     * @param EnrollmentJwtService $jwtService JWT service for token validation.
     */
    public function __construct(
        EnrollmentJwtService $jwtService,
    ) {
        $this->jwtService = $jwtService;
    }

    /**
     * Handle relay tunnel connection and upgrade.
     *
     * @param Request          $request Workerman HTTP request.
     * @param array<string, string> $params Route params containing 'id' (server UUID).
     *
     * @return Response
     *
     */
    public function handle(Request $request, array $params): Response
    {
        $serverId = $params['id'] ?? '';

        if ($serverId === '') {
            return (new Response())->error(400, 'missing_server_id', 'MISSING_SERVER_ID', [
                'message' => 'Server ID is required',
            ]);
        }

        // Read through getHeader(): $request->headers is case-NORMALISED at the
        // Workerman boundary (Workerman lowercases, collectHeadersFromWorkerman()
        // then uppercases), so indexing the raw bag with a literal key is a
        // silent miss — and with `?? ''` it degrades to "no token" rather than
        // erroring, which made this gate reject unconditionally in production
        // while its unit tests, which hand-set the mixed-case key, stayed green.
        // getHeader() compares with strcasecmp() and is correct whatever the
        // boundary does. See tests/Unit/Http/RawHeaderIndexGateTest.php.
        $authHeader = $request->getHeader('Authorization') ?? '';
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return (new Response())->error(401, 'auth.required', 'UNAUTHORIZED', [
                'message' => 'Missing or invalid Authorization header',
            ]);
        }

        $enrollmentJwt = substr($authHeader, 7);

        try {
            $kid = JwtHeader::kid($enrollmentJwt);
            if ($kid === null) {
                return (new Response())->error(401, 'auth.required', 'UNAUTHORIZED', [
                    'message' => 'Invalid token format',
                ]);
            }

            $payload = $this->jwtService->validateEnrollmentJwt($enrollmentJwt, $kid);
            if ($payload === null) {
                return (new Response())->error(401, 'auth.enrollment_expired', 'UNAUTHORIZED', [
                    'message' => 'Invalid or expired enrollment token',
                ]);
            }

            if (($payload['server_id'] ?? '') !== $serverId) {
                return (new Response())->error(401, 'auth.server_mismatch', 'UNAUTHORIZED', [
                    'message' => 'Server ID mismatch',
                ]);
            }
        } catch (\InvalidArgumentException $e) {
            return (new Response())->error(401, 'auth.required', 'UNAUTHORIZED', [
                'message' => $e->getMessage(),
            ]);
        }

        // SH-077/SH-081 seam (ruling 20): the endpoint signpost is computed ONCE
        // above BOTH steer arms. TLS-lane truth: the steered SCHEME tracks the
        // listener's bind-time posture (config `relay_tls` / HUB_RELAY_TLS — same
        // truthiness idiom as config/server.php). Announcing `ws://` against a
        // wss:// bind (or the reverse) sends operators into the endless-reconnect
        // shape RelayConsumer itself documents. The PORT remains the fixed
        // default — that limitation is documented, unchanged.
        $docsUrl = 'https://detain.github.io/phlix-docs/dev/relay-protocol';
        $hubWsHost = getenv('HUB_WS_HOST');
        $hubWsHost = is_string($hubWsHost) && $hubWsHost !== '' ? $hubWsHost : getenv('HUB_PUBLIC_DOMAIN');
        $hubWsHost = is_string($hubWsHost) && $hubWsHost !== '' ? $hubWsHost : 'your-hub-host';
        $relayTls = filter_var(
            ($v = getenv('HUB_RELAY_TLS')) !== false && $v !== '' ? $v : 'false',
            FILTER_VALIDATE_BOOLEAN,
        );
        $wsEndpoint = ($relayTls ? 'wss://' : 'ws://') . $hubWsHost . ':8802';

        // Same normalisation caveat as the Authorization read above — the raw
        // bag only ever holds 'UPGRADE', so the literal 'Upgrade' never matched
        // and this branch fired for every caller, making the 501 below dead.
        //
        // SH-081 (hub__copywriter #1): the old copy promised "Please connect via
        // WSS." unconditionally — a FALSE scheme promise on every hub that binds
        // :8802 in plaintext (the default). The leg now steers through the same
        // seam as the 501 below, so its sentence names the endpoint the hub
        // actually serves, and the machine-readable `ws_endpoint` field joins the
        // frame so callers never parse prose for the address.
        if ($request->getHeader('Upgrade') !== 'websocket') {
            return (new Response())->error(426, 'relay.ws_http_endpoint', 'UPGRADE_REQUIRED', [
                'message' => 'This endpoint requires a WebSocket upgrade.'
                    . ' Connect to ' . $wsEndpoint . '.',
                'upgrade' => 'websocket',
                'ws_endpoint' => $wsEndpoint,
            ]);
        }

        // The WS upgrade + multiplex tunnel is not implemented in this build.
        // Return 501 Not Implemented (RFC 9110 §15.6.2) — the canonical status
        // for "this server doesn't know how to fulfill the request method"
        // — instead of 500 (which would imply a transient server fault).
        // Auth still runs above so unauth attempts still get 401/403.
        //
        // SH-081 (hub__copywriter #4/#5): the steer family speaks one canonical
        // sentence (byte-ident here and in ClientMountController's arms, modulo
        // the surface noun + credential phrase), and the `protocol` key — which
        // carried PROSE in a machine-field slot, was read by zero consumers, and
        // named nothing resembling a protocol identifier — is retired. The
        // pointer to the framing spec rides its honest legs: the `docs` field
        // and the Link header.
        return (new Response())
            ->header('Link', '<' . $docsUrl . '>; rel="help"')
            ->header('X-WS-Endpoint', $wsEndpoint)
            ->status(501)
            ->json([
                'error'   => 'NOT_IMPLEMENTED_VIA_HTTP',
                'code'    => 'relay.ws_http_endpoint',
                'message' => 'The relay tunnel must be established via WebSocket.'
                    . ' Connect to ' . $wsEndpoint . ' with your enrollment JWT.',
                'ws_endpoint' => $wsEndpoint,
                'docs'        => $docsUrl,
            ]);
    }
}
