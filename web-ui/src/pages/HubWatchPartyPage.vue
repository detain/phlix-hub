<script setup lang="ts">
/**
 * Hub-local "Watch Parties" page — [review hub__casualgamer M5] (M-HUB-08).
 *
 * The review's words: "Watch parties — the hub's marquee feature for an end
 * user — are invisible to the end user… I looked everywhere in the portal for
 * my friend's party: no join screen, no room-code field, no link handler, no
 * copy." Every one of those capabilities already exists on the hub's :8804
 * relay (the closed canonical `syncplay_*` catalog, docs/websockets.md); the
 * portal just never surfaced one frame of it. This page is that surface —
 * built strictly on the documented wire: no new endpoints, no new frame
 * types, no invented capability.
 *
 * What the wire genuinely provides (and what is therefore SHIPPED here):
 *  - mint a short-lived, server-scoped relay token
 *    (`POST /api/v1/me/servers/{id}/relay-token`, owner-gated);
 *  - dial `ws(s)://<hub>:8804/syncplay/{server_id}` carrying that token in the
 *    `bearer,<token>` subprotocol — the ONLY carrier a browser may use (S237);
 *  - start / join-by-name / leave the account's own shadow rooms
 *    (`syncplay_group_create` / `_join` / `_leave`, first joiner hosts, join
 *    implies leave) and list the account's live rooms (`syncplay_group_list`).
 *
 * What the wire does NOT provide (stated in the UI, reported to the owner
 * reel — NOT papered over): the room key is scoped to `(server_id, owner)`, so
 * a party spans the DEVICES of the account that owns the server. A friend on
 * another account cannot join it: the mint gate answers `403 server.not_owned`
 * (ClientRelayTokenController::mint) before any socket exists. Cross-user
 * guest access is a hub capability still to come; this page says exactly that
 * in a visible paragraph and never renders a "shared with friends" success
 * state the relay cannot back.
 *
 * All frame parsing/building, link math and refusal sentences live in
 * `utils/watchPartyWire.ts` (pure, node-tested); this file only binds them.
 *
 * Package-boundary posture (same precedent as HubSharedWithMePage.vue and
 * HubSecuritySettingsPage.vue): the vendored @phlix/ui at the pin owns a
 * member-facing SyncPlay surface internally but exports none of it from the
 * package entry, so the hub page speaks the documented hub-relay dialect
 * directly instead of waiting on an upstream tag.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { ApiClient, ApiError, errMessage, useAuthStore } from '@phlix/ui';
import {
    normalizePartyCode,
    buildRelayUrl,
    relaySubprotocols,
    buildPartyLink,
    frameGroupCreate,
    frameGroupJoin,
    frameGroupList,
    frameGroupLeave,
    parsePartyFrame,
    partyErrorSentence,
    mintFailureSentence,
    sortMembersForDisplay,
    formatPlaybackPosition,
    formatAge,
} from '../utils/watchPartyWire';
import type { PartyState, PartySummary } from '../utils/watchPartyWire';

/** Route props — only the deep link `/app/party/:serverId/:code` supplies them. */
const props = defineProps<{ serverId?: string; code?: string }>();

const http = new ApiClient();
const auth = useAuthStore();

interface ServerRow {
    serverId: string;
    serverName: string;
    status: string;
    relayActive: boolean;
    /** Unix seconds (ServerInfoDto::toPayload) or null when never seen. */
    lastSeenAt: number | null;
}
interface ServersResponse {
    servers?: ServerRow[];
}

/** Every UI state is named; nothing is rendered from an implied one. */
type Phase =
    | 'loading-servers'
    | 'servers-failed'
    | 'no-servers'
    | 'setup'
    | 'minting'
    | 'connecting'
    | 'lobby'
    | 'in-party'
    | 'closed';

