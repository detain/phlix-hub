---
paths:
  - src/Http/Controllers/**
---

# Controller Conventions

- `final class XController` with constructor-injected handlers; return `Phlix\Hub\Http\Response`.
- JSON envelope: `(new Response())->status(N)->json(['error' => ..., 'code' => ..., 'message' => ...])`.
- Auth gate first: `$userId = $request->userId ?? ''; if ($userId === '') return 401 'auth.required';`
- Ownership: 404 `server.not_found` then 403 `server.not_owned` (see `ServerManageController`).
- Admin routes are gated by `AdminMiddleware` in the route chain (`[AuthMiddleware, AdminMiddleware]`) — 401 unauthenticated / 403 non-admin; `AdminMiddleware::checkAccess($request)` returns the deny status.
- Third-party surfaces use their own gate, never `AuthMiddleware`: `/oauth/userinfo` → `OAuthResourceMiddleware` (scoped Bearer, RFC 6750 `WWW-Authenticate` on 401/403), `/alexa/skill` → `AlexaSignatureMiddleware`, `/mcp` → the PAT check inside `McpController`. `POST /oauth/token` is registered **outside** the `/oauth` auth group — a client exchanging a code has no hub session and would be 302'd to `/app/login`.
- Map handler `InvalidArgumentException` codes (400/403/404/409) to responses (`LibraryShareController`).
- Path params arrive as `array $params` (`$params['id']`), wired by `src/Http/Router.php`.
