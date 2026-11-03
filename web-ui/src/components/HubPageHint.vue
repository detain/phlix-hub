<script setup lang="ts">
/**
 * Hub-local contextual page hint (W5 / plan §9 PageHint pass).
 *
 * Upstream `PageHint` is on the RUNTIME barrel at the vendored v0.99.14 pin
 * (a `PageHint` export of `dist/phlix-ui.js`) but NOT the TYPE barrel —
 * `dist/index.d.ts` never names it (the component's declaration exists only
 * at `dist/components/ui/PageHint.vue.d.ts`), so a typed `import { PageHint
 * } from '@phlix/ui'` still fails `vue-tsc`. The hub's three shell pages
 * therefore keep the minimal hub-side equivalent until upstream declares the
 * export at the package entry — at which point these wrappers swap the
 * import and this component retires ([tag-wave C2] registers the follow-up).
 * Contract mirrors the ui pattern:
 * a dismissable contextual paragraph + at least one link, persisted
 * dismissal keyed by `hintId`.
 */
import { ref, watchEffect } from 'vue';

const props = defineProps<{
    /** Stable per-hint id for the localStorage dismissal key. */
    hintId: string;
    /** In-app router targets: { to, label } pairs. */
    links: Array<{ to: string; label: string }>;
}>();

const STORAGE_PREFIX = 'phlix-hub-pagehint:';
const dismissed = ref(false);

watchEffect(() => {
    try {
        dismissed.value = localStorage.getItem(STORAGE_PREFIX + props.hintId) === 'hidden';
    } catch {
        // Storage unavailable (private mode, hardened webviews): show, never throw.
        dismissed.value = false;
    }
});

function hide(): void {
    dismissed.value = true;
    try {
        localStorage.setItem(STORAGE_PREFIX + props.hintId, 'hidden');
    } catch {
        // Dismissal persistence is best-effort; the hint stays visible next visit.
    }
}
</script>

<template>
    <aside v-if="!dismissed" class="hub-page-hint" :data-hint-id="hintId">
        <div class="hub-page-hint__body">
            <slot />
            <nav v-if="links.length" class="hub-page-hint__links" aria-label="Related pages">
                <RouterLink v-for="link in links" :key="link.to" :to="link.to">{{ link.label }}</RouterLink>
            </nav>
        </div>
        <button
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
