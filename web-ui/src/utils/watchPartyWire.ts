/**
 * Hub-local watch-party wire boundary — [review hub__casualgamer M5] (M-HUB-08).
 *
 * The review's marquee complaint: a hub watch party is INVISIBLE in the portal —
 * no join screen, no room-code field, no link handler, no copy. The capability
 * already exists on the wire (`:8804`, the closed canonical `syncplay_*`
 * catalog of docs/websockets.md); nothing surfaced it to a human. This module is
 * the parse/build boundary for that wire, so the page binds honest state and the
 * LAW lives in one testable file.
 *
 * Wire facts this boundary encodes (all coords at the landing tip):
 *
 *  - Dial: `ws(s)://<hub-host>:8804/syncplay/{server_id}` — path regex
 *    `SyncPlayRelayWorker::parseServerId`
 *    `~^/syncplay/([^/?#]+)/?(?:[?#].*)?$~`; port fixed by
 *    `SyncPlayRelayWorker::DEFAULT_PORT`, never the origin's port. The pinned
 *    `@phlix/ui` (v1.0.2) DOES export this dial law at its package entry —
 *    `buildHubRelayUrl(hubBaseUrl, serverId)` + `HUB_SYNC_PLAY_PORT = 8804`
 *    (`dist/index.d.ts:16`; runtime entry `dist/phlix-ui.js`) — so this
 *    module re-states the law by CHOICE, not necessity: the ui's socket API
 *    `openHubRelayConnection` is a store-bound `pending_command`/`play_media`
 *    consumer that ignores every `syncplay_group_*` frame (wrong shape for
 *    party ops — this page owns its raw socket), and only the `SyncPlayPage`
 *    COMPONENT is genuinely unexported at the pin. The duplication is pinned
 *    both ways by `tests/watch-party.test.ts` — against the PHP `DEFAULT_PORT`
 *    and against the ui entry's exports — so neither 8804 law producer can
 *    drift silently. (r2: the premise that the seam was unexported was false
 *    as first written — the export law above is the corrected record.)
 *  - Credential carrier: the `bearer,<token>` WebSocket subprotocol is the ONLY
 *    carrier a browser can use (S237; the relay echoes `Sec-WebSocket-Protocol:
 *    bearer` via `SyncPlayRelayWorker::BEARER_SUBPROTOCOL_ECHO`, so the 101
 *    completes). Query-string tokens are refused by design — never emitted here.
 *  - The token: a per-user, server-scoped RELAY token minted by
 *    `POST /api/v1/me/servers/{id}/relay-token` (owner-gated BEFORE the mint:
 *    `ClientRelayTokenController::mint` answers 404 `server.not_found` / 403
 *    `server.not_owned`), short-lived (1h default) — so it is re-minted per
 *    connect attempt, never cached, exactly like `hubRelay.ts`'s
 *    `tokenProvider` re-read law.
 *  - Vocabulary: the CLOSED canonical catalog (docs/websockets.md "Message
 *    catalog — CANONICAL `syncplay_*` dialect"). The hub strips client copies
 *    of `type`/`protocol_version`/`timestamp` (`canonicalFrame()`), so outbound
 *    frames here only need `type` + payload fields. Unknown `syncplay_*` names
 *    earn `syncplay_error` `UNKNOWN_MESSAGE` — the catalog is law, and this
 *    module ships no type the catalog does not already document.
 *  - THE ROOM KEY: `group_id` IS the friendly name (`canonicalGroupPayload()`
 *    sets `group_id` = `group_name` = the name the first member chose) and the
 *    hub AUTO-CREATES on join with the first joiner as host
 *    (`handleCanonicalJoin()`). There is no hub-minted secret code; the UI must
 *    say so plainly instead of performing secrecy theatre.
 *  - HONEST SCOPE: rooms live inside the socket's `(server_id, owner)` scope
 *    (`syncplay_group_list` answers only those; a foreign account cannot even
 *    mint a token for the server). Cross-user guest join is STRUCTURALLY absent
 *    from this wire — the UI states that as fact (owner-reel rider), and every
 *    refusal path here renders the real reason. No fake success has a
 *    representation in these types.
 *
 * Pure + dependency-free (Law 3), like `shareLiveness.ts` before it: tested by
 * `npm test` (node --test) with no DOM, so the whole truth table is pinned at
 * the module boundary, not behind Vue.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

/** The hub SyncPlay relay's own port (SyncPlayRelayWorker::DEFAULT_PORT). */
export const RELAY_PORT = 8804;

