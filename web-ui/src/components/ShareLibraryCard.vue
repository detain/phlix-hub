<script setup lang="ts">
/**
 * Hub-local "Share a library now" card — M-HUB-05 [review hub__casualgamer m2].
 *
 * The review's words: the Shares page "could only list and revoke; creating a
 * share meant walking to the separate Invite Links page". `POST /api/v1/me/shares`
 * (instant share with an already-registered collaborator) existed API-only —
 * a first-time sharer saw an empty list and had to guess. This card is that
 * missing door, built strictly on endpoints the hub already serves (no new
 * routes, no new codes): pick one of YOUR servers, pick one of its libraries,
 * enter the collaborator's email, choose view-only or view-and-manage.
 * Someone WITHOUT an account is not a failure of this form — the copy routes
 * them to Invite Links, which is the correct self-service path there.
 *
 * All wire law (payload shapes, refusal sentences, parse boundary) lives in
 * `utils/shareCreate.ts` — pure, node-tested, cross-pinned against
 * LibraryShareController::createShare and the hub router. This file only binds.
 *
 * Terminology (the review's drift note): this page speaks "collaborator" for
 * the person and "share" for the grant, matching the HubPageHint copy on the
 * same screen; "invite link" names only the account-creating artifact.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
import { computed, onMounted, ref } from 'vue';
import { ApiClient, errMessage } from '@phlix/ui';
import {
    parseServers,
    parseLibraries,
    buildSharePayload,
    shareRefusalSentence,
    refusalCodeOf,
} from '../utils/shareCreate';
import type { ShareLibraryRow, ShareServerRow } from '../utils/shareCreate';

const http = new ApiClient();

/** Every UI state is named; nothing renders from an implied one. */
type ServersPhase = 'loading' | 'failed' | 'empty' | 'ready';
type LibrariesPhase = 'idle' | 'loading' | 'failed' | 'empty' | 'ready';
type SubmitPhase = 'idle' | 'submitting' | 'done';

const serversPhase = ref<ServersPhase>('loading');
const servers = ref<ShareServerRow[]>([]);
const selectedServerId = ref('');

const librariesPhase = ref<LibrariesPhase>('idle');
const libraries = ref<ShareLibraryRow[]>([]);
const selectedLibraryId = ref('');

const email = ref('');
const permission = ref<'read' | 'readwrite'>('read');
const submitPhase = ref<SubmitPhase>('idle');

/** Visible refusals with reasons — never tooltips, never a silent no-op. */
const refusal = ref<string | null>(null);
const sharedLine = ref<string | null>(null);

const busy = computed(() => submitPhase.value === 'submitting');
const selectedLibrary = computed<ShareLibraryRow | null>(
    () => libraries.value.find((l) => l.id === selectedLibraryId.value) ?? null,
);
const readyToShare = computed(() =>
    selectedServerId.value !== ''
    && selectedLibrary.value !== null
    && email.value.trim() !== ''
    && !busy.value,
);

/* ---------------------------------------------------------------- boot ----- */

async function loadServers(): Promise<void> {
    serversPhase.value = 'loading';
    refusal.value = null;
    try {
        servers.value = parseServers(await http.get<unknown>('/api/v1/me/servers'));
    } catch (e) {
        refusal.value = errMessage(e, 'The hub could not list your servers.');
        serversPhase.value = 'failed';
        return;
    }
    if (servers.value.length === 0) {
        serversPhase.value = 'empty';
        return;
    }
    selectedServerId.value = servers.value[0].serverId;
    serversPhase.value = 'ready';
    await loadLibraries();
}

async function loadLibraries(): Promise<void> {
    const serverId = selectedServerId.value;
    librariesPhase.value = 'loading';
    libraries.value = [];
    selectedLibraryId.value = '';
    if (serverId === '') {
        librariesPhase.value = 'idle';
        return;
    }
    try {
        libraries.value = parseLibraries(
            await http.get<unknown>('/api/v1/me/libraries?server_id=' + encodeURIComponent(serverId)),
        );
    } catch (e) {
        refusal.value = errMessage(e, 'The hub could not list that server\u2019s libraries.');
        librariesPhase.value = 'failed';
        return;
    }
    librariesPhase.value = libraries.value.length === 0 ? 'empty' : 'ready';
}

function onServerChange(): void {
    sharedLine.value = null;
    void loadLibraries();
}

onMounted(() => void loadServers());

/* -------------------------------------------------------------- submit ----- */

async function shareNow(): Promise<void> {
    const library = selectedLibrary.value;
    if (selectedServerId.value === '' || library === null || email.value.trim() === '') {
        // Unreachable through the disabled button; the guard keeps a scripted
        // submit from sending a body the server would 400 on.
        refusal.value = 'Pick a server and a library, and enter the email first.';
        return;
    }
    submitPhase.value = 'submitting';
    refusal.value = null;
    sharedLine.value = null;
    try {
        await http.post<unknown>(
            '/api/v1/me/shares',
            buildSharePayload(selectedServerId.value, library, email.value, permission.value),
        );
        sharedLine.value =
            '\u201c' + library.name + '\u201d is now shared with ' + email.value.trim()
            + ' (' + (permission.value === 'read' ? 'view only' : 'view and manage') + '). '
            + 'It appears in the list below after the next load.';
        email.value = '';
        submitPhase.value = 'done';
    } catch (e) {
        refusal.value = shareRefusalSentence(
            refusalCodeOf(e),
            errMessage(e, 'The hub refused that share.'),
        );
        submitPhase.value = 'idle';
    }
}
</script>