const phase = ref<Phase>('loading-servers');
const servers = ref<ServerRow[]>([]);
const selectedServerId = ref('');
const displayName = ref(auth.user?.username ?? '');
const partyName = ref('');
const joinCode = ref('');

/** Live relay socket — kept OUT of reactivity on purpose (a Ref<WebSocket>
 *  would hand Vue's proxy to browser event plumbing). */
let socket: WebSocket | null = null;

const yourId = ref<string | null>(null);
const party = ref<PartyState | null>(null);
const groups = ref<PartySummary[]>([]);
const activity = ref<string[]>([]);

/** The visible refusal — every dead state explains itself (review m4 law:
 *  disabled WITH REASON, shown on the face of the page, never a tooltip). */
const refusal = ref<string | null>(null);

/** Join/create intent parked until the socket is open (mint → dial → act). */
let pendingFrames: Record<string, unknown>[] = [];

const busy = computed(() => phase.value === 'minting' || phase.value === 'connecting');

/** Wall-clock seconds for the age formatters — display math, re-read per render. */
function now(): number {
    return Math.floor(Date.now() / 1000);
}
const inParty = computed(() => party.value !== null);
const isHost = computed(
    () => party.value !== null && yourId.value !== null && party.value.hostId === yourId.value,
);
const roster = computed(() => (party.value === null ? [] : sortMembersForDisplay(party.value.members)));
const partyLink = computed(() =>
    party.value === null || selectedServerId.value === ''
        ? ''
        : buildPartyLink(window.location.origin, selectedServerId.value, party.value.groupId),
);
const selectedServer = computed(() =>
    servers.value.find((s) => s.serverId === selectedServerId.value) ?? null,
);

/* --------------------------------------------------------------- boot ------ */

async function loadServers(): Promise<void> {
    phase.value = 'loading-servers';
    refusal.value = null;
    try {
        const data = await http.get<ServersResponse>('/api/v1/me/servers');
        servers.value = data.servers ?? [];
    } catch (e) {
        refusal.value = errMessage(e, 'The hub could not list your servers.');
        phase.value = 'servers-failed';
        return;
    }
    if (servers.value.length === 0) {
        phase.value = 'no-servers';
        return;
    }
    const wanted = normalizePartyCode(props.serverId);
    const match = wanted === null ? null : servers.value.find((s) => s.serverId === wanted);
    selectedServerId.value = match?.serverId ?? servers.value[0].serverId;
    const code = normalizePartyCode(props.code);
    if (wanted !== null && match === null) {
        // A deep link may name a server this account does not own/never had.
        // The mint endpoint is the authoritative gate; we pre-state the honest
        // refusal the moment the party cannot be dialed.
        refusal.value =
            'That party link points at a server your account has not registered with this hub. '
            + 'Hub parties live on the account that owns the media server.';
    }
    phase.value = 'setup';
    if (wanted !== null && match !== null && code !== null) {
        joinCode.value = code;
        void dial({ join: code });
    }
}

onMounted(loadServers);
onUnmounted(() => socket?.close());

/* ------------------------------------------------------------- dial law ---- */

function noteActivity(sentence: string): void {
    activity.value = [sentence, ...activity.value].slice(0, 30);
}

function handleFrame(raw: unknown): void {
    const frame = parsePartyFrame(raw);
    if (frame === null) {
        return; // garbage at the boundary: dropped, never rendered (Law 2)
    }
    switch (frame.kind) {
        case 'group_state':
            party.value = frame.state;
            if (frame.yourId !== null) {
                yourId.value = frame.yourId;
            }
            phase.value = 'in-party';
            refusal.value = null;
            break;
        case 'group_list':
            groups.value = frame.groups;
            if (phase.value === 'lobby') {
                refusal.value = null;
            }
            break;
        case 'info':
            if (frame.message !== '') {
                noteActivity(frame.message);
            }
            // The leave ack also lands here — the local view is cleared at the
            // moment leave is REQUESTED, so the ack is pure announcement.
            break;
        case 'host_elect': {
            const name =
                party.value?.members.find((m) => m.id === frame.electedId)?.name ?? frame.electedId;
            noteActivity(`The host left — ${name} is hosting now.`);
            break;
        }
        case 'error':
            // A refused act explains itself; the party shown (if any) stays
            // until the wire says otherwise via group_state — no local edits
            // pretending to know the room's truth.
            refusal.value = partyErrorSentence(frame.code, frame.message);
            break;
        case 'relay':
            // Documented fan-out (playback/chat/typing/time_pong): the party
            // view does not render it; acknowledged and ignored, no claims.
            break;
    }
}

