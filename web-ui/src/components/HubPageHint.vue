<script setup lang="ts">
/**
 * Hub-local contextual page hint (W5 / plan §9 PageHint pass).
 *
 * Upstream `PageHint` is on BOTH barrels at the vendored v0.99.28 pin — the
 * RUNTIME barrel (a `PageHint` export of `dist/phlix-ui.js`) AND the TYPE
 * entry (`dist/index.d.ts:30` names it; a typed `import { PageHint }
 * from '@phlix/ui'` now passes `vue-tsc` — re-executed at this pin). The
 * type-barrel absence that forced this file existed only at earlier pins;
 * the swap-and-retire of these wrappers is therefore UNBLOCKED behavior work,
 * registered as a follow-up at [tag-wave C2] and kept out of re-pin lanes.
 * Contract mirrors the ui pattern:
 * a dismissable contextual paragraph + at least one link, persisted
 * dismissal keyed by `hintId`.
 *
 * HUB-S9: `dismissible` (default true) opts a hint OUT of that contract. A
 * non-dismissible hint is a standing disclosure — copy that corrects the
 * page it sits on (e.g. the federation page's servers→hubs terminology band)
 * must not vanish with a click, or the uncorrected copy beneath it reads as
 * true again. Such a hint renders no dismiss button, never reads or writes
 * the dismissal key, and is exposed as a note rather than a dismissable aside.
 */
import { ref, watchEffect } from 'vue';

const props = withDefaults(
    defineProps<{
        /** Stable per-hint id for the localStorage dismissal key. */
        hintId: string;
        /** In-app router targets: { to, label } pairs. */
        links: Array<{ to: string; label: string }>;
        /** False = standing disclosure: always shown, no dismiss control. */
        dismissible?: boolean;
    }>(),
    { dismissible: true },
);

const STORAGE_PREFIX = 'phlix-hub-pagehint:';
const dismissed = ref(false);

watchEffect(() => {
    if (!props.dismissible) {
        // A standing disclosure ignores any stored dismissal (including one
        // left by an earlier dismissable build under the same id).
        dismissed.value = false;
        return;
    }
    try {
        dismissed.value = localStorage.getItem(STORAGE_PREFIX + props.hintId) === 'hidden';
    } catch {
        // Storage unavailable (private mode, hardened webviews): show, never throw.
        dismissed.value = false;
    }
});

function hide(): void {
    if (!props.dismissible) {
        return;
    }
    dismissed.value = true;
    try {
        localStorage.setItem(STORAGE_PREFIX + props.hintId, 'hidden');
    } catch {
        // Dismissal persistence is best-effort; the hint stays visible next visit.
    }
}
</script>

<template>
    <aside
        v-if="!dismissed"
        class="hub-page-hint"
        :class="{ 'hub-page-hint--standing': !dismissible }"
        :data-hint-id="hintId"
        :role="dismissible ? undefined : 'note'"
    >
        <div class="hub-page-hint__body">
            <slot />
            <nav v-if="links.length" class="hub-page-hint__links" aria-label="Related pages">
                <RouterLink v-for="link in links" :key="link.to" :to="link.to">{{ link.label }}</RouterLink>
            </nav>
        </div>
        <button
            v-if="dismissible"
            type="button"
            class="hub-page-hint__dismiss"
            aria-label="Dismiss this hint"
            @click="hide"
        >
            ×
        </button>
    </aside>
</template>

<style scoped>
/* Inherits the SPA's foreground color so the hint themes with whatever
   @phlix/ui shell wraps it; only layout + a neutral border are local. */
.hub-page-hint {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    margin: 0.75rem 1rem 0 1rem;
    padding: 0.6rem 0.85rem;
    border: 1px solid color-mix(in srgb, currentColor 22%, transparent);
    border-radius: 8px;
    font-size: 0.85rem;
    line-height: 1.45;
    opacity: 0.92;
}
.hub-page-hint__body {
    flex: 1 1 auto;
}
.hub-page-hint__links {
    display: flex;
    flex-wrap: wrap;
    gap: 0.9rem;
    margin-top: 0.35rem;
}
.hub-page-hint__dismiss {
    flex: 0 0 auto;
    border: 0;
    background: transparent;
    color: inherit;
    font-size: 1.1rem;
    line-height: 1;
    cursor: pointer;
    padding: 0 0.15rem;
}
</style>
