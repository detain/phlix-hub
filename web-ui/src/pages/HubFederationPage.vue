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
</script>

<template>
    <div>
        <HubPageHint
            hint-id="federation"
            :links="[
                { to: '/app/federation/shares', label: 'Incoming shares' },
                { to: '/app/admin/settings', label: 'Hub settings' },
            ]"
        >
            Federation links this hub to other Phlix hubs (peers, shared libraries, admin
            delegation). The whole subsystem is gated by <code>federation.enabled</code> in hub
            settings: while off, mutations are refused with <em>provider.not_configured</em>, peer
            traffic is dropped and outbound dials pause. Flipping it back on instantly re-admits
            traffic on links that survived the off-window and re-establishes links that dropped
            while off within ≤60s automatically — the reconnect chain parks at the ≤60s backoff cap
            during the off-window and re-checks on that cadence, so no restart or explicit trigger
            is needed. A hub-config save / peer relay toggle still dials immediately.
        </HubPageHint>
        <FederationPage />
    </div>
</template>
