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
 *
 * HUB-S9 (hub-local half; review HUB-87, HUB-37):
 *  - Admin delegation is named in the hint's scope list but, like the
 *    explicit re-dial triggers, it has NO control on this page — it lives
 *    only on admin-gated API routes (the per-peer toggle plus the master-hub
 *    -only grant list). The existing API-only sentence is extended to name
 *    those routes, so the hint never promises a surface the page lacks.
 *  - servers→hubs band-aid: the wrapped @phlix/ui page still says "servers"
 *    where the hub means peer HUBS. The correction used to ride inside the
 *    dismissable hint, so one click restored the wrong noun as the only copy
 *    on screen. It is now a standing (non-dismissible) band directly above
 *    the wrapped page. Retire it when the vendored pin carries the corrected
 *    noun (upstream @phlix/ui item; the re-vendor rides HUB-S52) —
 *    tests/federation-page-hint.test.ts reddens on that pin to force the
 *    retirement.
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
            out immediately, but today they — and all of admin delegation (a peer’s delegation
            toggle, granting and revoking delegated admins) — exist only as admin-gated API
            endpoints:
            <code>PUT /api/v1/me/federation/hub-config</code>,
            <code>PUT /api/v1/me/federation/peers/:id/relay</code>,
            <code>PUT /api/v1/me/federation/peers/:id/admin-delegation</code>,
            <code>GET</code>/<code>POST /api/v1/me/federation/admin-delegations</code> (the
            delegation list and grants answer on the master hub only) and
            <code>DELETE /api/v1/me/federation/admin-delegations/:id</code>; this page exposes no
            control that invokes any of them.
        </HubPageHint>
        <HubPageHint hint-id="federation-terminology" :dismissible="false" :links="[]">
            On this hub a <strong>peer</strong> is another Phlix <strong>hub</strong>, never a
            Phlix server. Where the page below says “server” (“Connect with other Phlix
            servers”, “links whole servers together”, the add-peer address field), read “hub”:
            a peer’s address is the other hub’s URL. That wording is shared-page copy awaiting a
            fix in <code>@phlix/ui</code>; the hub vocabulary and the README glossary stand.
        </HubPageHint>
        <FederationPage />
    </div>
</template>
