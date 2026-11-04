<script setup lang="ts">
/**
 * Hub route wrapper for @phlix/ui's FederationPage + W5 page hint.
 *
 * Documents the master switch (`federation.enabled`) whose 409s would
 * otherwise look like arbitrary mutation failures, and its re-dial law:
 * re-enabling re-admits surviving links instantly AND re-establishes links
 * dropped while off within ≤60s automatically (the reconnect chain parks
 * at the ≤60s cap during the off-window and re-checks on that cadence —
 * BEHAVIOR lane, lifting the one-shot limitation documented @5a048a6).
 */
import { FederationPage } from '@phlix/ui';
import HubPageHint from '../components/HubPageHint.vue';
// [review hub Minor 50] quick-link labels ride the hub-local catalog; the
// hint PROSE below stays English by the documented partial-adoption law.
import { useHubMessages } from '../i18n/hub-messages';

const { t } = useHubMessages();
</script>

<template>
    <div>
        <HubPageHint
            hint-id="federation"
            :links="[
                { to: '/app/federation/shares', label: t('links.incomingShares') },
                { to: '/app/admin/settings', label: t('links.hubSettings') },
            ]"
        >
            Federation links this hub to other Phlix hubs (peers, shared libraries, admin
            delegation). The whole subsystem is gated by <code>federation.enabled</code> in hub
            settings: while off, mutations are refused with <em>provider.not_configured</em>, peer
            traffic is dropped and outbound dials pause. Flipping it back on instantly re-admits
            traffic on links that survived the off-window and re-establishes links that dropped
            while off within ≤60s automatically — the reconnect chain parks at the ≤60s backoff cap
            during the off-window and re-checks on that cadence, so no restart or explicit trigger
            is needed. The explicit re-dial triggers (a hub-config save, a peer relay toggle) dial
            out immediately, but today they exist only as admin-gated API endpoints —
            <code>PUT /api/v1/me/federation/hub-config</code> and
            <code>PUT /api/v1/me/federation/peers/:id/relay</code>; this page exposes no control
            that invokes them.
        </HubPageHint>
        <FederationPage />
    </div>
</template>
