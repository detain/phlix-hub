---
name: enrollment-jwt
description: Implements Ed25519 enrollment-JWT auth for server-facing endpoints in phlix-hub: the extractKid() header helper, EnrollmentJwtService::validateEnrollmentJwt(), and the server_id-vs-path match gate used by RelayController, SubdomainController, EnrollmentJwtMiddleware, and HeartbeatHandler. Use when the user says 'verify enrollment token', 'add bearer auth to a server endpoint', 'relay/heartbeat/subdomain auth', or guards a /api/v1/servers/{id}/* route. Do NOT use for user-session HS256 login JWTs (those use Phlix\Hub\Auth\AuthManager + JwtHandler with `aud=hub`); do NOT use for issuing tokens at claim time (that is createEnrollmentJwt in ClaimRequestHandler).
paths:
  - src/Http/Controllers/*Controller.php
  - src/Http/Middleware/EnrollmentJwtMiddleware.php
  - src/Hub/*Handler.php
  - src/Hub/EnrollmentJwtService.php
---
# Enrollment JWT Auth

Enrollment JWTs authenticate **enrolled media servers** calling `/api/v1/servers/{id}/*` routes. They are Ed25519 (EdDSA), `aud="server"`, `iss="phlix-hub"`, minted by `EnrollmentJwtService::createEnrollmentJwt()` at claim time. They are NOT the same as user-session HS256 JWTs (`Phlix\Hub\Auth\JwtHandler`, `aud="hub"`).

## Critical

- NEVER trust the `kid` blindly. Always `extractKid()` from the token header, then pass it as the second arg to `validateEnrollmentJwt($token, $kid)`. The service rejects (`return null`) when `$expectedKid !== $keyManager->getKid()`.
- ALWAYS gate on `$payload['server_id']` matching the path `{id}`. A valid token for server A must NOT authorize actions on server B. Pattern: `if (($payload['server_id'] ?? '') !== $serverIdFromPath) { /* reject */ }`.
- `validateEnrollmentJwt()` returns `array<string,mixed>|null` — `null` means invalid/expired/wrong-issuer/wrong-audience. NEVER treat a non-null falsy payload as failure; check strictly `=== null`.
- Do NOT add a JWT/firebase library. This repo hand-rolls JWT with `sodium_crypto_sign_*` and base64url helpers in `src/Hub/EnrollmentJwtService.php`. Reuse the service; do not reimplement signature verification.
- `EnrollmentJwtService` is constructed via PHP-DI factory in `src/Common/Container/Providers/HubServicesProvider.php` (singleton, `new EnrollmentJwtService($keyManager, $hubBaseUrl)`). Inject it; never `new` it in production code.
- `declare(strict_types=1);` at top of every file. PHPStan level 9 + Psalm errorLevel 1 must stay green (no baselines).

## Which pattern to use

Three call sites exist — pick by layer:

| Layer | Example | On failure | server_id source |
|-------|---------|-----------|------------------|
| HTTP Controller (inline) | `RelayController`, `SubdomainController` | `return (new Response())->status(401)->json([...])` | `$params['id']` |
| HTTP Middleware | `EnrollmentJwtMiddleware` | `return` a 401 `Response` (short-circuit); else `return null` | sets `$request->serverId` from payload |
| Domain Handler | `HeartbeatHandler` | `throw new \InvalidArgumentException('CODE')` | method arg `$serverId` |

Error-code conventions differ per layer — match the existing one exactly:
- Controllers: `error: 'UNAUTHORIZED'` / `'MISSING_SERVER_ID'`, with `message`.
- Middleware: `error: 'Unauthorized'`, `code: 'ENROLLMENT_TOKEN_EXPIRED'`.
- Handlers: throw `InvalidArgumentException` with message `'ENROLLMENT_TOKEN_EXPIRED'` (bad token) or `'SERVER_NOT_FOUND'` (server_id mismatch / no DB row).

## Instructions

### A. Inline controller auth (RelayController / SubdomainController pattern)

1. Constructor-inject the service: `public function __construct(private readonly EnrollmentJwtService $jwtService) {}`. Verify the import `use Phlix\Hub\Hub\EnrollmentJwtService;` is present before proceeding.
2. Pull `$serverIdFromPath = $params['id'] ?? '';`. If empty, return `status(400)->json(['error' => 'MISSING_SERVER_ID', 'message' => 'Server ID is required'])`.
3. Read the header and require the Bearer prefix:
   ```php
   $authHeader = $request->headers['Authorization'] ?? '';
   if (!str_starts_with($authHeader, 'Bearer ')) {
       return (new Response())->status(401)->json([
           'error' => 'UNAUTHORIZED',
           'message' => 'Missing or invalid Authorization header',
       ]);
   }
   $enrollmentJwt = substr($authHeader, 7);
   ```
4. Run the canonical auth block (copy verbatim, adjust nothing but variable names):
   ```php
   try {
       $kid = $this->extractKid($enrollmentJwt);
       if ($kid === null) {
           return $this->unauthorized('Invalid token format');
       }
       $payload = $this->jwtService->validateEnrollmentJwt($enrollmentJwt, $kid);
       if ($payload === null) {
           return $this->unauthorized('Invalid or expired enrollment token');
       }
       if (($payload['server_id'] ?? '') !== $serverIdFromPath) {
           return $this->unauthorized('Server ID mismatch');
       }
   } catch (\InvalidArgumentException $e) {
       return $this->unauthorized($e->getMessage());
   }
   ```
5. Add the two private helpers `unauthorized(string $message): Response` and `extractKid(string $token): ?string` exactly as in `src/Http/Controllers/RelayController.php:122-157`. Do NOT alter `extractKid` — it `explode('.')`, requires 3 parts, base64url-decodes `parts[0]`, json_decodes with depth `2`, returns `is_string($kid) ? $kid : null`, and swallows `\JsonException` returning `null`.
6. Only after the auth block passes, do the real work and return your success `Response`.
7. Register the route+controller in `HubServicesProvider` with `->parameter('jwtService', get(EnrollmentJwtService::class))`. Verify with `./vendor/bin/phpstan analyze --no-progress`.

### B. Middleware auth (EnrollmentJwtMiddleware pattern)

Use this when MANY routes share the gate and you want `$request->serverId` populated for downstream controllers.

1. Implement `__invoke(Request $request): ?Response`. Read `$token = $request->bearerToken;` (already extracted in `src/Http/Request.php`). If `null`/empty → `return $this->unauthorized('ENROLLMENT_TOKEN_EXPIRED');`.
2. `$kid = $this->extractKid($token);` null → same 401. `$payload = $this->jwtService->validateEnrollmentJwt($token, $kid);` null → same 401.
3. On success, populate the request and continue: `$request->serverId = is_string($payload['server_id'] ?? null) ? $payload['server_id'] : null; return null;`. `return null` means "continue routing".
4. `unauthorized()` returns `status(401)->json(['error' => 'Unauthorized', 'code' => $code])`. Mirror `src/Http/Middleware/EnrollmentJwtMiddleware.php` exactly.

### C. Domain-handler auth (HeartbeatHandler pattern)

Use inside a `src/Hub/*Handler.php` that receives the raw token + serverId as method args.

1. Constructor-inject `EnrollmentJwtService $jwtService` (alongside `Workerman\MySQL\Connection $db` and `StructuredLogger $logger`).
2. At the top of the handle method:
   ```php
   $tokenKid = $this->extractKidFromToken($enrollmentJwt);
   if ($tokenKid === null) {
       throw new InvalidArgumentException('ENROLLMENT_TOKEN_EXPIRED');
   }
   $payload = $this->jwtService->validateEnrollmentJwt($enrollmentJwt, $tokenKid);
   if ($payload === null) {
       throw new InvalidArgumentException('ENROLLMENT_TOKEN_EXPIRED');
   }
   if (($payload['server_id'] ?? '') !== $serverId) {
       throw new InvalidArgumentException('SERVER_NOT_FOUND');
   }
   ```
3. Then run DB work using **named `:param` placeholders only** (positional `?` breaks `bindMore()`), e.g. `SELECT id FROM servers WHERE id = :id FOR UPDATE` with `['id' => $serverId]`. See `src/Hub/HeartbeatHandler.php`.
4. The caller is responsible for mapping `InvalidArgumentException` to HTTP status (401 vs 404 by message).

## Examples

**User says:** "Add bearer enrollment auth to a new `POST /api/v1/servers/{id}/metrics` controller."

**Actions taken:**
1. Create `src/Http/Controllers/MetricsController.php`, `declare(strict_types=1);`, `namespace Phlix\Hub\Http\Controllers;`, `use` Request/Response/EnrollmentJwtService.
2. `final class MetricsController` with `public function __construct(private readonly EnrollmentJwtService $jwtService) {}`.
3. `public function record(Request $request, array $params): Response` — copy the Step A.2→A.4 blocks, then handle metrics, then `return (new Response())->json([...])`.
4. Append the verbatim `unauthorized()` + `extractKid()` private helpers.
5. Register in `HubServicesProvider` with `->parameter('jwtService', get(EnrollmentJwtService::class))` and add the route.
6. Add `tests/Unit/Http/Controllers/MetricsControllerTest.php` mirroring `RelayControllerTest`: build `Ed25519KeyManager` over a temp `signing-key.pem`, `new EnrollmentJwtService($km, 'https://hub.example.com')`, mint a token via `createEnrollmentJwt($serverId)`, set `Bearer ` header, assert 400/401/mismatch/success.

**Result:** New endpoint rejects missing header (401 `UNAUTHORIZED`), bad token (401), and server_id mismatch (401 `Server ID mismatch`); authorized server records metrics. `./vendor/bin/phpstan analyze --no-progress` and `./vendor/bin/phpunit` pass green.

## Common Issues

- **`validateEnrollmentJwt()` always returns `null` for a freshly minted token in a test:** The test's `Ed25519KeyManager` must point at the SAME key file the service signs with. Construct one `Ed25519KeyManager` and pass it to the service used for both `createEnrollmentJwt` and `validateEnrollmentJwt`. Different key managers ⇒ different `kid` ⇒ the `$expectedKid !== $keyManager->getKid()` guard returns null.
- **Valid token but 401 `Server ID mismatch`:** The path `{id}` doesn't equal `$payload['server_id']`. Confirm the token was minted with the same UUID you route to: `createEnrollmentJwt($serverId)` sets both `sub` and `server_id` to `$serverId`.
- **PHPStan: `Cannot access offset 'server_id' on mixed`:** `validateEnrollmentJwt` returns `array<string,mixed>|null`. Access with `($payload['server_id'] ?? '')` and only after a `=== null` guard. For `serverId` assignment, narrow with `is_string(...)` as in the middleware.
- **`extractKid` returns null for a token you believe is valid:** It json_decodes the header at depth `2` only. Enrollment headers are flat (`alg`/`typ`/`kid`), so this is fine — a null here means the header isn't valid base64url JSON with a string `kid`. Do not raise the depth; match a real enrollment JWT header.
- **`bindMore()` error / wrong rows in handler DB calls:** You used positional `?` placeholders. Switch to named `:param` placeholders (e.g. `WHERE id = :id`) — required by `Workerman\MySQL\Connection`. See CLAUDE.md DB conventions.
- **`EnrollmentJwtService` not found by DI / null injection:** You instantiated it directly or forgot `->parameter('jwtService', get(EnrollmentJwtService::class))`. Register through `ServiceProviderInterface` in `HubServicesProvider`; never call container `set()`.
- **Confused it with user login:** If the token has `aud="hub"` and HS256, it is a user-session JWT — use `AuthManager`/`JwtHandler` + `AuthMiddleware`, NOT this skill.