function socketCloseReason(): string {
    return yourId.value !== null
        ? 'The relay closed the connection. The party still exists on the hub — rejoin with its name (relay tokens expire about an hour after minting).'
        : 'The relay closed the connection before the party act completed. Relay tokens expire about an hour after minting — try again.';
}

function dial(intent: { join?: string; create?: string } = {}): void {
    const serverId = selectedServerId.value;
    if (serverId === '') {
        refusal.value = 'Pick one of your servers first — a party is hosted on a single media server.';
        return;
    }
    pendingFrames =
        intent.join !== undefined
            ? [frameGroupJoin(intent.join, displayName.value.trim())]
            : intent.create !== undefined
              ? [frameGroupCreate(intent.create, displayName.value.trim())]
              : [frameGroupList()];

    phase.value = 'minting';
    refusal.value = null;
    void (async () => {
        let token: string;
        try {
            const minted = await http.post<{ token?: unknown }>('/api/v1/me/servers/' + serverId + '/relay-token');
            if (typeof minted.token !== 'string' || minted.token === '') {
                refusal.value = 'The hub minted an empty relay token — the party cannot be dialed.';
                phase.value = 'setup';
                return;
            }
            token = minted.token;
        } catch (e) {
            const code = e instanceof ApiError ? (e.body as { code?: unknown } | null)?.code : null;
            refusal.value = mintFailureSentence(
                typeof code === 'string' ? code : null,
                errMessage(e, 'The hub refused to mint a relay token for that server.'),
            );
            phase.value = 'setup';
            return;
        }

        phase.value = 'connecting';
        let ws: WebSocket;
        try {
            ws = new WebSocket(buildRelayUrl(undefined, serverId), relaySubprotocols(token));
        } catch {
            refusal.value = 'This browser could not even build the relay connection — the hub origin is unusable.';
            phase.value = 'setup';
            return;
        }
        ws.onopen = () => {
            for (const frame of pendingFrames) {
                ws.send(JSON.stringify(frame));
            }
            pendingFrames = [];
            phase.value = party.value === null ? 'lobby' : 'in-party';
        };
        ws.onmessage = (event: MessageEvent) => handleFrame(event.data);
        ws.onerror = () => {
            refusal.value =
                'The hub SyncPlay relay on port 8804 is unreachable from this browser. '
                + 'The relay is a separate listener from the hub website — see docs/websockets.md (§:8804) for opening it.';
        };
        ws.onclose = () => {
            socket = null;
            yourId.value = null;
            party.value = null;
            groups.value = [];
            pendingFrames = [];
            refusal.value = refusal.value ?? socketCloseReason();
            phase.value = 'closed';
        };
        socket = ws;
    })();
}

function startParty(): void {
    const name = normalizePartyCode(partyName.value);
    if (name === null) {
        refusal.value = 'Type a party name first — the name IS the join code on this relay.';
        return;
    }
    dial({ create: name });
}

function joinParty(code: string): void {
    const name = normalizePartyCode(code);
    if (name === null) {
        refusal.value = 'Type the exact party name — an empty name has no room on the relay.';
        return;
    }
    if (joinCode.value !== name) {
        joinCode.value = name;
    }
    dial({ join: name });
}