/** The subprotocol the relay accepts AND echoes (S237 bearer law). */
export const BEARER_SUBPROTOCOL = 'bearer';

/* ------------------------------------------------------------------ dial --- */

/**
 * Minimal shape of the deployment-injected `window.__PHLIX__` config object
 * emitted by SharedUiController::shell(). Only the two socket hints are read
 * here; null/absent means "operator did not pin a relay endpoint".
 */
interface PhlixShellSocketConfig {
    readonly wsUrl?: string | null;
    readonly wsPath?: string | null;
}

function shellSocketConfig(): PhlixShellSocketConfig | undefined {
    return (globalThis as { __PHLIX__?: PhlixShellSocketConfig }).__PHLIX__;
}

/**
 * Build the relay URL: `ws(s)://<host>:8804/syncplay/<server_id>`.
 *
 * The scheme follows the hub origin (`https:` → `wss:`), the host is the
 * origin's host, and the port is ALWAYS the relay's own `RELAY_PORT` — never
 * the origin's port. `hubBaseUrl` defaults to the app's own origin (the common
 * self-hosted layout where the hub serves the SPA and the relay from one host).
 *
 * The operator seam wins: when `__PHLIX__.wsUrl` is pinned (HUB_WS_URL,
 * SharedUiController::configuredSocketBaseUrl), it is dialed verbatim with
 * `__PHLIX__.wsPath ?? '/syncplay'` — the exact rule the pinned @phlix/ui
 * SyncPlay lane applies (phlix-ui.js buildHubRelayUrl), mirrored here so the
 * vendored store and this portal's own Watch Party dial can never diverge on
 * a TLS/443-only reverse-proxy deployment (where same-origin :8804 is dead).
 *
 * @throws Error when no seam is pinned AND `hubBaseUrl` (or the ambient
 *         location) is unparsable — a silent fallback URL would dial
 *         somewhere nobody chose.
 */
export function buildRelayUrl(hubBaseUrl: string | undefined, serverId: string): string {
    const injected = shellSocketConfig();
    const seam = typeof injected?.wsUrl === 'string' && injected.wsUrl !== '' ? injected : null;
    if (seam !== null) {
        const wsPath = seam.wsPath ?? '/syncplay';
        return `${seam.wsUrl}${wsPath}/${encodeURIComponent(serverId)}`;
    }
    const ambient = globalThis.location?.origin;
    const base = hubBaseUrl ?? ambient;
    if (typeof base !== 'string' || base === '') {
        throw new Error('buildRelayUrl: no hub origin to derive the relay scheme and host from');
    }
    const url = new URL(base);
    const scheme = url.protocol === 'https:' ? 'wss:' : 'ws:';
    return `${scheme}//${url.hostname}:${RELAY_PORT}/syncplay/${encodeURIComponent(serverId)}`;
}

/**
 * The `protocols` argument for `new WebSocket(url, protocols)`.
 *
 * Order matters to the hub's extractor, not to the browser: the hub reads
 * `Sec-WebSocket-Protocol: bearer, <token>` (ClientRelayWorker::
 * extractClientToken, S237) — first token is the echoed protocol name, second
 * the credential. The browser sends whatever it negotiated first; the pair
 * `['bearer', token]` produces exactly the documented header.
 */
export function relaySubprotocols(token: string): string[] {
    return [BEARER_SUBPROTOCOL, token];
}

/* -------------------------------------------------------------- payloads --- */

/** One member of `group.members` (hub shape `{id, name, is_host, joined_at}`). */
export interface PartyMember {
    /** Hub-minted client id (SPEC §9 identity; `your_id` matches it). */
    id: string;
    /** Display metadata only — claimed by the joining client, hub never trusts it. */
    name: string;
    isHost: boolean;
    /** Unix SECONDS (the group payload law), or null when the hub sent none. */
    joinedAt: number | null;
}

