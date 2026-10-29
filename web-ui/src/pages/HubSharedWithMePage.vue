<script setup lang="ts">
/**
 * Hub-local "Shared With Me" page — [review hub__casualgamer M3].
 *
 * Replaces the vendored `SharedWithMePage` on `/app/shared-with-me` (the ui
 * package is a remote tarball pin; the honest-state cure belongs to the hub's
 * own surface until an upstream ui tag ships it). Same data, same layout
 * language — plus the point of the rework: every share card states what the
 * HUB knows about the hosting server, and a dead Open button never ships
 * silently (disabled + visible reason, per the module law below).
 *
 * All display logic lives in `utils/shareLiveness.ts` (pure, node-tested);
 * this file only binds it to the DOM.
 */
import { ref, onMounted } from 'vue';
import { ApiClient, errMessage } from '@phlix/ui';
import type { HubIncomingShare } from '../utils/shareLiveness';
import {
    shareOpenBlockReason,
    livenessBadge,
    shareLiveness,
    formatLastSeen,
    safeHref,
} from '../utils/shareLiveness';

const http = new ApiClient();

interface SharesResponse {
    incoming?: HubIncomingShare[];
}

const shares = ref<HubIncomingShare[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

async function loadShares(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const data = await http.get<SharesResponse>('/api/v1/me/shares');
        // Sort by created_at descending (most recent first); null timestamps
        // sink to the bottom instead of poisoning the sort with NaN.
        shares.value = [...(data.incoming ?? [])].sort(
            (a, b) => (b.created_at ?? 0) - (a.created_at ?? 0),
        );
    } catch (e) {
        error.value = errMessage(e, 'Failed to load shared libraries.');
    } finally {
        loading.value = false;
    }
}

onMounted(loadShares);

/** The disabled-gate for the Open button: non-null reason ⇒ button is dead AND explains itself. */
function openBlock(share: HubIncomingShare): string | null {
    return shareOpenBlockReason(share);
}

function badge(share: HubIncomingShare): { tone: string; text: string } {
    return livenessBadge(share);
}

function isExpired(share: HubIncomingShare): boolean {
    return typeof share.expires_at === 'number' && share.expires_at * 1000 < Date.now();
}

function formatDate(unixSeconds: number | null): string {
    if (typeof unixSeconds !== 'number') return '—';
    return new Date(unixSeconds * 1000).toLocaleDateString();
}

function formatPermission(permission: string): string {
    return permission === 'readwrite' ? 'Read / Write' : 'Read only';
}

/**
 * One-line status sentence per card. Three honest registers: verified-live
 * (with the hub's own last-seen timestamp), verified-dead (why + last-seen),
 * unverified (neutral — explicitly not a claim in either direction).
 */
function statusLine(share: HubIncomingShare): string {
    const seen = formatLastSeen(share.server_last_seen_at);
    switch (shareLiveness(share)) {
        case 'reachable':
            return share.server_relay_active
                ? `Tunnelled to this hub right now (${seen}).`
                : `The hub sees this server online (${seen}).`;
        case 'unreachable':
            return `The hub cannot reach this server (${seen}).`;
        case 'unknown':
        default:
            return 'The hub has no fresh signal about this server — the link may work anyway.';
    }
}

function openServer(share: HubIncomingShare): void {
    if (openBlock(share) !== null) {
        return;
    }
    const safe = safeHref(share.access_urls[0]);
    if (!safe) {
        return;
    }
    window.open(safe, '_blank', 'noopener,noreferrer');
}
</script>

