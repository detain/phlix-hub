---
name: hub-handler
description: Scaffolds a src/Hub/*Handler.php domain service for phlix-hub using Workerman\MySQL\Connection with named :param queries, the inline generateUuid() helper, StructuredLogger, and AuditLogger, plus its PHP-DI factory registration in HubServicesProvider. Use when the user says 'add handler', 'new business logic', 'claim/heartbeat/share logic', or adds a domain service that sits behind an HTTP controller. Do NOT use for HTTP-layer request parsing or Response building (that is the controller's job), and NEVER use PDO/mysqli or positional ? placeholders.
paths:
  - src/Hub/*Handler.php
  - src/Common/Container/Providers/HubServicesProvider.php
  - tests/Unit/Hub/*HandlerTest.php
---
# Hub Handler

Creates a domain service under `src/Hub/` (named `{Name}Handler.php`, e.g. alongside `src/Hub/HeartbeatHandler.php`) that owns business logic + DB access for one concern (claim, heartbeat, share, invite, deregister). Controllers parse the request and call the handler; the handler does the work and throws on failure.

## Critical

- **DB driver is `Workerman\MySQL\Connection` ONLY.** No PDO, no mysqli. Importing either, or using `new PDO(...)`, is forbidden in this repo.
- **Use named `:param` placeholders, never positional `?`.** Positional `?` breaks the async client's `bindMore()`. `$this->db->query('... WHERE id = :id', ['id' => $serverId])`. Param keys may be written with or without the leading `:` — existing code uses both (`'id'` in `src/Hub/HeartbeatHandler.php`, `':id'` in `src/Hub/AuditLogRepository.php`); pick one and be consistent within the file.
- **`declare(strict_types=1);` on line 3, namespace `Phlix\Hub\Hub`** (PSR-4 `Phlix\Hub\` → `src/`).
- **Handlers throw, they do not build HTTP responses.** Throw `InvalidArgumentException($message, $httpCode)`. The controller catches and maps to `(new Response())->json(['error' => ..., 'code' => ...])`. Do NOT import `Response` or `Request` into a handler.
- **UUIDs use the inline `generateUuid()` sprintf helper** (copy verbatim from any existing handler). Tables use `CHAR(36)` PK. Do not pull in a UUID library.
- **PHPStan level 9 + Psalm errorLevel 1 must stay green with no baseline.** Every `$this->db->query(...)` result is `mixed`/`list<array<string,mixed>>` — annotate with `/** @var list<array<string, mixed>> $rows */` before use.

## Instructions

1. **Confirm the concern is not already covered.** Run `ls src/Hub/*Handler.php`. If an existing handler owns this domain (e.g. `src/Hub/LibrarySharingHandler.php` for shares), add a method to it instead of a new class. Verify: the new responsibility does not duplicate an existing handler before proceeding.

2. **Create the handler file under `src/Hub/`** (named `{Name}Handler.php`) with constructor-promoted readonly deps. Model it on `src/Hub/HeartbeatHandler.php`. Standard dependency set — pick only what the logic needs:
   - `private readonly Connection $db` (always, if it touches the DB)
   - `private readonly StructuredLogger $logger` (always — use `LoggerFactory::get(LogChannels::HUB)` at registration time)
   - `private readonly EnrollmentJwtService $jwtService` (when validating server enrollment JWTs)
   - `private readonly UserRepository $users` (when resolving users by email/id)
   - `private readonly AuditLogger $audit` (when the action is security-relevant: auth, ownership, sharing, admin)

   ```php
   <?php

   declare(strict_types=1);

   namespace Phlix\Hub\Hub;

   use InvalidArgumentException;
   use Phlix\Hub\Common\Logger\StructuredLogger;
   use Workerman\MySQL\Connection;

   /**
    * One-line description of the concern this handler owns.
    *
    * @package Phlix\Hub\Hub
    */
   class {Name}Handler
   {
       public function __construct(
           private readonly Connection $db,
           private readonly StructuredLogger $logger,
       ) {
       }
   }
   ```
   Verify the file has `declare(strict_types=1);`, namespace `Phlix\Hub\Hub`, and promoted readonly props before adding methods.

3. **Write the public action method.** Order inside it must be: (a) validate inputs / resolve dependencies, (b) ownership & state guards that `throw new InvalidArgumentException($msg, $code)`, (c) `$now = time();` + `$id = $this->generateUuid();`, (d) the `INSERT`/`UPDATE` via named params, (e) `$this->logger->info(...)` and `$this->audit->...` if applicable, (f) `return` a DTO or void. Copy the guard/throw + insert shape from `src/Hub/LibrarySharingHandler.php` `shareLibrary()`:

   ```php
   if (!$this->isServerOwnedByUser($serverId, $ownerId)) {
       throw new InvalidArgumentException('You do not own this server', 403);
   }

   $now = time();
   $id = $this->generateUuid();
   $this->db->query(
       'INSERT INTO {table} (id, owner_user_id, created_at)
        VALUES (:id, :owner_user_id, :created_at)',
       ['id' => $id, 'owner_user_id' => $ownerId, 'created_at' => $now],
   );
   $this->logger->info('{Thing} created', ['id' => $id, 'owner_id' => $ownerId]);
   ```
   HTTP codes in use: `400` bad input, `403` not owner, `404` not found, `409` already exists. Verify every failure path throws with one of these codes.

4. **Type every query result.** Reads return `mixed`; annotate and guard:
   ```php
   /** @var list<array<string, mixed>> $rows */
   $rows = $this->db->query('SELECT id FROM servers WHERE id = :id LIMIT 1', ['id' => $serverId]);
   if (empty($rows)) {
       throw new InvalidArgumentException('SERVER_NOT_FOUND', 404);
   }
   ```
   For row-field extraction, coerce with `is_string(...)`/`is_numeric(...)` exactly as `src/Hub/LibrarySharingHandler.php` `getDistinctLibrariesForServer()` does — PHPStan level 9 rejects bare `(string) $row['x']`.

5. **Add the `generateUuid()` private helper** at the bottom — copy verbatim from `src/Hub/HeartbeatHandler.php` (lines 196-209). Do not invent a variant. Verify it is `private function generateUuid(): string` returning the 8-4-4-4-12 sprintf.

6. **Add AuditLogger calls for security-relevant events** (uses Step 2's `$this->audit`). Available methods (see `src/Common/Logger/AuditLogger.php`): `logFailedAuth(string $reason, array $context = [])`, `logPermissionDenied(string $userId, string $resource, string $action)`, `logAdminAction(...)`. Pattern from `src/Hub/ClaimRequestHandler.php`:
   ```php
   $this->audit->logFailedAuth('CLAIM_CODE_NOT_FOUND', ['claim_code' => $normalizedCode]);
   ```
   Call the audit method on the failure path *before* throwing. `StructuredLogger` is for operational logs; `AuditLogger` is for security events — use both as appropriate.

7. **Register the handler in `src/Common/Container/Providers/HubServicesProvider.php`.** Add a `use` import for the class, then a factory definition inside `register()` mirroring the existing entries. NEVER use `$container->set()` — only `factory(...)->parameter(...)`:
   ```php
   {Name}Handler::class => factory(static function (
       Connection $db,
       UserRepository $users,
   ): {Name}Handler {
       return new {Name}Handler(
           $db,
           $users,
           LoggerFactory::get(LogChannels::HUB),
       );
   })->parameter('db', get(Connection::class))
       ->parameter('users', get(UserRepository::class)),
   ```
   Note: `StructuredLogger` deps are NOT injected via `get()` — they are constructed inline with `LoggerFactory::get(LogChannels::HUB)` inside the factory closure (see the `HeartbeatHandler` and `LibrarySharingHandler` registrations in `src/Common/Container/Providers/HubServicesProvider.php`). Only the typed services (`Connection`, `UserRepository`, `EnrollmentJwtService`, `AuditLogger`) get `->parameter(...)` bindings. Verify each non-logger ctor param has a matching `->parameter()`.

8. **Wire the handler into its controller** (constructor-inject it, do not `new` it). Add the handler as a `->parameter(...)` on the controller's factory in the same provider, exactly like `src/Http/Controllers/LibraryShareController.php` depends on `LibrarySharingHandler`.

9. **Add a unit test** in `tests/Unit/Hub/{Name}HandlerTest.php` (namespace `Phlix\Hub\Tests\Unit\Hub`). Assert that invalid input / ownership failures throw `InvalidArgumentException` with the expected code, and that `generateUuid()` output matches the UUID v4 format. Mock `Connection` to return canned `list<array<string,mixed>>` rows.

10. **Validate — all four must pass:**
    ```bash
    ./vendor/bin/phpstan analyze --no-progress
    ./vendor/bin/psalm --no-progress
    ./vendor/bin/phpcs --standard=PSR12 src/
    ./vendor/bin/phpunit
    ```
    Then confirm DI resolves the new binding: `php bin/phlix smoke:jwt` boots the container; a missing `->parameter()` surfaces as a resolution error. Do not consider the task done until all four are green.

## Examples

**User says:** "Add a handler to revoke an invite link by id, only the creator can revoke it."

**Actions taken:**
1. `ls src/Hub/*Handler.php` → `src/Hub/InviteLinkHandler.php` already owns invite logic → add a `revokeInvite()` method there instead of a new class.
2. Add method:
   ```php
   public function revokeInvite(string $userId, string $inviteId): void
   {
       /** @var list<array<string, mixed>> $rows */
       $rows = $this->db->query(
           'SELECT created_by FROM invite_links WHERE id = :id LIMIT 1',
           ['id' => $inviteId],
       );
       if (empty($rows)) {
           throw new InvalidArgumentException('Invite not found', 404);
       }
       if (($rows[0]['created_by'] ?? '') !== $userId) {
           $this->audit->logPermissionDenied($userId, $inviteId, 'invite.revoke');
           throw new InvalidArgumentException('You do not own this invite', 403);
       }
       $this->db->query(
           'UPDATE invite_links SET revoked_at = :revoked_at WHERE id = :id',
           ['revoked_at' => time(), 'id' => $inviteId],
       );
       $this->logger->info('Invite revoked', ['invite_id' => $inviteId, 'user_id' => $userId]);
   }
   ```
3. `src/Http/Controllers/InviteLinkController.php` already injects `InviteLinkHandler` — add a `revoke` controller method that calls it, catches `InvalidArgumentException`, and returns `(new Response())->json(['error' => $e->getMessage(), 'code' => $e->getCode()])`.
4. `./vendor/bin/phpstan analyze && ./vendor/bin/psalm && ./vendor/bin/phpunit` → green.

**Result:** Revoke logic lives in the existing handler, named params, ownership guarded + audited, no new DI wiring needed because the controller already had the dependency.

## Common Issues

- **PHPStan: `Cannot cast mixed to string` / `Parameter expects string, mixed given`** — a query row field was used raw. Wrap with a guard: `$id = is_string($row['id'] ?? null) ? $row['id'] : '';`. Add `/** @var list<array<string, mixed>> $rows */` above the `$this->db->query(...)` assignment.
- **`Workerman\MySQL\Connection::bindMore(): Array ... count mismatch` at runtime** — you used positional `?` placeholders. Convert every `?` to a named `:param` and pass an associative array keyed by name.
- **`Entry "...Handler" cannot be resolved: Parameter $x has no value`** (PHP-DI on boot / `php bin/phlix smoke:jwt`) — a constructor param has no `->parameter('x', get(...))` binding in `src/Common/Container/Providers/HubServicesProvider.php`. Loggers are the exception: they are built inline via `LoggerFactory::get(LogChannels::HUB)`, not injected. Add the missing `->parameter()` for every typed service.
- **`Class "Phlix\Hub\Hub\{Name}Handler" not found`** — namespace must be exactly `Phlix\Hub\Hub` and the file under `src/Hub/` (e.g. `src/Hub/HeartbeatHandler.php`). Run `composer dump-autoload` if you just created it.
- **phpcs: `Each class must be in a namespace of at least one level` / line length** — confirm `declare(strict_types=1);` is on its own line (line 3) and wrap long SQL strings across multiple lines like `src/Hub/HeartbeatHandler.php` does. Re-run `./vendor/bin/phpcs --standard=PSR12 src/`.
- **Handler returns an HTTP `Response` / imports `Phlix\Hub\Http\Response`** — wrong layer. Strip the Response usage; throw `InvalidArgumentException($msg, $code)` and let the controller serialize it.