/** The `group` of `syncplay_group_state`, parsed at this boundary. */
export interface PartyState {
    /** == groupName: the friendly key IS the room key (wire fact, see header). */
    groupId: string;
    groupName: string;
    hostId: string | null;
    members: PartyMember[];
    currentMediaId: string | null;
    /** Wire milliseconds (SPEC §2) — the ONLY ms field on the group payload. */
    playbackPositionMs: number;
    playbackState: string;
    /** Queue length only; the party view does not re-implement the media browser. */
    queueLength: number;
    /** Unix seconds. */
    createdAt: number | null;
    /** Unix seconds. */
    lastActivityAt: number | null;
}

/** One row of the `syncplay_group_list` answer (server summary shape). */
export interface PartySummary {
    id: string;
    name: string;
    memberCount: number;
    currentMedia: string | null;
    isPlaying: boolean;
}

/** Everything the party surface consumes out of the relay, typed at the edge. */
export type PartyFrame =
    | { kind: 'group_state'; state: PartyState; yourId: string | null }
    | { kind: 'group_list'; groups: PartySummary[]; count: number }
    | { kind: 'info'; message: string }
    | { kind: 'host_elect'; electedId: string }
    | { kind: 'error'; code: string; message: string }
    /** A hub-verified relay fan-out this view does not render (playback_*,
     *  chat, typing, time_pong). Kept as an opaque type so a documented
     *  neighbour frame can never crash the page — but never CLAIMED as success. */
    | { kind: 'relay'; type: string };

/* ------------------------------------------- inbound parse (edge of trust) -- */

function asRecord(value: unknown): Record<string, unknown> | null {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : null;
}

function asString(value: unknown, fallback = ''): string {
    return typeof value === 'string' ? value : fallback;
}

function asNumberOrNull(value: unknown): number | null {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return Math.trunc(value);
    }
    if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) {
        return Math.trunc(Number(value));
    }
    return null;
}

function parseMember(raw: unknown): PartyMember | null {
    const row = asRecord(raw);
    if (row === null) {
        return null;
    }
    const id = asString(row.id);
    if (id === '') {
        return null;
    }
    return {
        id,
        name: asString(row.name, id),
        isHost: row.is_host === true,
        joinedAt: asNumberOrNull(row.joined_at),
    };
}

function parseGroupState(rawGroup: unknown): PartyState | null {
    const group = asRecord(rawGroup);
    if (group === null) {
        return null;
    }
    const groupId = asString(group.group_id) || asString(group.group_name);
    if (groupId === '') {
        return null;
    }
    const membersRaw = asRecord(group.members) ?? {};
    const members: PartyMember[] = [];
    for (const value of Object.values(membersRaw)) {
        const member = parseMember(value);
        if (member !== null) {
            members.push(member);
        }
    }
    const queue = Array.isArray(group.queue) ? group.queue : [];
    return {
        groupId,
        groupName: asString(group.group_name, groupId),
        hostId: asString(group.host_id) || null,
        members,
        currentMediaId: asString(group.current_media_id) || null,
        playbackPositionMs: asNumberOrNull(group.playback_position) ?? 0,
        playbackState: asString(group.playback_state, 'stopped'),
        queueLength: queue.length,
        createdAt: asNumberOrNull(group.created_at),
        lastActivityAt: asNumberOrNull(group.last_activity_at),
    };
}

/**
 * Coerce one raw relay text frame into a typed {@link PartyFrame}.
 *
 * The parse boundary (Law 2): JSON garbage, non-canonical bare-vocabulary
 * frames, and anything without a string `type` return `null` and are dropped by
 * the caller — a malformed frame must never render as a state change. Every
 * accepted arm carries only what docs/websockets.md already documents.
 */