<template>
    <section class="slc" aria-labelledby="share-now-heading">
        <header class="slc__head">
            <h2 id="share-now-heading" class="slc__title">Share a library now</h2>
            <p class="slc__subtitle">
                Grant a Phlix account direct access to one library on one of your servers —
                instant, no link to forward.
            </p>
        </header>

        <div v-if="refusal" class="slc__refusal" role="alert">{{ refusal }}</div>
        <div v-if="sharedLine" class="slc__success" role="status">{{ sharedLine }}</div>

        <p v-if="serversPhase === 'loading'" class="slc__state">Loading your servers…</p>
        <template v-else-if="serversPhase === 'failed'">
            <p class="slc__state">Your servers could not be listed, so nothing can be shared.</p>
            <button type="button" class="slc__button" @click="loadServers">Retry</button>
        </template>
        <template v-else-if="serversPhase === 'empty'">
            <p class="slc__state">
                You have no servers registered with this hub yet. Connect one under
                <RouterLink to="/app/servers">My Servers</RouterLink> first.
            </p>
        </template>

        <div v-else class="slc__card">
            <label class="slc__field">
                <span class="slc__label">Server</span>
                <select v-model="selectedServerId" :disabled="busy" @change="onServerChange">
                    <option v-for="s in servers" :key="s.serverId" :value="s.serverId">
                        {{ s.serverName }}
                    </option>
                </select>
            </label>

            <p v-if="librariesPhase === 'loading'" class="slc__state">Loading its libraries…</p>
            <p v-else-if="librariesPhase === 'failed'" class="slc__state">
                That server’s libraries could not be listed — retry by switching servers,
                or check its relay tunnel under <RouterLink to="/app/servers">My Servers</RouterLink>.
            </p>
            <p v-else-if="librariesPhase === 'empty'" class="slc__state">
                The hub has not seen any libraries on this server yet — its relay tunnel must
                report them first (see <RouterLink to="/app/servers">My Servers</RouterLink>).
            </p>
            <label v-else-if="librariesPhase === 'ready'" class="slc__field">
                <span class="slc__label">Library</span>
                <select v-model="selectedLibraryId" :disabled="busy">
                    <option value="" disabled>Select a library…</option>
                    <option v-for="l in libraries" :key="l.id" :value="l.id">{{ l.name }}</option>
                </select>
            </label>

            <label class="slc__field">
                <span class="slc__label">Collaborator’s email</span>
                <input
                    v-model="email"
                    type="email"
                    autocomplete="off"
                    placeholder="they already have a Phlix account"
                    :disabled="busy"
                />
                <span class="slc__hint">
                    No account there yet? Create an
                    <RouterLink to="/app/invite-links">invite link</RouterLink> instead —
                    it opens the account and the share in one step.
                </span>
            </label>

            <label class="slc__field">
                <span class="slc__label">What can they do?</span>
                <select v-model="permission" :disabled="busy">
                    <option value="read">View only</option>
                    <option value="readwrite">View and manage</option>
                </select>
            </label>

            <div class="slc__row">
                <button
                    type="button"
                    class="slc__button"
                    :disabled="!readyToShare"
                    @click="shareNow"
                >
                    {{ busy ? 'Sharing…' : 'Share library' }}
                </button>
                <span v-if="!readyToShare && !busy" class="slc__hint">
                    Pick a library and enter an email to enable sharing.
                </span>
            </div>
        </div>
    </section>
</template>

<style scoped>
/* Layout + neutral borders only; colors inherit the @phlix/ui shell theme
   (same posture as HubWatchPartyPage.vue / HubPageHint.vue). */
.slc { display: block; margin: 0 0 1.25rem; }
.slc__title { margin: 0; font-size: 1.15rem; }
.slc__subtitle { margin: 0.15rem 0 0; opacity: 0.75; font-size: 0.9rem; }
.slc__refusal, .slc__success {
    margin: 0.6rem 0 0;
    padding: 0.6rem 0.85rem;
    border-radius: 8px;
    font-size: 0.9rem;
}
.slc__refusal { border: 1px solid color-mix(in srgb, #d64545 55%, transparent); }
.slc__success { border: 1px solid color-mix(in srgb, #2e7d32 55%, transparent); }
.slc__state { opacity: 0.8; font-size: 0.9rem; }
.slc__card {
    display: grid;
    gap: 0.6rem;
    margin: 0.75rem 0 0;
    padding: 0.9rem 1rem;
    border: 1px solid color-mix(in srgb, currentColor 18%, transparent);
    border-radius: 10px;
}
.slc__field { display: grid; gap: 0.3rem; font-size: 0.9rem; }
.slc__label { font-weight: 600; }
.slc__hint { font-size: 0.8rem; opacity: 0.75; }
.slc__row { display: flex; gap: 0.75rem; align-items: center; }
.slc__card select, .slc__card input {
    padding: 0.45rem 0.6rem;
    border: 1px solid color-mix(in srgb, currentColor 25%, transparent);
    border-radius: 8px;
    background: transparent;
    color: inherit;
    font: inherit;
}
.slc__button {
    padding: 0.45rem 0.9rem;
    border: 1px solid color-mix(in srgb, currentColor 30%, transparent);
    border-radius: 8px;
    background: transparent;
    color: inherit;
    font: inherit;
    cursor: pointer;
}
.slc__button:disabled { opacity: 0.5; cursor: not-allowed; }
</style>