function leaveParty(): void {
    if (socket !== null && socket.readyState === WebSocket.OPEN) {
        socket.send(JSON.stringify(frameGroupLeave()));
    }
    // The wire acks a leave with syncplay_info; the local view clears at the
    // request. If no socket was open there was nothing on the relay to leave.
    party.value = null;
    yourId.value = null;
    if (socket !== null) {
        phase.value = 'lobby';
        // The rooms list is live state — refresh it rather than show a room
        // that may have just emptied out.
        socket.send(JSON.stringify(frameGroupList()));
    }
    noteActivity('You left the party.');
}

function disconnect(): void {
    socket?.close();
    socket = null;
    phase.value = 'setup';
    party.value = null;
    yourId.value = null;
    groups.value = [];
    activity.value = [];
    refusal.value = null;
}

/* ------------------------------------------------------------- copy link --- */

const copied = ref(false);
const copyUnavailable = ref(false);

async function copyLink(): Promise<void> {
    copied.value = false;
    try {
        await navigator.clipboard.writeText(partyLink.value);
        copied.value = true;
    } catch {
        // Honest failure: the link stays visible and selectable in its field.
        copyUnavailable.value = true;
    }
}

function selectLinkField(event: Event): void {
    (event.target as HTMLInputElement | null)?.select();
}
</script>

