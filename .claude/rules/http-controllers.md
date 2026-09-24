---
paths:
  - src/Http/Controllers/**
---

# Controller Conventions

- `final class XController` with constructor-injected handlers; return `Phlix\Hub\Http\Response`.
- JSON envelope: `(new Response())->status(N)->json(['error' => ..., 'code' => ..., 'message' => ...])`, or `(new Response())->error($status, $code, $message, $extra = [])` (emits `{error, code}` in that key order, then any `$extra` keys such as `message`).
- Every emitted `code` must be registered in the vendored contracts fixture `tests/fixtures/contracts/error-codes.json` (pinned by `tests/Unit/Contracts/ErrorCodesContractTest.php`); keep codes as inline, scan-visible literals — no indirection helpers. When flipping a legacy code, use dual placement: dotted code on `code`, legacy literal byte-identical in `error` (e.g. `AlexaSignatureMiddleware::REJECTION_CODE_MAP`, `SubdomainController`'s `error(404, 'server.not_found', 'SERVER_NOT_FOUND', ['message' => …])`). Pin each frame in `tests/Unit/Http/Controllers/ErrorPromotionFramesTest.php`.
- Auth gate first: `$userId = $request->userId ?? ''; if ($userId === '') return 401 'auth.required';`
- Ownership: 404 `server.not_found` then 403 `server.not_owned` (see `ServerManageController`).
- Admin routes are gated by `AdminMiddleware` in the route chain (`[AuthMiddleware, AdminMiddleware]`) — 401 unauthenticated / 403 non-admin; `AdminMiddleware::checkAccess($request)` returns the deny status.
- Third-party surfaces use their own gate, never `AuthMiddleware`: `/oauth/userinfo` → `OAuthResourceMiddleware` (scoped Bearer, RFC 6750 `WWW-Authenticate` on 401/403), `/alexa/skill` → `AlexaSignatureMiddleware`, `/mcp` → the PAT check inside `McpController`. `POST /oauth/token` is registered **outside** the `/oauth` auth group — a client exchanging a code has no hub session and would be 302'd to `/app/login`.
- Map handler `InvalidArgumentException` codes (400/403/404/409) to responses (`LibraryShareController`).
- Path params arrive as `array $params` (`$params['id']`), wired by `src/Http/Router.php`.
