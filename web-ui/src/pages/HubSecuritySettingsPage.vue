<script setup lang="ts">
/**
 * Hub-local Security settings page — [review hub__casualgamer M7] (M-HUB-07).
 *
 * The route `/app/settings/security` used to mount the VENDORED
 * `SecuritySettingsPage` from the pinned @phlix/ui tarball — a page that
 * speaks ONLY passkeys/webauthn (`/api/v1/me/webauthn/credentials`,
 * `/api/v1/auth/webauthn/register/*`). The hub provisions no webauthn
 * endpoints and the server-proxy allowlist carries none, so every control on
 * that page was guaranteed dead: the review's "security page: a 404" (and
 * the companion finding "password: no path"). Package-pin boundary — the
 * cure belongs here, on the hub's own surface, exactly like the
 * Shared-With-Me rework (M3) before it.
 *
 * What ships instead: the account owner's real security action — rotating
 * their own password through `POST /api/v1/me/password` (proof-first: the
 * current secret is verified before anything is written; a failed proof
 * drains the shared per-IP login budget). The passkey section states the
 * truth plainly instead of rendering a dead form.
 */
import { onBeforeUnmount, ref, watch } from 'vue';
import { ApiClient, ApiError, errMessage } from '@phlix/ui';
import {
    checkPasswordForm,
    passwordErrorField,
    passwordErrorSentence,
} from '../utils/passwordForm';
import type { PasswordField } from '../utils/passwordForm';

const http = new ApiClient();

const currentPassword = ref('');
const newPassword = ref('');
const confirmPassword = ref('');
const submitting = ref(false);
/*
 * Announcement split (HUB-51, house pattern of ShareLibraryCard.vue): a
 * refusal renders in a role="alert" region (assertive — the owner must hear
 * the change did NOT happen), the success line in a role="status" region
 * (polite). One shared role="status" used to carry both, so a wrong current
 * password was announced as calmly as a success.
 */
const refusal = ref<string | null>('');
const success = ref<string | null>('');
/**
 * The input the refusal is about (aria-invalid + aria-describedby → the
 * alert), or null for a form-level refusal that no single input caused.
 */
const invalidField = ref<PasswordField | null>(null);

/** id of the refusal sentence, the target of the invalid input's aria-describedby. */
const REFUSAL_ID = 'security-password-refusal';

// Editing the flagged input withdraws its invalid mark — a corrected value
// must not keep announcing "invalid". The refusal sentence stays until the
// next submit. (The post-success field reset never trips this: a success
// leaves no flagged input.)
watch([currentPassword, newPassword, confirmPassword], () => {
    invalidField.value = null;
});

function invalidAttrs(field: PasswordField): Record<string, string> {
    return invalidField.value === field
        ? { 'aria-invalid': 'true', 'aria-describedby': REFUSAL_ID }
        : {};
}

/*
 * Live-region announcements (HUB-S8). The two <p role> regions below are
 * PERSISTENT containers (always mounted, text bound inside), because a region
 * that mounts with its text already in it is not announced by some screen
 * readers. And an identical repeat sentence changes nothing in the DOM, so it
 * would not be re-announced: announce() clears the region, then sets the text
 * after a short timeout (longer than a microtask, so the clear and the set are
 * two separate DOM mutations the assistive tech observes).
 */
const ANNOUNCE_GAP_MS = 60;
let announceToken = 0;
const announceTimers = new Set<ReturnType<typeof setTimeout>>();

function announce(target: { value: string | null }, text: string): void {
    const token = ++announceToken;
    target.value = '';
    const timer = setTimeout(() => {
        announceTimers.delete(timer);
        // A newer announcement or a submit that cleared the regions wins.
        if (token === announceToken) {
            target.value = text;
        }
    }, ANNOUNCE_GAP_MS);
    announceTimers.add(timer);
}

/** Withdraw both messages now and cancel any announcement still pending. */
function clearAnnouncements(): void {
    announceToken++;
    refusal.value = '';
    success.value = '';
}

onBeforeUnmount(() => {
    announceToken++;
    for (const timer of announceTimers) {
        clearTimeout(timer);
    }
    announceTimers.clear();
});

function refuse(sentence: string, field: PasswordField | null): void {
    success.value = '';
    announce(refusal, sentence);
    invalidField.value = field;
}

interface FailureFrame {
    code: string | null;
    text: string | null;
}

/** Pull `{code, human sentence}` out of a thrown error, whatever shape it is. */
function frameOf(e: unknown): FailureFrame {
    if (e instanceof ApiError) {
        const body = e.body as { code?: unknown; error?: unknown; message?: unknown } | null;
        const code = typeof body?.code === 'string' ? body.code : null;
        // `message` first: on errorFrame() refusals it mirrors `error`
        // byte-for-byte, and on the 429 it is the exact-wait sentence while
        // `error` is only the 'Too Many Requests' label (HUB-19).
        const text =
            typeof body?.message === 'string' && body.message !== ''
                ? body.message
                : typeof body?.error === 'string' && body.error !== ''
                  ? body.error
                  : e.message || null;
        return { code, text };
    }
    return { code: null, text: null };
}