<template>
    <section class="swn" aria-labelledby="shared-with-me-heading">
        <header class="swn__head">
            <h1 id="shared-with-me-heading" class="swn__title">Shared With Me</h1>
            <p class="swn__subtitle">Libraries shared with you by other users.</p>
        </header>

        <p class="swn__note">
            Server status below is measured <strong>from the hub’s vantage</strong>: whether the
            hub can see the owner’s server right now, not the path from your device to it. A
            server the hub cannot reach is usually offline or behind NAT — try again later.
        </p>

        <p v-if="loading" class="swn__state">Loading shared libraries…</p>

        <div v-else-if="error" class="swn__state swn__state--error" role="alert">
            <p>Couldn’t load shared libraries: {{ error }}</p>
            <button type="button" class="swn__button" @click="loadShares">Retry</button>
        </div>

        <p v-else-if="shares.length === 0" class="swn__state">
            No shared libraries yet. Libraries other people share with you appear here.
        </p>

        <div v-else class="swn__grid">
            <article
                v-for="share in shares"
                :key="share.share_id"
                class="swn-card"
                :class="{ 'swn-card--expired': isExpired(share), 'swn-card--down': shareLiveness(share) === 'unreachable' }"
            >
                <div class="swn-card__header">
                    <h2 class="swn-card__library">{{ share.library_name }}</h2>
                    <div class="swn-card__badges">
                        <span class="swn-badge swn-badge--perm">{{ formatPermission(share.permission_level) }}</span>
                        <span class="swn-badge" :class="`swn-badge--${badge(share).tone}`">{{ badge(share).text }}</span>
                        <span v-if="isExpired(share)" class="swn-badge swn-badge--expired">Expired</span>
                    </div>
                </div>

                <dl class="swn-card__details">
                    <div><dt>Server</dt><dd>{{ share.server_name }}</dd></div>
                    <div><dt>Shared by</dt><dd>{{ share.owner_name }}</dd></div>
                    <div><dt>Received</dt><dd>{{ formatDate(share.created_at) }}</dd></div>
                    <div><dt>Expires</dt><dd>{{ share.expires_at === null ? 'Never' : formatDate(share.expires_at) }}</dd></div>
                </dl>

                <p class="swn-card__status" :class="`swn-card__status--${shareLiveness(share)}`">
                    {{ openBlock(share) ?? statusLine(share) }}
                </p>

                <footer class="swn-card__footer">
                    <button
                        type="button"
                        class="swn-button"
                        :disabled="openBlock(share) !== null"
                        :title="openBlock(share) ?? 'Open this server in a new tab'"
                        @click="openServer(share)"
                    >
                        Open server
                    </button>
                </footer>
            </article>
        </div>
    </section>
</template>

<style scoped>
/* Layout + neutral borders only; colors inherit the @phlix/ui shell theme
   (same posture as HubPageHint.vue). */
.swn { display: block; }
.swn__title { margin: 0; font-size: 1.4rem; }
.swn__subtitle { margin: 0.15rem 0 0; opacity: 0.75; font-size: 0.9rem; }
.swn__note {
    margin: 0.6rem 0 1rem;
    padding: 0.6rem 0.85rem;
    border: 1px solid color-mix(in srgb, currentColor 22%, transparent);
    border-radius: 8px;
    font-size: 0.85rem;
    opacity: 0.85;
}
.swn__state { opacity: 0.8; font-size: 0.9rem; }
.swn__state--error { display: grid; gap: 0.5rem; justify-items: start; }

.swn__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1rem;
}
.swn-card {
    display: grid;
    gap: 0.6rem;
    padding: 0.9rem 1rem;
    border: 1px solid color-mix(in srgb, currentColor 18%, transparent);
    border-radius: 10px;
}
.swn-card--expired, .swn-card--down { opacity: 0.88; }
.swn-card__header { display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem; flex-wrap: wrap; }
.swn-card__library { margin: 0; font-size: 1.05rem; }
.swn-card__badges { display: flex; gap: 0.35rem; flex-wrap: wrap; }
.swn-badge {
    font-size: 0.68rem;
    padding: 0.1rem 0.45rem;
    border-radius: 999px;
    border: 1px solid color-mix(in srgb, currentColor 30%, transparent);
}
.swn-badge--success { color: #2e9e5b; border-color: currentColor; }
.swn-badge--error { color: #cf4444; border-color: currentColor; }
.swn-badge--neutral, .swn-badge--perm, .swn-badge--expired { opacity: 0.8; }

.swn-card__details {
    margin: 0;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.25rem 1rem;
    font-size: 0.82rem;
}
.swn-card__details div { display: flex; gap: 0.4rem; }
.swn-card__details dt { opacity: 0.65; }
.swn-card__details dd { margin: 0; }

.swn-card__status { margin: 0; font-size: 0.8rem; opacity: 0.9; }
.swn-card__status--unreachable { color: #cf4444; }
.swn-card__status--reachable { color: #2e9e5b; }
.swn-card__status--unknown { opacity: 0.7; }

.swn-card__footer { display: flex; justify-content: flex-end; }
.swn-button {
    font: inherit;
    padding: 0.35rem 0.9rem;
    border-radius: 8px;
    border: 1px solid color-mix(in srgb, currentColor 35%, transparent);
    background: transparent;
    color: inherit;
    cursor: pointer;
}
.swn-button:disabled { cursor: not-allowed; opacity: 0.45; }
.swn__button { font: inherit; padding: 0.3rem 0.8rem; border-radius: 8px; border: 1px solid color-mix(in srgb, currentColor 35%, transparent); background: transparent; color: inherit; cursor: pointer; }
</style>