export function parsePartyFrame(raw: unknown): PartyFrame | null {
    if (typeof raw === 'string') {
        try {
            raw = JSON.parse(raw);
        } catch {
            return null;
        }
    }
    const frame = asRecord(raw);
    if (frame === null) {
        return null;
    }
    const type = asString(frame.type);
    if (!type.startsWith('syncplay_')) {
        return null;
    }

    switch (type) {
        case 'syncplay_group_state': {
            const state = parseGroupState(frame.group);
            if (state === null) {
                return null;
            }
            const yourId = asString(frame.your_id) || null;
            return { kind: 'group_state', state, yourId };
        }
        case 'syncplay_group_list': {
            const rows = Array.isArray(frame.groups) ? frame.groups : [];
            const groups: PartySummary[] = [];
            for (const value of rows) {
                const row = asRecord(value);
                const id = row === null ? '' : asString(row.id);
                if (row === null || id === '') {
                    continue;
                }
                groups.push({
                    id,
                    name: asString(row.name, id),
                    memberCount: asNumberOrNull(row.member_count) ?? 0,
                    currentMedia: asString(row.current_media) || null,
                    isPlaying: row.is_playing === true,
                });
            }
            return { kind: 'group_list', groups, count: asNumberOrNull(frame.count) ?? groups.length };
        }
        case 'syncplay_info':
            return { kind: 'info', message: asString(frame.message) };
        case 'syncplay_host_elect': {
            const electedId = asString(frame.elected_id);
            if (electedId === '') {
                return null;
            }
            return { kind: 'host_elect', electedId };
        }
        case 'syncplay_error':
            return {
                kind: 'error',
                code: asString(frame.error_code, 'syncplay.unknown'),
                message: asString(frame.message),
            };
        default:
            // Documented relay fan-outs (playback_*, chat, typing, time_pong)
            // and anything future: acknowledged opaque, never mis-rendered.
            return { kind: 'relay', type };
    }
}

/* ------------------------------------------------------- outbound frames --- */

/**
 * `syncplay_group_create` — open (or re-enter) the owner-scoped shadow room
 * named by `group_name`; first member is host. `member_name` is display
 * metadata only (identity is the hub-minted `your_id`, SPEC §9).
 */
export function frameGroupCreate(groupName: string, memberName: string): Record<string, unknown> {
    return { type: 'syncplay_group_create', group_name: groupName, member_name: memberName };
}

/**
 * `syncplay_group_join` — same path keyed by `group_id` (the friendly name;
 * the hub auto-creates and makes the first joiner host — see header law).
 * Join implies leave. `password_hash` is deliberately never sent: the hub
 * accepts-and-ignores it INSIDE the owner scope, and rendering a password
 * field would advertise a protection that does not exist.
 */
export function frameGroupJoin(groupId: string, memberName: string): Record<string, unknown> {
    return { type: 'syncplay_group_join', group_id: groupId, member_name: memberName };
}

/** `syncplay_group_list` — this socket's own (server_id, owner) shadow rooms. */
export function frameGroupList(): Record<string, unknown> {
    return { type: 'syncplay_group_list' };
}

/** `syncplay_group_leave` — leave the current room (leaver gets the ack). */
export function frameGroupLeave(): Record<string, unknown> {
    return { type: 'syncplay_group_leave' };
}

/* ------------------------------------------------------------ party links -- */

/**
 * The SPA route this page mounts under: `/app/party/{serverId}/{code}`.
 *
 * The server id rides the path because the DIAL carries it
 * (`/syncplay/{server_id}`) and the relay re-checks ownership of exactly that
 * pairing — a link without it could not reconnect to the same room.
 */
export function buildPartyLink(origin: string, serverId: string, code: string): string {
    return `${origin}/app/party/${encodeURIComponent(serverId)}/${encodeURIComponent(code)}`;
}

/** Trim a route/link-supplied party code; blank is not a party (wire law). */
export function normalizePartyCode(raw: unknown): string | null {
    const value = asString(raw).trim();
    return value === '' ? null : value;
}

/* ------------------------------------------------------ honest sentences --- */