async function submit(): Promise<void> {
    const complaint = checkPasswordForm({
        currentPassword: currentPassword.value,
        newPassword: newPassword.value,
        confirmPassword: confirmPassword.value,
    });
    if (complaint !== null) {
        refuse(complaint.sentence, complaint.field);
        return;
    }

    submitting.value = true;
    clearAnnouncements();
    invalidField.value = null;
    try {
        const data = await http.post<{ message?: string }>('/api/v1/me/password', {
            current_password: currentPassword.value,
            new_password: newPassword.value,
        });
        currentPassword.value = '';
        newPassword.value = '';
        confirmPassword.value = '';
        // House copy law (API `message` carries no terminal period; SPA
        // success toasts do): render the toast sentence normalized so the
        // one sentence cannot read two ways depending on which branch fired.
        const apiMessage =
            typeof data.message === 'string' ? data.message : 'Password updated successfully';
        announce(success, apiMessage.endsWith('.') ? apiMessage : `${apiMessage}.`);
    } catch (e) {
        const { code, text } = frameOf(e);
        refuse(
            passwordErrorSentence(
                code,
                text,
                errMessage(e, 'The hub couldn’t change the password. Try again.'),
            ),
            passwordErrorField(code),
        );
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <section class="sec" aria-labelledby="security-heading">
        <header class="sec__head">
            <h1 id="security-heading" class="sec__title">Security</h1>
            <p class="sec__subtitle">Sign-in settings for your hub account.</p>
        </header>

        <form class="sec__card" autocomplete="on" @submit.prevent="submit">
            <h2 class="sec__card-title">Change password</h2>
            <p class="sec__card-note">
                Your current password is checked first — nothing is written unless it’s
                correct. Repeated wrong answers are throttled like failed logins. Existing
                sign-in sessions keep working until their tokens expire; a stolen device
                should be signed out by an admin.
            </p>

            <label class="sec__field">
                <span class="sec__label">Current password</span>
                <input
                    v-model="currentPassword"
                    type="password"
                    autocomplete="current-password"
                    required
                    v-bind="invalidAttrs('currentPassword')"
                />
            </label>
            <label class="sec__field">
                <span class="sec__label">New password</span>
                <input
                    v-model="newPassword"
                    type="password"
                    autocomplete="new-password"
                    minlength="8"
                    maxlength="4096"
                    required
                    v-bind="invalidAttrs('newPassword')"
                />
            </label>
            <label class="sec__field">
                <span class="sec__label">Confirm new password</span>
                <input
                    v-model="confirmPassword"
                    type="password"
                    autocomplete="new-password"
                    minlength="8"
                    maxlength="4096"
                    required
                    v-bind="invalidAttrs('confirmPassword')"
                />
            </label>

            <p :id="REFUSAL_ID" class="sec__status sec__status--error" role="alert">
                {{ refusal }}
            </p>
            <p class="sec__status" role="status">
                {{ success }}
            </p>

            <div class="sec__actions">
                <button type="submit" class="sec__button" :disabled="submitting">
                    {{ submitting ? 'Changing password…' : 'Change password' }}
                </button>
            </div>
        </form>

        <section class="sec__card sec__card--passkeys" aria-label="Passkeys">
            <h2 class="sec__card-title">Passkeys</h2>
            <p class="sec__card-note">
                This hub doesn’t offer passkey sign-in yet — there’s nothing to
                register or remove here. Passkeys are available on Phlix media
                servers, not on the hub account itself.
            </p>
        </section>
    </section>
</template>

<style scoped>
/* Layout + neutral borders only; colors inherit the @phlix/ui shell theme
   (same posture as HubSharedWithMePage.vue). */
.sec { display: block; max-width: 560px; }
.sec__title { margin: 0; font-size: 1.4rem; }
.sec__subtitle { margin: 0.15rem 0 0; opacity: 0.75; font-size: 0.9rem; }
.sec__card {
    display: grid;
    gap: 0.7rem;
    margin-top: 1.1rem;
    padding: 1rem 1.1rem;
    border: 1px solid color-mix(in srgb, currentColor 18%, transparent);
    border-radius: 10px;
}
.sec__card-title { margin: 0; font-size: 1.05rem; }
.sec__card-note { margin: 0; font-size: 0.83rem; opacity: 0.8; }
.sec__field { display: grid; gap: 0.25rem; font-size: 0.88rem; }
.sec__label { opacity: 0.75; }
.sec__field input {
    padding: 0.45rem 0.6rem;
    border: 1px solid color-mix(in srgb, currentColor 25%, transparent);
    border-radius: 6px;
    background: transparent;
    color: inherit;
    font: inherit;
}
.sec__status { margin: 0; font-size: 0.85rem; }
.sec__status--error { color: #cf4444; }
/* Persistent live regions stay mounted when empty; cancel the grid gap they would add. */
.sec__status:empty { margin-top: -0.7rem; }
.sec__actions { display: flex; justify-content: flex-end; }
.sec__button {
    padding: 0.45rem 0.9rem;
    border: 1px solid color-mix(in srgb, currentColor 30%, transparent);
    border-radius: 8px;
    background: transparent;
    color: inherit;
    font: inherit;
    cursor: pointer;
}
.sec__button:disabled { opacity: 0.6; cursor: default; }
</style>