<template>
    <section class="wp" aria-labelledby="watch-party-heading">
        <header class="wp__head">
            <h1 id="watch-party-heading" class="wp__title">Watch Parties</h1>
            <p class="wp__subtitle">
                Sync one media server across devices through the hub's SyncPlay relay.
            </p>
        </header>

        <p class="wp__note">
            A party is hosted on ONE of your servers and spans the signed-in devices of
            YOUR account — the relay scopes every room to the account that owns the server,
            so a friend signed in elsewhere cannot join it yet (guest access is a hub
            capability still to come). The party name IS the join code: this hub mints no
            secret ticket, and the link below simply carries both.
        </p>

        <!-- Honest per-state refusals: visible, never tooltips. -->
        <div v-if="refusal" class="wp__refusal" role="alert">{{ refusal }}</div>

        <!-- Loading / failure / empty-server states with reasons -->
        <p v-if="phase === 'loading-servers'" class="wp__state">Loading your servers…</p>
        <template v-else-if="phase === 'servers-failed'">
            <p class="wp__state">Your servers could not be listed, so no party can be dialed.</p>
            <button type="button" class="wp__button" @click="loadServers">Retry</button>
        </template>
        <template v-else-if="phase === 'no-servers'">
            <p class="wp__state">
                A watch party needs one of your media servers to host it, and this account has
                none registered with the hub. Connect a server under
                <RouterLink to="/app/servers">My Servers</RouterLink> first.
            </p>
        </template>

        <!-- Server + identity setup -->
        <div v-else class="wp__card">
            <label class="wp__field">
                <span class="wp__label">Host server</span>
                <select v-model="selectedServerId" :disabled="busy || inParty">
                    <option v-for="s in servers" :key="s.serverId" :value="s.serverId">
                        {{ s.serverName }} — {{ s.relayActive ? 'relay online' : s.status }}
                    </option>
                </select>
                <span v-if="selectedServer && !selectedServer.relayActive" class="wp__hint">
                    This server's relay tunnel is not online right now (the hub last heard from it
                    {{ formatAge(selectedServer.lastSeenAt, now()) }}). The party forms between
                    your connected clients on the hub regardless; media ids still resolve against
                    that server on each device.
                </span>
            </label>
            <label class="wp__field">
                <span class="wp__label">Your name in the party</span>
                <input v-model="displayName" type="text" maxlength="80" placeholder="Host" :disabled="inParty" />
                <span class="wp__hint">
                    Display label only — the relay identifies members by hub-minted ids, not by
                    this name.
                </span>
            </label>
        </div>

        <!-- Lobby: start / join / list (socket not yet open → dialing acts) -->
        <div v-if="!inParty && phase !== 'loading-servers' && phase !== 'servers-failed' && phase !== 'no-servers'" class="wp__card">
            <h2 class="wp__card-title">Start a party</h2>
            <div class="wp__row">
                <input
                    v-model="partyName"
                    type="text"
                    maxlength="80"
                    placeholder="Party name (this is also the join code)"
                    :disabled="busy"
                    @keyup.enter="startParty"
                />
                <button type="button" class="wp__button" :disabled="busy" @click="startParty">
                    {{ busy ? 'Connecting…' : 'Start' }}
                </button>
            </div>

            <h2 class="wp__card-title">Join by party name</h2>
            <div class="wp__row">
                <input
                    v-model="joinCode"
                    type="text"
                    maxlength="80"
                    placeholder="Exact party name"
                    :disabled="busy"
                    @keyup.enter="joinParty(joinCode)"
                />
                <button type="button" class="wp__button" :disabled="busy" @click="joinParty(joinCode)">
                    {{ busy ? 'Connecting…' : 'Join' }}
                </button>
            </div>

            <template v-if="phase === 'lobby'">
                <h2 class="wp__card-title">Your live parties on this server</h2>
                <p v-if="groups.length === 0" class="wp__state">
                    None right now — a party only exists while at least one client keeps it open.
                </p>
                <ul v-else class="wp__list">
                    <li v-for="g in groups" :key="g.id" class="wp__list-row">
                        <span>{{ g.name }}</span>
                        <span class="wp__meta">
                            {{ g.memberCount }} member{{ g.memberCount === 1 ? '' : 's' }}{{ g.isPlaying ? ' · playing' : '' }}
                        </span>
                        <button type="button" class="wp__button wp__button--small" @click="joinParty(g.id)">Join</button>
                    </li>
                </ul>
            </template>
        </div>

        <!-- In-party view -->
        <div v-if="inParty && party" class="wp__card">
            <h2 class="wp__card-title">{{ party.groupName }}</h2>
            <p class="wp__state">
                {{ isHost ? 'You are hosting.' : 'Guest of the host.' }}
                You are member {{ yourId ?? '(id pending)' }}.
            </p>

            <dl class="wp__details">
                <div><dt>Playback</dt><dd>{{ party.playbackState }} @ {{ formatPlaybackPosition(party.playbackPositionMs) }}</dd></div>
                <div><dt>Current media id</dt><dd>{{ party.currentMediaId ?? 'nothing queued' }}</dd></div>
                <div><dt>Queue</dt><dd>{{ party.queueLength }} item{{ party.queueLength === 1 ? '' : 's' }}</dd></div>
                <div><dt>Started</dt><dd>{{ formatAge(party.createdAt, now()) }}</dd></div>
                <div><dt>Last activity</dt><dd>{{ formatAge(party.lastActivityAt, now()) }}</dd></div>
            </dl>

            <h3 class="wp__card-title">Members</h3>
            <ul class="wp__list">
                <li v-for="m in roster" :key="m.id" class="wp__list-row">
                    <span>{{ m.name }}</span>
                    <span v-if="m.isHost" class="wp__badge">host</span>
                    <span v-if="m.id === yourId" class="wp__badge wp__badge--you">you</span>
                </li>
            </ul>

            <h3 class="wp__card-title">Party link (same-account devices)</h3>
            <div class="wp__row">
                <input :value="partyLink" type="text" readonly @click="selectLinkField" />
                <button type="button" class="wp__button" :disabled="!isHost" @click="copyLink">
                    {{ copied ? 'Copied' : 'Copy' }}
                </button>
            </div>
            <p v-if="copyUnavailable" class="wp__hint">
                This browser blocked clipboard writes — the field above is selected; copy it manually.
            </p>
            <p v-else-if="!isHost" class="wp__hint">
                The party was started by another of your devices; the host holds the link.
            </p>

            <div class="wp__row">
                <button type="button" class="wp__button" @click="leaveParty">Leave party</button>
                <button type="button" class="wp__button wp__button--quiet" @click="disconnect">Disconnect</button>
            </div>

            <template v-if="activity.length > 0">
                <h3 class="wp__card-title">Activity</h3>
                <ul class="wp__list wp__list--activity">
                    <li v-for="(line, i) in activity" :key="i">{{ line }}</li>
                </ul>
            </template>
        </div>

        <p v-else-if="phase === 'closed'" class="wp__state">
            Disconnected from the relay.
            <button type="button" class="wp__button wp__button--small" @click="dial()">Reconnect</button>
        </p>
    </section>