/*
 * HOUSE COPY POLICY for every member-visible sentence this module and
 * HubWatchPartyPage.vue render (review hub HUB-7/60/61/62; pinned by
 * `tests/watch-party.test.ts` "house copy policy"):
 *
 *  - Terms law (README glossary "watch party / group / room"): members read
 *    *watch party* (short form *party* inside compound labels like "party
 *    name"); the wire's *group* and the internals' *room* never reach them.
 *  - Register: no wire vocabulary (frame, catalog, protocol operation, token
 *    minting) and no ports, EXCEPT the port-8804 unreachable sentence, whose
 *    "relay on port 8804 is unreachable" clause the README troubleshooting
 *    heading quotes verbatim — and that clause is now said only when the
 *    page really dialed :8804 (see {@see relayUnreachableSentence}).
 *  - Punctuation: typographic apostrophe (U+2019) and ellipsis (U+2026); a
 *    spaced em dash joins a remedy clause; every curated sentence ends with a
 *    full stop; no ALL-CAPS emphasis in copy (docblocks may shout, members
 *    are never shouted at).
 *  - Honesty: a sentence states only what the hub's code can actually mean —
 *    each curated code below was re-read against the worker emitter that
 *    sends it (wire text itself is untouched; the client owns the copy).
 */

/** Member cap per watch party (SyncPlayRelayWorker::MAX_ROOM_MEMBERS, pinned by test). */
export const MAX_PARTY_MEMBERS = 50;

/** Queue cap per watch party (SyncPlayRelayWorker::CANONICAL_MAX_QUEUE_SIZE, pinned by test). */
export const MAX_PARTY_QUEUE = 1000;

/**
 * Mint-time failure sentences, keyed by EXACTLY the codes
 * `ClientRelayTokenController::mint` can answer with (zero-new-wire-codes law:
 * this map is pinned against the controller source by
 * `tests/watch-party.test.ts`, so a new server code reddens the tripwire
 * instead of silently degrading to a generic error).
 */
export const MINT_FAILURE_SENTENCES: Readonly<Record<string, string>> = Object.freeze({
    'auth.required': 'You are signed out — sign in to your hub account to open a watch party.',
    'server.not_found': 'That server is not registered with this hub. Connect it first under My Servers.',
    'server.not_owned':
        'This server belongs to another account. A watch party on this hub spans the devices of the '
        + 'account that owns the server — guest access for friends is a hub capability still to come.',
});

/**
 * Canonical relay refusal sentences for the ops THIS page can perform
 * (create/join/list/leave) plus the host/queue refusals a party member can
 * meet, keyed by the codes the hub actually answers with (docs/websockets.md
 * catalog; `handleCanonicalJoin` / `handleCanonicalLeave` /
 * `sendCanonicalError`). Each sentence was re-derived from its emitter:
 *
 *  - `syncplay.group_not_found` — the worker answers it ONLY for a join whose
 *    `group_id` is missing or blank; a non-empty unknown name AUTO-CREATES a
 *    new party (`handleCanonicalJoin`), so "no party has that name" was never
 *    a reachable diagnosis.
 *  - `syncplay.group_full` — the M-HUB-28 cap; previously uncurated, so a
 *    full party surfaced as raw "Group is full" plus its code.
 *  - `INVALID_NEW_HOST` — the worker means "no new-host member id was sent",
 *    not "that member is absent" (that is `MEMBER_NOT_FOUND`).
 *  - `HANDLER_ERROR` — the worker's catch-all "Internal relay error".
 *
 * Unknown codes fall through to the hub's own message — displayed verbatim
 * with its code, never dressed up.
 */
export const WIRE_ERROR_SENTENCES: Readonly<Record<string, string>> = Object.freeze({
    'syncplay.group_not_found': 'The hub did not receive a party name. Type the party name and try again.',
    'syncplay.group_full': `That watch party is full — it already has ${MAX_PARTY_MEMBERS} members.`,
    'syncplay.leave_failed': 'You were not in a watch party, so there was nothing to leave.',
    'syncplay.queue_limit_exceeded':
        `That queue is too long — a watch party holds at most ${MAX_PARTY_QUEUE.toLocaleString('en-US')} items.`,
    UNKNOWN_MESSAGE: 'The hub did not recognize that request. Reload the page and try again.',
    NOT_HOST: 'Only the party host can do that.',
    NOT_IN_GROUP: 'You are not in a watch party right now.',
    INVALID_NEW_HOST: 'No member was chosen to take over as host.',
    MEMBER_NOT_FOUND: 'That member is no longer in this watch party.',
    SAME_HOST: 'You are already the host.',
    HANDLER_ERROR: 'The hub hit an internal error handling that request. Try again — if it keeps happening, tell your hub admin.',
    'hub.protocol_unsupported': 'This hub does not support that request from this version of the page. Reload the page and try again.',
});

