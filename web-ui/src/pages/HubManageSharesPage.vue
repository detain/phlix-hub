<script setup lang="ts">
/**
 * Hub route wrapper for @phlix/ui's ManageSharesPage + W5 page hint.
 *
 * Documents the collaborator quota whose 409 would otherwise look like a
 * generic share failure, and the reactivate-exemption semantics.
 *
 * M-HUB-05 [review hub__casualgamer m2]: the list-and-revoke page below had no
 * create door — `POST /api/v1/me/shares` was API-only and first-time sharers
 * had to detour through Invite Links. The ShareLibraryCard mounted here is
 * that door (hub-local: the vendored page is a package-pin boundary, and the
 * card needs no ui seams — it rides the documented endpoints directly).
 */
import { ManageSharesPage } from '@phlix/ui';
import HubPageHint from '../components/HubPageHint.vue';
import ShareLibraryCard from '../components/ShareLibraryCard.vue';
</script>

<template>
    <div>
        <HubPageHint
            hint-id="manage-shares"
            :links="[
                { to: '/app/invite-links', label: 'Invite links' },
                { to: '/app/servers', label: 'My servers' },
                { to: '/app/admin/settings', label: 'Hub settings' },
            ]"
        >
            Shares grant other Phlix accounts access to libraries on your servers; invite links
            are the self-service path into the same shares. An admin can cap how many distinct
            collaborators one server has (<code>server.max_users_per_server</code>, hub settings —
            0 means unlimited); a refused share with “collaborator limit” is that cap. People
            already sharing another library of the same server, or returning after a revoke,
            never count against it.
        </HubPageHint>
        <ShareLibraryCard />
        <ManageSharesPage />
    </div>
</template>