</template>

<style scoped>
/* Layout + neutral borders only; colors inherit the @phlix/ui shell theme
   (same posture as HubSharedWithMePage.vue / HubPageHint.vue). */
.wp { display: block; }
.wp__title { margin: 0; font-size: 1.4rem; }
.wp__subtitle { margin: 0.15rem 0 0; opacity: 0.75; font-size: 0.9rem; }
.wp__note {
    margin: 0.6rem 0 1rem;
    padding: 0.6rem 0.85rem;
    border: 1px solid color-mix(in srgb, currentColor 22%, transparent);
    border-radius: 8px;
    font-size: 0.85rem;
    opacity: 0.9;
}
.wp__refusal {
    margin: 0 0 1rem;
    padding: 0.6rem 0.85rem;
    border: 1px solid color-mix(in srgb, #d64545 55%, transparent);
    border-radius: 8px;
    font-size: 0.9rem;
}
.wp__state { opacity: 0.8; font-size: 0.9rem; }
.wp__card {
    display: grid;
    gap: 0.6rem;
    margin: 0 0 1rem;
    padding: 0.9rem 1rem;
    border: 1px solid color-mix(in srgb, currentColor 18%, transparent);
    border-radius: 10px;
}
.wp__card-title { margin: 0.4rem 0 0; font-size: 1rem; }
.wp__field { display: grid; gap: 0.3rem; font-size: 0.9rem; }
.wp__label { font-weight: 600; }
.wp__hint { font-size: 0.8rem; opacity: 0.75; }
.wp__row { display: flex; gap: 0.5rem; align-items: center; }
.wp__row input, .wp__row select, .wp__field select, .wp__field input {
    flex: 1;
    padding: 0.45rem 0.6rem;
    border: 1px solid color-mix(in srgb, currentColor 25%, transparent);
    border-radius: 8px;
    background: transparent;
    color: inherit;
    font: inherit;
}
.wp__button {
    padding: 0.45rem 0.9rem;
    border: 1px solid color-mix(in srgb, currentColor 30%, transparent);
    border-radius: 8px;
    background: transparent;
    color: inherit;
    font: inherit;
    cursor: pointer;
}
.wp__button:disabled { opacity: 0.5; cursor: not-allowed; }
.wp__button--small { padding: 0.2rem 0.6rem; font-size: 0.8rem; }
.wp__button--quiet { opacity: 0.75; }
.wp__list { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.35rem; }
.wp__list-row { display: flex; gap: 0.5rem; align-items: baseline; font-size: 0.9rem; }
.wp__meta { opacity: 0.7; font-size: 0.8rem; }
.wp__badge {
    font-size: 0.7rem;
    padding: 0.05rem 0.4rem;
    border-radius: 999px;
    border: 1px solid color-mix(in srgb, currentColor 30%, transparent);
}
.wp__badge--you { opacity: 0.7; }
.wp__details { display: grid; gap: 0.3rem; margin: 0.3rem 0; font-size: 0.9rem; }
.wp__details div { display: flex; gap: 0.5rem; }
.wp__details dt { font-weight: 600; }
.wp__details dd { margin: 0; }
</style>