/** Close a hub-supplied fragment as a sentence (house policy: full stop). */
function asSentence(text: string): string {
    const trimmed = text.trim();
    return /[.!?…]$/.test(trimmed) ? trimmed : `${trimmed}.`;
}

/** Plain sentence for any wire refusal — known code → curated truth; else raw. */
export function partyErrorSentence(code: string, message: string): string {
    const curated = WIRE_ERROR_SENTENCES[code];
    if (curated !== undefined) {
        return curated;
    }
    const detail = message.trim() === '' ? 'the hub gave no reason' : message.trim();
    return asSentence(`The hub refused that request (${code}): ${detail}`);
}

/** Plain sentence for a mint refusal, whatever shape the ApiError carried. */
export function mintFailureSentence(code: string | null, fallback: string): string {
    if (code !== null && MINT_FAILURE_SENTENCES[code] !== undefined) {
        return MINT_FAILURE_SENTENCES[code];
    }
    return fallback;
}

/**
 * Activity-feed sentence for a `syncplay_info` message, or null when the page
 * already said it.
 *
 * The worker's two fixed prose shapes speak wire vocabulary
 * (`<name> joined the group` broadcast to the others on a join;
 * `<name> left the group` acked ONLY to the leaver) — the wire text stays
 * untouched (other clients branch on it); this page re-voices it under the
 * terms law. The leave ack returns null: `leaveParty()` notes the leave at
 * request time, so rendering the ack too would log the same event twice.
 * Any other info message passes through verbatim, closed as a sentence.
 */
export function activitySentence(message: string): string | null {
    const text = message.trim();
    if (text === '') {
        return null;
    }
    if (/ left the group$/.test(text)) {
        return null;
    }
    const joined = / joined the group$/.exec(text);
    if (joined !== null) {
        return `${text.slice(0, joined.index)} joined the watch party.`;
    }
    return asSentence(text);
}

/**
 * Close codes the `:8804` relay sends (SyncPlayRelayWorker::CLOSE_*, pinned
 * by test; docs/websockets.md "Rejection close codes").
 */
export const RELAY_CLOSE_UNAUTHORIZED = 4401;
export const RELAY_CLOSE_DO_NOT_RETRY = 1011;
export const RELAY_CLOSE_TRY_AGAIN_LATER = 1013;

/**
 * Sentence for a relay socket close, from the close code the browser saw.
 *
 * Replaces the old token-expiry explanation: the token is checked once, at
 * the handshake, and this page fetches a fresh one on every connect — so its
 * lifetime was never why an open party dropped. With the bearer subprotocol a
 * PRE-UPGRADE refusal (1011, pre-upgrade 4401/1013) never reaches this
 * function with its code: the browser reports it as `onerror` + 1006 BEFORE
 * `open` (docs "Carrier caveat"), and the page renders it through
 * {@see relayUnreachableSentence} ("unreachable or refused the connection"),
 * not the generic arm. The 4401/1011/1013 arms here cover only closes that
 * arrive after the upgrade.
 */
export function relayCloseSentence(code: number, wasInParty: boolean): string {
    switch (code) {
        case RELAY_CLOSE_UNAUTHORIZED:
            return 'The hub stopped accepting this device for that server — your sign-in may have ended, '
                + 'or the server is no longer on your account. Sign in again, then retry.';
        case RELAY_CLOSE_DO_NOT_RETRY:
            return 'The hub could not tell which server this watch party belongs to. Pick the server again and retry.';
        case RELAY_CLOSE_TRY_AGAIN_LATER:
            return 'The hub closed the connection — it was busy, or this device stopped answering for a while. '
                + 'Reconnect to carry on.';
        default:
            return wasInParty
                ? 'The connection to the hub closed. If anyone else is still in the watch party, rejoin it by name.'
                : 'The connection to the hub closed before the watch party opened. Try again.';
    }
}

