<?php

declare(strict_types=1);

namespace Phlix\Hub\Http;

use support\Context;

/**
 * Thin, typed wrapper around {@see \support\Context} for per-request data
 * inside the hub daemon.
 *
 * Workerman 5 + Webman 2.2 run HTTP handlers inside coroutines once the
 * Swoole eventLoop driver is enabled (see `start.php` lines ~48-58,
 * mirrored from `phlix-server/start.php` in step 0.2a). Inside a
 * coroutine, ANY use of `static` / `global` / `$GLOBALS` to hold
 * request-scoped data is a correctness bug: the next request handled by
 * the same worker will see — or trample — the previous request's value.
 *
 * `support\Context` (which proxies to {@see \Workerman\Coroutine\Context})
 * is the supported per-request store. Behind the scenes it picks an
 * isolation driver from the active eventLoop (`Swoole`, `Swow`, or
 * `Fiber` fallback) and isolates values per coroutine — analogous to
 * AsyncLocalStorage in Node.js or `ContextVar` in Python.
 *
 * This wrapper mirrors {@see \Phlix\Server\Http\RequestContext} so the
 * server and hub share one canonical pattern. The audit run during step
 * 0.2c (`/tmp/0.2-hub-static-audit.txt`) found zero per-request offenders
 * in `src/`, so today this wrapper is exercised primarily by the
 * coroutine-isolation test suite — but having it in place means new code
 * (the upcoming hub admin settings store, the audit-log viewer in the
 * hub track) has a typo-safe, typed home for any per-request data it
 * needs to share between middleware and downstream services.
 *
 * @package Phlix\Hub\Http
 * @since   0.1.x (Step 0.2c)
 *
 * @see https://www.workerman.net/doc/webman/components/context.html
 * @see \Workerman\Coroutine\Context
 * @see \Phlix\Server\Http\RequestContext
 */
final class RequestContext
{
    /**
     * Namespaced context key for the authenticated user-id of the
     * current request. Namespaced (`phlix.hub.*`) to avoid collisions
     * with webman's own keys (`context.onDestroy`, etc.) AND with the
     * server-side keys when this codebase eventually shares a process
     * with phlix-server-style helpers.
     *
     * @var string
     */
    public const KEY_USER_ID = 'phlix.hub.userId';

    /**
     * Static-only helper — instantiation is intentionally forbidden.
     */
    private function __construct()
    {
    }

    /**
     * Store the authenticated user-id of the current request.
     *
     * Pass `null` to clear the value.
     *
     * @param string|null $userId Authenticated user-id, or `null` to clear.
     *
     * @return void
     *
     * @since 0.1.x (Step 0.2c)
     */
    public static function setUserId(?string $userId): void
    {
        Context::set(self::KEY_USER_ID, $userId);
    }

    /**
     * Read the authenticated user-id of the current request.
     *
     * Returns `null` when no user-id was published into the context
     * (anonymous request, or auth middleware not yet run).
     *
     * @return string|null Authenticated user-id, or `null` if unset.
     *
     * @since 0.1.x (Step 0.2c)
     */
    public static function getUserId(): ?string
    {
        /** @psalm-suppress MixedAssignment — Context::get returns mixed; narrowed via is_string */
        $value = Context::get(self::KEY_USER_ID);
        return is_string($value) ? $value : null;
    }

    /**
     * Returns true if a user-id has been published into the current
     * coroutine's context. Does NOT return true for `null` or empty
     * values.
     *
     * @return bool
     *
     * @since 0.1.x (Step 0.2c)
     */
    public static function hasUserId(): bool
    {
        /** @psalm-suppress MixedAssignment — Context::get returns mixed; narrowed via is_string */
        $value = Context::get(self::KEY_USER_ID);
        return is_string($value) && $value !== '';
    }

    /**
     * Drop the user-id from the current coroutine's context. Equivalent
     * to `setUserId(null)`, expressed positively for call sites that
     * want to assert "clear request state."
     *
     * Useful in long-running background coroutines (e.g. the relay
     * tunnels) that may handle several "logical" requests in sequence
     * and in test fixtures that need to reset shared state between
     * assertions.
     *
     * @return void
     *
     * @since 0.1.x (Step 0.2c)
     */
    public static function clearUserId(): void
    {
        Context::set(self::KEY_USER_ID, null);
    }
}
