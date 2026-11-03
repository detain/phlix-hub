/**
 * Media-surface server-selection guard law ([review hub Major 39]).
 *
 * The vendored Search/Music/Books/Audiobooks/Photo pages fetch through
 * `useMediaApiBase()`: with a selected server they speak
 * `{apiBase}/api/v1/servers/{id}/proxy`, and with NONE they fall back to the
 * host's own base. On the hub that fallback is a guaranteed dead end — the
 * hub registers zero media endpoints on its own API (full inventory:
 * src/Application.php media-route grep = none) — so those pages rendered raw
 * `{error:'Not found'}` 404 JSON, or silently broke when a persisted server
 * id was later unlinked. main.ts answers every such navigation with a redirect
 * to /app/servers (the redirect IS the prompt; nav entries stay clickable).
 *
 * This module is the pure decision half of that law (importable, testable);
 * main.ts owns the router wiring and the live `useServerStore().hasCurrent`
 * read.
 */

/** Features whose hub routes exist only behind a selected media server. */
export const MEDIA_PATH_PREFIXES: readonly string[] = [
    '/app/search',
    '/app/music',
    '/app/books',
    '/app/audiobooks',
    '/app/photo',
];

/**
 * True when `path` is a guarded media surface: the exact feature root or any
 * child of it (prefix match requires the `/` boundary — `/app/photofoo` is
 * NOT a photo route).
 */
export function needsServerSelection(path: string): boolean {
    return MEDIA_PATH_PREFIXES.some((prefix) => path === prefix || path.startsWith(prefix + '/'));
}

/** Where an un-scoped media navigation lands. */
export const SERVER_DIRECTORY_PATH = '/app/servers';
