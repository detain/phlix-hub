---
name: hub-controller
description: Scaffolds a new src/Http/Controllers/*Controller.php in phlix-hub following the project's auth-gate + JSON-envelope pattern: final class with injected src/Hub/ handlers, mandatory `$request->userId` 401 'auth.required' gate, body/param validation returning {error, code}, InvalidArgumentException code mapping (404/403/409/400 → 500 default), DI registration in a ServiceProvider, route wiring in Application.php behind AuthMiddleware, and a matching tests/Unit/Http/Controllers test. Use when the user says 'add controller', 'new endpoint', 'add API route', 'add API method', or registers a route in src/Application.php. Do NOT use for relay/WebSocket workers in src/Relay/, federation protocol workers, or Smarty SSR-only pages (PageController) that render templates rather than return JSON.
paths:
  - src/Http/Controllers/*.php
  - src/Application.php
  - src/Common/Container/Providers/*.php
  - tests/Unit/Http/Controllers/*.php
---
# hub-controller

Scaffold a JSON API controller in `src/Http/Controllers/` that matches the
auth-gate + JSON-envelope + `InvalidArgumentException`-code-mapping pattern used
by every existing controller (e.g. `src/Http/Controllers/LibraryShareController.php`, `src/Http/Controllers/ServerListController.php`,
`src/Http/Controllers/RequestController.php`). A controller is plumbing: a `final class` with injected
handlers that validates input, delegates to a `src/Hub/` handler, and shapes the
JSON response. **Business logic lives in the handler, not the controller.**

## Critical

- `declare(strict_types=1);` on line 3 of every file. Namespace `Phlix\Hub\Http\Controllers`.
- The class is `final`. Constructor uses promoted `private readonly` properties for injected handlers only — no `new` inside, no container access, no DB connection unless the handler genuinely needs raw SQL (`src/Http/Controllers/ServerManageController.php` is the only one that takes `Connection`).
- **Every method that touches user data MUST gate first:** `$userId = $request->userId ?? ''; if ($userId === '') return 401 'auth.required'`. Never trust the middleware alone — the inline gate is mandatory and is the first thing each method does.
- Every response is `(new Response())->status(N)->json([...])` with an `error` (human string) and `code` (machine slug) key. 200 responses may omit `->status()`. 204 responses call `->status(204)` with **no** `->json()`.
- DB placeholders inside handlers are named `:param` only — positional `?` breaks `bindMore()`. Controllers do not write SQL.
- Must end PHPStan level 9 + Psalm errorLevel 1 green with **no baseline**. `mixed` from `$request->body` must be narrowed with `is_string()`/`is_array()` and annotated `/** @var mixed $x */` before use.
- Cross-repo DTOs come from the `Phlix\Shared\*` namespace (the shared composer package) — never redefine them. Serialize via the DTO's `->toPayload()`.

## Instructions

1. **Pick the handler.** Find or confirm the `src/Hub/*Handler.php` (or service) that holds the logic. The controller only injects handlers — if the logic does not exist yet, build the handler first. Verify the handler class exists with `grep -rn "class .*Handler" src/Hub/` before proceeding.

2. **Create the new controller under `src/Http/Controllers/`** (named `{Name}Controller.php`, alongside existing ones such as `src/Http/Controllers/LibraryShareController.php`). Copy this skeleton, replacing `{Name}` and `{Handler}`. Each public method maps to one route and is documented with its verb+path:

   ```php
   <?php

   declare(strict_types=1);

   namespace Phlix\Hub\Http\Controllers;

   use InvalidArgumentException;
   use Phlix\Hub\Hub\{Handler};
   use Phlix\Hub\Http\Request;
   use Phlix\Hub\Http\Response;

   /**
    * API controller for {feature} endpoints.
    *
    * @package Phlix\Hub\Http\Controllers
    */
   final class {Name}Controller
   {
       public function __construct(
           private readonly {Handler} $handler,
       ) {
       }

       /**
        * `POST /api/v1/me/{resource}` — create a {resource}.
        */
       public function create(Request $request): Response
       {
           $userId = $request->userId ?? '';
           if ($userId === '') {
               return (new Response())->status(401)->json([
                   'error' => 'Unauthorized',
                   'code' => 'auth.required',
               ]);
           }

           $body = $request->body;
           if ($body === []) {
               return (new Response())->status(400)->json([
                   'error' => 'Bad Request',
                   'code' => 'invalid_body',
               ]);
           }

           /** @var mixed $name */
           $name = $body['name'] ?? null;
           if (!is_string($name) || $name === '') {
               return (new Response())->status(400)->json([
                   'error' => 'Bad Request',
                   'code' => 'missing_name',
               ]);
           }

           try {
               $dto = $this->handler->create(ownerId: $userId, name: $name);
               return (new Response())->status(201)->json($dto->toPayload());
           } catch (InvalidArgumentException $e) {
               return $this->mapError($e);
           }
       }
   }
   ```

   For routes with a path param, the method signature is `public function get(Request $request, array $params): Response` and you read `$id = $params['id'] ?? '';` (return 400 `missing_{resource}_id` when empty). List endpoints serialize with `array_map(fn ($d) => $d->toPayload(), $items)`. **Verify the file has `declare(strict_types=1);`, is `final`, and every method starts with the userId gate before continuing.**

3. **Map handler exceptions by code.** The handler throws `InvalidArgumentException` with an HTTP-status integer code (`new InvalidArgumentException('...', 404)`). Translate it — inline (as in `src/Http/Controllers/LibraryShareController.php`) or via a small private helper. The default branch is always 500 `unknown_error`:

   ```php
   private function mapError(InvalidArgumentException $e): Response
   {
       $code = $e->getCode();
       if ($code === 404) {
           return (new Response())->status(404)->json(['error' => 'Not Found', 'code' => 'not_found']);
       }
       if ($code === 403) {
           return (new Response())->status(403)->json(['error' => 'Forbidden', 'code' => 'not_owner']);
       }
       if ($code === 409) {
           return (new Response())->status(409)->json(['error' => 'Conflict', 'code' => 'already_exists']);
       }
       if ($code === 400) {
           return (new Response())->status(400)->json(['error' => 'Bad Request', 'code' => 'invalid_request', 'message' => $e->getMessage()]);
       }
       return (new Response())->status(500)->json(['error' => 'Internal Server Error', 'code' => 'unknown_error']);
   }
   ```

   Ownership/permission failures (403) and not-found (404) come from the handler — the controller does not query the DB to check ownership itself. **Verify every `try` that calls the handler has a matching `catch (InvalidArgumentException $e)`.**

4. **Register in the container.** Add a `factory()` entry to the matching provider — generic HTTP controllers go in `src/Common/Container/Providers/HttpServicesProvider.php`, Hub/domain ones in `src/Common/Container/Providers/HubServicesProvider.php`. Never call `$container->set()`. The factory's typed params are autowired; for ambiguous binds add `->parameter('handler', get({Handler}::class))`:

   ```php
   {Name}Controller::class => factory(static function (
       {Handler} $handler,
   ): {Name}Controller {
       return new {Name}Controller($handler);
   })->parameter('handler', get({Handler}::class)),
   ```

   This uses the handler from Step 1. **Verify with `grep -n "{Name}Controller::class" src/Common/Container/Providers/`.**

5. **Add a resolve helper + register the route in `src/Application.php`.** Add the `use` import at the top, then a private resolver mirroring the existing ones (the `instanceof` guard satisfies PHPStan):

   ```php
   private function resolve{Name}Controller(): {Name}Controller
   {
       $controller = $this->container->get({Name}Controller::class);
       if (!$controller instanceof {Name}Controller) {
           throw new \RuntimeException('Container returned an unexpected {Name}Controller instance');
       }
       return $controller;
   }
   ```

   Then register inside a `$this->router->group('/api/v1/...', function (Router $r) use (...) { ... }, [$authMiddleware])`. Authenticated routes always pass `[$authMiddleware]` (from `$this->resolveAuthMiddleware()`). Route params are narrowed before the call:

   ```php
   $ctrl = $this->resolve{Name}Controller();
   $this->router->group('/api/v1/me/{resource}', static function (Router $r) use ($ctrl): void {
       $r->post('', static fn (Request $req): Response => $ctrl->create($req));
       $r->get('/{id}', static function (Request $req, array $params) use ($ctrl): Response {
           /** @var array<string, string> $typedParams */
           $typedParams = $params;
           return $ctrl->get($req, $typedParams);
       });
   }, [$authMiddleware]);
   ```

   For admin-only routes add `$adminMiddleware = $this->resolveAdminMiddleware();` and pass `[$authMiddleware, $adminMiddleware]`, **and** keep an inline `requireAdmin()` check in the controller (defense in depth — see `src/Http/Controllers/RequestController.php` `requireAdmin()`, which calls `$this->users->findAdminById($userId)` and returns 403 `admin_required`). **Verify route registration with `grep -n "{resource}" src/Application.php`.**

6. **Write the test** at `tests/Unit/Http/Controllers/{Name}ControllerTest.php`. Mock the handler with `$this->createMock({Handler}::class)`, build a bare `new Request()`, set `$request->userId`/`$request->body` directly, and assert on `$response->statusCode` and `$response->body` (a JSON string — use `assertStringContainsString`). Always cover the 401 path:

   ```php
   public function testReturns401WhenUserIdMissing(): void
   {
       $controller = new {Name}Controller($this->createMock({Handler}::class));
       $response = $controller->create(new Request());
       self::assertSame(401, $response->statusCode);
       self::assertStringContainsString('auth.required', $response->body);
   }
   ```

   Add `@covers \Phlix\Hub\Http\Controllers\{Name}Controller` to the class docblock. **Run the gates below and confirm all green before finishing.**

7. **Validate.** Run, in order, and fix any failure before claiming done:
   ```bash
   ./vendor/bin/phpunit --filter {Name}ControllerTest
   ./vendor/bin/phpstan analyze --no-progress
   ./vendor/bin/psalm --no-progress
   ./vendor/bin/phpcs --standard=PSR12 src/
   ```

## Examples

**User says:** "Add an endpoint to rename one of my servers — `PATCH /api/v1/me/servers/{id}/name`."

**Actions taken:**
1. Confirm `ServerInfoHandler` (in `src/Hub/`) has/needs a `renameServer(ownerId, serverId, name)` that throws `InvalidArgumentException(404)` if the server is missing and `(403)` if the caller is not the owner.
2. Since this is server management it joins `src/Http/Controllers/ServerManageController.php` rather than a new class — add `public function renameServer(Request $request, array $params): Response`: userId 401 gate → read `$params['id']` (400 `missing_server_id` if empty) → narrow `$request->body['name']` (400 `missing_name`) → `try { $dto = $handler->renameServer(...); return (new Response())->json($dto->toPayload()); } catch (InvalidArgumentException $e) { /* 404/403 map */ }`.
3. `ServerManageController` is already container-registered in `src/Common/Container/Providers/HttpServicesProvider.php`, so no provider change.
4. In `src/Application.php`, inside the existing `/api/v1` group with `[$authMiddleware]`, add `$r->patch('/me/servers/{id}/name', ...)` with the `/** @var array<string,string> $typedParams */` narrowing.
5. Extend `tests/Unit/Http/Controllers/ServerManageControllerTest.php`: 401-when-no-userId, 404-when-handler-throws-404, 200-with-payload-on-success.

**Result:** `PATCH /api/v1/me/servers/{id}/name` returns the server payload (200), `{"error":"Not Found","code":"not_found"}` (404), `{"error":"Unauthorized","code":"auth.required"}` (401). PHPStan/Psalm/phpcs green.

## Common Issues

- **`Parameter #1 ... expects array<string, string>, array<string, mixed> given` (PHPStan/Psalm):** the closure's `array $params` is `array<string,mixed>`. Add `/** @var array<string, string> $typedParams */ $typedParams = $params;` and pass `$typedParams` — see every `$r->get('/{id}', ...)` in `src/Application.php`.
- **`Cannot call method toPayload() on mixed` / `Possibly invalid argument`:** you used `$request->body['x']` without narrowing. Assign to a `/** @var mixed $x */` var, then `if (!is_string($x)) return 400`. Psalm treats `$request->body` values as `mixed`.
- **DI `Entry ... cannot be resolved` / wrong instance at boot:** the controller isn't registered, or is in the wrong provider. Add the `factory()` to `src/Common/Container/Providers/HttpServicesProvider.php` (generic) or `src/Common/Container/Providers/HubServicesProvider.php` (domain). For an ambiguous constructor arg add `->parameter('name', get(Type::class))`. Confirm: `php start.php start` boots without a `RuntimeException` from the resolve helper.
- **Endpoint returns 401 even when logged in:** the route group is missing `[$authMiddleware]`, so `$request->userId` is never populated. Add the middleware array as the 3rd arg to `$this->router->group(...)`.
- **`bindMore(): ...` or empty result from a handler query:** the handler used positional `?` placeholders. Switch to named `:param` — positional `?` is unsupported by `Workerman\MySQL\Connection`.
- **phpcs PSR12 failure on the new file:** usually a missing blank line after the namespace/use block or wrong brace placement. Run `./vendor/bin/phpcbf --standard=PSR12 src/Http/Controllers/` (or pass the single new file) to auto-fix.
