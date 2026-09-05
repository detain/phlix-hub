---
paths:
  - src/Mcp/**
  - src/OAuth/**
  - src/Http/Middleware/OAuthResourceMiddleware.php
---

# MCP tokens & the OAuth authorization server

- **Two independent gates, in this order.** Identity + ownership first (`McpToolContext` re-derives the token's `user_id` and delegates to the production controllers, which enforce "the caller owns this server"), scope second. A scope can only ever SUBTRACT from the ownership gate — granting every scope never widens access to a server the user does not own.
- **One shared scope vocabulary.** `OAuthScopes` is `profile:read` plus every `McpScopes::all()` value re-exported verbatim (pinned by `OAuthScopesTest::testEveryMcpScopeIsGrantableOverOauth()`). Never invent a per-integration prefix such as `alexa:library:read` — an MCP client moving from a PAT to an authorization-code grant asks for the same strings.
- **Fail closed when parsing.** Unknown scope values are DROPPED at parse and mint time, so a typo becomes "no scope", not a scope that silently matches nothing later. Storage format is one space-delimited string (`parse()` / `toStorage()`).
- **PKCE is `S256` only** (`src/OAuth/Pkce.php`): `plain` is rejected, and an omitted `code_challenge_method` is rejected rather than defaulting to `plain` as RFC 7636 §4.4 would have it.
- PATs are stored as `hash('sha256', $token)` in `mcp_tokens.token_hash` (migration `044_mcp_tokens`); the plaintext is returned once, at mint. OAuth state lives in `045_oauth_authorization_server`.
- The MCP credential is accepted **only** from `Authorization: Bearer`, never a query string; answer a missing/invalid one with 401 + a `WWW-Authenticate` challenge before touching the rate limiter.
- Register OAuth clients through `php bin/phlix oauth:client:register` (and `oauth:client:list` / `oauth:client:disable`) — a hand-written `INSERT` bypasses `OAuthClient::create()` and can persist a row the lookup then refuses forever.
