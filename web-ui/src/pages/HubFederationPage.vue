<script setup lang="ts">
/**
 * Hub route wrapper for @phlix/ui's FederationPage + W5 page hint.
 *
 * Documents the master switch (`federation.enabled`) whose 409s would
 * otherwise look like arbitrary mutation failures, and its re-dial law:
 * re-enabling re-admits surviving links instantly, while a link dropped
 * while off needs a restart or an explicit re-dial trigger (docs-truth
 * pass @5a048a6 — the reconnect backoff is one-shot and only retries
 * while federation is on).
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
            traffic on links that survived the off-window; a link that dropped while off stays down
            until a process restart or a hub-config save / peer relay toggle re-dials it (the ≤60s
            reconnect backoff is one-shot and only retries while federation is on).
        </HubPageHint>
        <FederationPage />
    </div>
</template>
