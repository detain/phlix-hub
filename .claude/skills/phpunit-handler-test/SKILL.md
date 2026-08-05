---
name: phpunit-handler-test
description: Writes a PHPUnit 10 unit test under tests/Unit mirroring the src/ namespace, mocking Workerman\MySQL\Connection with willReturnCallback that branches on SQL fragments (str_contains), and constructing a real Ed25519KeyManager/EnrollmentJwtService against a temp dir created in setUp and cleaned in tearDown. Use when the user says 'write test', 'add unit test', 'test this handler', 'test this controller', or 'cover this class'. Do NOT use for JS/web-ui tests (use the web-ui Vitest tooling), for live-DB integration tests under tests/Integration, or for migration SQL tests.
paths:
  - tests/Unit/**/*Test.php
  - tests/**/*Test.php
---
# PHPUnit Handler/Controller Test

Writes a `tests/Unit/` PHPUnit 10 test that mirrors the `src/` layout, mocks the DB with SQL-fragment callbacks, and uses a real temp-dir `Ed25519KeyManager` when the class under test needs JWT signing. Output must look identical to existing tests in `tests/Unit/Hub/` and `tests/Unit/Http/Controllers/`.

## Critical

- The test file path mirrors the source path: `src/Hub/HeartbeatHandler.php` → `tests/Unit/Hub/HeartbeatHandlerTest.php`; `src/Http/Controllers/ServerClaimController.php` → `tests/Unit/Http/Controllers/ServerClaimControllerTest.php`.
- Namespace mirrors the path under `Phlix\Hub\Tests\` (PSR-4 `Phlix\Hub\Tests\`→`tests/`). E.g. `tests/Unit/Hub/` → `namespace Phlix\Hub\Tests\Unit\Hub;`.
- Every file starts with `<?php` then a blank line then `declare(strict_types=1);` — never omit either.
- The class is `final`, extends `PHPUnit\Framework\TestCase`, and carries a `@covers \Fully\Qualified\ClassName` PHPDoc tag (PHPUnit 10 + `failOnRisky="true"` makes uncovered-by-design risky tests fail loudly).
- Mock ONLY `Workerman\MySQL\Connection` — never PDO/mysqli. Match SQL with `str_contains($sql, '...')`, NOT exact-string equality (queries use named `:param` placeholders that you don't want to assert verbatim).
- Use static assertion style: `self::assertSame(...)`, `self::assertTrue(...)` — matching every existing test. Do not use `$this->assert*`.
- Do NOT touch a real database. If a test needs MySQL, it belongs in `tests/Integration/` and is out of scope for this skill.

## Instructions

1. **Locate the source class and read its constructor.** Open the `src/` file for the class under test. Note every constructor parameter and its type — these become your mocks (DB `Connection`, repositories, `StructuredLogger`) or real instances (`Ed25519KeyManager`, `EnrollmentJwtService`). Verify you have the full constructor signature before writing setUp.

2. **Create the test file at the mirrored path** with the header block. Verify the namespace matches the directory before proceeding:
   ```php
   <?php

   declare(strict_types=1);

   namespace Phlix\Hub\Tests\Unit\Hub;

   use Phlix\Hub\Common\Logger\StructuredLogger;
   use Phlix\Hub\Hub\HeartbeatHandler;
   use PHPUnit\Framework\TestCase;
   use Workerman\MySQL\Connection;

   /**
    * Unit tests for {@see HeartbeatHandler}.
    *
    * @package Phlix\Hub\Tests\Unit\Hub
    *
    * @covers \Phlix\Hub\Hub\HeartbeatHandler
    */
   final class HeartbeatHandlerTest extends TestCase
   {
   }
   ```

3. **If the class signs/validates JWTs, add temp-dir lifecycle** (uses the output from Step 1: presence of an `Ed25519KeyManager`/`EnrollmentJwtService` dependency). Copy this exact setUp/tearDown — the key file lives under a per-run `uniqid()` dir and is fully removed after:
   ```php
   private string $tmpDir;

   protected function setUp(): void
   {
       parent::setUp();
       $this->tmpDir = sys_get_temp_dir() . '/phlix-hub-<class>-test-' . uniqid();
       mkdir($this->tmpDir, 0700, true);
   }

   protected function tearDown(): void
   {
       parent::tearDown();
       $files = glob($this->tmpDir . '/*');
       foreach ($files as $file) {
           if (is_file($file)) {
               unlink($file);
           }
       }
       if (is_dir($this->tmpDir)) {
           rmdir($this->tmpDir);
       }
   }
   ```
   Then build the real services inside each test (or setUp): `$keyManager = new Ed25519KeyManager($this->tmpDir . '/key.pem');` and `$jwtService = new EnrollmentJwtService($keyManager, 'https://hub.example.com');`. Mint test tokens with `$jwtService->createEnrollmentJwt($serverId)` and read the kid via `$keyManager->getKid()`.

4. **For a HANDLER test, mock the Connection with a SQL-branching callback.** The handler calls `$db->query($sql, $params)`; return array-of-rows for SELECTs and `[]` for writes. Branch on fragments and assert on the SQL inside the callback when you need to prove a write happened:
   ```php
   $db = $this->createMock(Connection::class);
   $db->method('query')->willReturnCallback(function (string $sql) use ($serverId) {
       if (str_contains($sql, 'FOR UPDATE')) {
           return [['id' => $serverId]];
       }
       if (str_contains($sql, 'UPDATE servers')) {
           self::assertStringContainsString("status = 'online'", $sql);
           return [];
       }
       return [];
   });
   ```
   Mock collaborator dependencies the same way: `$logger = $this->createMock(StructuredLogger::class);`, `$users = $this->createMock(UserRepository::class);` with `$users->method('findByEmail')->willReturn([...]);`. Verify each `willReturn`/`willReturnCallback` covers every query path the method executes before running the test.

5. **For a CONTROLLER test, construct a real `Request` and mock the handler.** Controllers depend on domain handlers, not the DB directly — mock the handler, build `new Phlix\Hub\Http\Request()` and set its public props (`->method`, `->path`, `->headers[...]`, `->body`, `->userId`). Assert on the returned `Response`'s `statusCode` and `body`:
   ```php
   $handler = $this->createMock(ClaimRequestHandler::class);
   $controller = new ServerClaimController($handler);

   $request = new Request();
   $request->method = 'POST';
   $request->path = '/api/v1/server-claims/claim';
   $request->userId = 'user-1';
   $request->body = ['claim_code' => 'ABC'];

   $response = $controller->claim($request);
   self::assertSame(400, $response->statusCode);
   self::assertStringContainsString('claim_code is required', $response->body);
   ```
   Always include an auth-gate test: a request with no `->userId` must return `401` and the body must contain the auth error code (e.g. `UNAUTHENTICATED` / `auth.required`).

6. **Cover the error paths the source throws.** For each `throw` in the source, add a test using `$this->expectException(\InvalidArgumentException::class);` and, when the source sets one, `$this->expectExceptionMessage('SERVER_NOT_FOUND');` or `$this->expectExceptionCode(404);`. Set the mock to return `[]` (empty result) to trigger not-found branches.

7. **Run the new test alone, then the static analysers.** Verify all three are green before reporting done:
   ```bash
   ./vendor/bin/phpunit --filter HeartbeatHandlerTest
   ./vendor/bin/phpstan analyze --no-progress
   ./vendor/bin/psalm --no-progress
   ```
   PHPStan runs at level 9 and Psalm at errorLevel 1 with NO baselines — a new untyped closure param or missing return type will fail the build, not just the test.

## Examples

**User says:** "write a test for the RenewHandler"

**Actions taken:**
1. Read `src/Hub/RenewHandler.php`; constructor takes `(Connection $db, EnrollmentJwtService $jwt, StructuredLogger $logger)`.
2. Create `tests/Unit/Hub/RenewHandlerTest.php`, namespace `Phlix\Hub\Tests\Unit\Hub`, `@covers \Phlix\Hub\Hub\RenewHandler`.
3. Because it validates a JWT, add the temp-dir setUp/tearDown and build a real `Ed25519KeyManager`/`EnrollmentJwtService` against `$this->tmpDir . '/key.pem'`.
4. Mock `Connection` with a `willReturnCallback` returning `[['id' => $serverId]]` for the `SELECT ... FOR UPDATE` and `[]` for the `UPDATE servers` write.
5. Add a happy-path test (mint a token, call `handle()`, assert no throw), an invalid-token test (`expectException(\InvalidArgumentException::class)`), and an unknown-server test (`willReturn([])` + `expectExceptionMessage('SERVER_NOT_FOUND')`).
6. Run `./vendor/bin/phpunit --filter RenewHandlerTest` then phpstan + psalm.

**Result:** `tests/Unit/Hub/RenewHandlerTest.php` — a `final` TestCase, static `self::assert*`, no real DB, all green under PHPStan 9 + Psalm 1.

## Common Issues

- **`Error: Call to a member function query() on null` / mock returns null:** you set `->method('query')->willReturn(...)` for one path but the method runs several queries. Switch to `->willReturnCallback` and branch on every SQL fragment the method executes (Step 4).
- **`Risky test ... did not perform any assertions`** (fails because `failOnRisky="true"`): add at least one `self::assert*`. For a void method with no return, end with `self::assertTrue(true);` after the call, or assert inside the query callback (as `HeartbeatHandlerTest::testHandleUpdatesLastSeenAndStatus` does).
- **`Class "Phlix\Hub\Tests\Unit\..." not found` when running --filter:** the namespace doesn't match the directory under `tests/`, or you ran from outside the repo root. Confirm `namespace` mirrors the path (PSR-4 `Phlix\Hub\Tests\`→`tests/`) and run `composer dump-autoload`.
- **`expectExceptionMessage` fails but the exception was thrown:** the source uses an error CODE not a message. Use `$this->expectExceptionCode(404)` instead, matching what the source `throw` actually sets (see `LibrarySharingHandlerTest`).
- **PHPStan: `Parameter $sql of closure has no type` / Psalm `MissingClosureParamType`:** type the callback params — `function (string $sql, array $params)` — every existing callback does. No-baseline analysers reject untyped closures.
- **JWT token won't validate in-test (`validateEnrollmentJwt` returns null):** you minted with one `Ed25519KeyManager` and validated against a different kid. Read the kid from the SAME manager: `$keyManager->getKid()`. The kid is a stable fingerprint of the persisted key (see `EnrollmentJwtServiceTest::testEnrollmentJwtSurvivesKeyManagerReload`).
- **Leftover `/tmp/phlix-hub-*` dirs after a failed run:** a test threw before tearDown's `rmdir`. Harmless, but confirm setUp uses `uniqid()` so parallel/repeated runs never collide on the key path.