<script setup lang="ts">
/**
 * Hub route wrapper for @phlix/ui's FederationPage + W5 page hint.
 *
 * Documents the master switch (`federation.enabled`) whose 409s would
 * otherwise look like arbitrary mutation failures, and its self-heal law.
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
            traffic is dropped and outbound dials pause — flipping it back on re-establishes the
            link automatically (≤60s reconnect backoff), no restart needed.
        </HubPageHint>
        <FederationPage />
    </div>
</template>
