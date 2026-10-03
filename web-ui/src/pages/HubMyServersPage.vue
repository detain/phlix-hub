<script setup lang="ts">
/**
 * Hub route wrapper for @phlix/ui's MyServersPage + W5 page hint.
 *
 * The hint documents the settings that govern this page's surface
 * (`server.max_servers_per_user` especially — a member suddenly refused at
 * claim time needs to know a quota exists and who controls it).
 */
import { MyServersPage } from '@phlix/ui';
import HubPageHint from '../components/HubPageHint.vue';
</script>

<template>
    <div>
        <HubPageHint
            hint-id="my-servers"
            :links="[
                { to: '/app/invite-links', label: 'Invite links' },
                { to: '/app/shared-with-me', label: 'Shared with me' },
                { to: '/app/admin/settings', label: 'Hub settings' },
            ]"
        >
            Servers appear here after their owner redeems a claim code. An admin can cap how many
            servers each account may own (<code>server.max_servers_per_user</code>, hub settings —
            0 means unlimited); a refused claim with “Server quota reached” is that cap, not a bad
            code. Sharing a library with another account happens per server and is governed by
            <code>server.max_users_per_server</code>.
        </HubPageHint>
        <MyServersPage />
    </div>
</template>
