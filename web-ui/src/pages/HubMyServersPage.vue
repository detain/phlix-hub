<script setup lang="ts">
/**
 * Hub route wrapper for @phlix/ui's MyServersPage + W5 page hint.
 *
 * The hint documents the settings that govern this page's surface
 * (`server.max_servers_per_user` especially — a member suddenly refused at
 * claim time needs to know a quota exists and who controls it).
 *
 * M-HUB-04 law: the hint must NOT quote a refusal string the vendored
 * @phlix/ui bundle renders — that copy lives in the pinned tag. As of the
 * current pin the bundle's code table DOES carry `quota.exceeded` (generic
 * "reached a quota limit for this account or server" wording, localized), so
 * a quota 409 is no longer labelled with the already-claimed copy. The law
 * still stands: describe the CAUSE here rather than echoing wire text, so the
 * hint stays true whatever the pinned ui wording is. A future lane MAY
 * localize the quota refusal hub-side (naming `server.max_servers_per_user`)
 * if the generic ui copy proves too vague for claim-time refusals.
 */
import { MyServersPage } from '@phlix/ui';
import HubPageHint from '../components/HubPageHint.vue';
// [review hub Minor 50] quick-link labels ride the hub-local catalog; the
// hint PROSE below stays English by the documented partial-adoption law.
import { useHubMessages } from '../i18n/hub-messages';

const { t } = useHubMessages();
</script>

<template>
    <div>
        <HubPageHint
            hint-id="my-servers"
            :links="[
                { to: '/app/invite-links', label: t('links.inviteLinks') },
                { to: '/app/shared-with-me', label: t('links.sharedWithMe') },
                { to: '/app/admin/settings', label: t('links.hubSettings') },
            ]"
        >
            Servers appear here after their owner redeems a claim code. An admin can cap how many
            servers each account may own (<code>server.max_servers_per_user</code>, hub settings —
            0 means unlimited); if a claim is refused even though the code was copied correctly,
            that cap is usually the reason, not a bad code. Sharing a library with another account
            happens per server and is governed by
            <code>server.max_users_per_server</code>.
        </HubPageHint>
        <MyServersPage />
    </div>
</template>
