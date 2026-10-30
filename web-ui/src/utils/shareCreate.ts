/**
 * Pure wire law for the hub-local "Share a library now" card — M-HUB-05.
 *
 * [review hub__casualgamer m2]: the Shares page could only list and revoke;
 * creating a share meant walking to the separate Invite Links page, and the
 * direct `POST /api/v1/me/shares` endpoint (instant share with an ALREADY
 * registered collaborator) had zero portal surface. The card that binds this
 * law lives in `components/ShareLibraryCard.vue`; this module owns the shapes
 * and sentences so they stay node-testable, same posture as
 * `watchPartyWire.ts`.
 *
 * Endpoints pinned against the hub router by `tests/share-create.test.ts`:
 *   - `GET  /api/v1/me/servers`                  (ServerListController)
 *   - `GET  /api/v1/me/libraries?server_id={id}` (LibraryController::listForServer)
 *   - `POST /api/v1/me/shares`                   (LibraryShareController::createShare)
 * Permission vocabulary is the server's (`LibraryShare::PERMISSION_READ` /
 * `PERMISSION_READWRITE`), not an invention of the form. Refusal sentences are
 * keyed by EXACTLY the codes `createShare` answers with — the test reddens on
 * drift instead of shipping a lie in the UI (zero-new-wire-codes law).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

/** One of the caller's own servers (`ServerInfoDto::toPayload` subset). */
export interface ShareServerRow {
    serverId: string;
    serverName: string;
    status: string;
}

/** One library of a server (`GET /api/v1/me/libraries` row). */
export interface ShareLibraryRow {
    id: string;
    name: string;
}

/** Drop non-object rows and rows without a usable id — garbage never renders. */
export function parseServers(raw: unknown): ShareServerRow[] {
    const rows = isRecord(raw) && Array.isArray(raw['servers']) ? raw['servers'] : [];
    const out: ShareServerRow[] = [];
    for (const value of rows) {
        if (!isRecord(value)) {
            continue;
        }
        const serverId = asString(value['serverId']);
        if (serverId === '') {
            continue;
        }
        out.push({
            serverId,
            serverName: asString(value['serverName'], serverId),
            status: asString(value['status']),
        });
    }
    return out;
}

/** Same boundary law for libraries: `{libraries: [{id, name}]}`, junk rows drop. */
export function parseLibraries(raw: unknown): ShareLibraryRow[] {
    const rows = isRecord(raw) && Array.isArray(raw['libraries']) ? raw['libraries'] : [];
    const out: ShareLibraryRow[] = [];
    for (const value of rows) {
        if (!isRecord(value)) {
            continue;
        }
        const id = asString(value['id']);
        if (id === '') {
            continue;
        }
        out.push({ id, name: asString(value['name'], id) });
    }
    return out;
}

/** The exact body `createShare` requires (library_name is the human row name). */
export function buildSharePayload(
    serverId: string,
    library: ShareLibraryRow,
    email: string,
    permission: string,
): Record<string, string> {
    return {
        collaborator_email: email.trim(),
        server_id: serverId,
        library_id: library.id,
        library_name: library.name,
        permission,
    };
}

/**
 * Curated sentences for the refusals `LibraryShareController::createShare`
 * can answer with (the memberError list in that method is the tripwire).
 * The collaborator-limit line repeats the server's own sentence verbatim
 * (`QuotaFrames::COLLABORATORS_TEXT`) so hub and wire never disagree.
 */
export const SHARE_REFUSAL_SENTENCES: Readonly<Record<string, string>> = Object.freeze({
    'user_not_found':
        'No Phlix account exists at that email yet. Invite links create the '
        + 'account and the share in one step — send one instead.',
    'share_exists': 'That library is already shared with that person.',
    'quota.exceeded': 'This server has reached its collaborator limit',
    'not_server_owner': 'You do not own that server, so you cannot share from it.',
});

/** Plain sentence for a share refusal — known code curated, else server's own. */
export function shareRefusalSentence(code: string | null, fallback: string): string {
    const curated = code === null ? undefined : SHARE_REFUSAL_SENTENCES[code];
    if (curated !== undefined) {
        return curated;
    }
    return fallback;
}

/** The code inside an ApiError-shaped rejection body, when one is present. */
export function refusalCodeOf(error: unknown): string | null {
    if (typeof error !== 'object' || error === null || !('body' in error)) {
        return null;
    }
    const body: unknown = (error as { body?: unknown }).body;
    if (!isRecord(body)) {
        return null;
    }
    const code = body['code'];
    return typeof code === 'string' && code !== '' ? code : null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function asString(value: unknown, fallback = ''): string {
    return typeof value === 'string' ? value : fallback;
}