/**
 * Sentence for a relay socket that never opened, naming the endpoint the page
 * REALLY dialed. The "relay on port 8804 is unreachable" clause is the
 * README troubleshooting heading's verbatim quote, so it is kept — but only
 * for a dial that actually went to :8804; behind the operator `__PHLIX__`
 * seam the dial goes elsewhere and saying "port 8804" would misdirect. The
 * copy covers a pre-upgrade REFUSAL too: with the bearer subprotocol the
 * browser surfaces that as `onerror` + 1006 before open, indistinguishable
 * from a dead relay, so the sentence must not claim the relay is down.
 */
export function relayUnreachableSentence(relayUrl: string): string {
    let where = 'relay';
    try {
        const url = new URL(relayUrl);
        where = url.port === String(RELAY_PORT)
            ? `relay on port ${RELAY_PORT}`
            : `relay at ${url.host}`;
    } catch {
        // Unparsable dial: keep the neutral noun rather than invent a place.
    }
    return `The hub’s watch-party ${where} is unreachable or refused the connection from this browser. `
        + 'If you run this hub, check that the relay is running, accepting connections and reachable from the internet; '
        + 'otherwise ask your hub admin — the page cannot open the connection on its own.';
}

/* ---------------------------------------------------------- display math --- */

/**
 * Roster order for rendering: host first, then join order (unix seconds),
 * then id for stability. Display law only — the wire members dict is unordered
 * by JSON contract, and the hub never ranks anyone but the host.
 */
export function sortMembersForDisplay(members: PartyMember[]): PartyMember[] {
    return [...members].sort((a, b) => {
        if (a.isHost !== b.isHost) {
            return a.isHost ? -1 : 1;
        }
        const at = a.joinedAt ?? Number.MAX_SAFE_INTEGER;
        const bt = b.joinedAt ?? Number.MAX_SAFE_INTEGER;
        if (at !== bt) {
            return at - bt;
        }
        return a.id < b.id ? -1 : a.id > b.id ? 1 : 0;
    });
}

/** `h:mm:ss` / `m:ss` for the ms playback anchor (never claims a live clock). */
export function formatPlaybackPosition(ms: number): string {
    const total = Math.max(0, Math.floor(ms / 1000));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = total % 60;
    const pad = (n: number) => String(n).padStart(2, '0');
    return hours > 0 ? `${hours}:${pad(minutes)}:${pad(seconds)}` : `${minutes}:${pad(seconds)}`;
}

/**
 * Age from unix SECONDS in the house time register shared with
 * `shareLiveness.ts`'s `formatLastSeen` ("5 min ago", "2 h ago",
 * "3 days ago" — the two modules used to say "5m"/"5 min" for the same
 * fact; the parity is pinned by test). Null-safe to "unknown".
 */
export function formatAge(seconds: number | null, nowSeconds: number): string {
    if (seconds === null) {
        return 'unknown';
    }
    const delta = Math.max(0, nowSeconds - seconds);
    if (delta < 60) {
        return 'just now';
    }
    const minutes = Math.floor(delta / 60);
    if (minutes < 60) {
        return `${minutes} min ago`;
    }
    const hours = Math.floor(minutes / 60);
    if (hours < 48) {
        return `${hours} h ago`;
    }
    return `${Math.floor(hours / 24)} days ago`;
}

/**
 * The "last heard" clause for a server whose relay tunnel is down. A server
 * the hub never heard from has no age — "last heard from it unknown" was the
 * old ungrammatical render of exactly that case.
 */
export function lastHeardClause(seconds: number | null, nowSeconds: number): string {
    return seconds === null
        ? 'the hub has no record of hearing from it'
        : `the hub last heard from it ${formatAge(seconds, nowSeconds)}`;
}

/**
 * Member-facing playback line from the group payload's `playback_state`
 * (`playing` | `paused` | `stopped`, SyncPlayRelayWorker::$roomStates) and
 * ms anchor — replaces the raw "playing @ 1:01" wire echo.
 */
export function playbackLine(state: string, positionMs: number): string {
    const at = formatPlaybackPosition(positionMs);
    switch (state) {
        case 'playing':
            return `Playing at ${at}`;
        case 'paused':
            return `Paused at ${at}`;
        case 'stopped':
            return 'Stopped';
        default: {
            const label = state.trim() === '' ? 'Unknown state' : state.charAt(0).toUpperCase() + state.slice(1);
            return `${label} at ${at}`;
        }
    }
}
