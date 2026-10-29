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
import { ref } from 'vue';
import { ApiClient, ApiError, errMessage } from '@phlix/ui';
import { validatePasswordForm, passwordErrorSentence } from '../utils/passwordForm';

const http = new ApiClient();

const currentPassword = ref('');
const newPassword = ref('');
const confirmPassword = ref('');
const submitting = ref(false);
/** null = no message; otherwise the sentence to render under the form. */
const message = ref<string | null>(null);
const failed = ref(false);

interface FailureFrame {
    code: string | null;
    text: string | null;
}

/** Pull `{code, human sentence}` out of a thrown error, whatever shape it is. */
function frameOf(e: unknown): FailureFrame {
    if (e instanceof ApiError) {
        const body = e.body as { code?: unknown; error?: unknown; message?: unknown } | null;
        const code = typeof body?.code === 'string' ? body.code : null;
        const text =
            typeof body?.error === 'string' && body.error !== ''
                ? body.error
                : typeof body?.message === 'string' && body.message !== ''
                  ? body.message
                  : e.message || null;
        return { code, text };
    }
    return { code: null, text: null };
}

async function submit(): Promise<void> {
    const complaint = validatePasswordForm({
        currentPassword: currentPassword.value,
        newPassword: newPassword.value,
        confirmPassword: confirmPassword.value,
    });
    if (complaint !== null) {
        failed.value = true;
        message.value = complaint;
        return;
    }

    submitting.value = true;
    message.value = null;
    failed.value = false;
    try {
        const data = await http.post<{ message?: string }>('/api/v1/me/password', {
            current_password: currentPassword.value,
            new_password: newPassword.value,
        });
        currentPassword.value = '';
        newPassword.value = '';
        confirmPassword.value = '';
        message.value = typeof data.message === 'string' ? data.message : 'Password updated successfully.';
    } catch (e) {
        failed.value = true;
        const { code, text } = frameOf(e);
        message.value = passwordErrorSentence(
            code,
            text,
            errMessage(e, 'The hub could not change the password. Try again.'),
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
                Your current password is checked first — nothing is written unless it is
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
                />
            </label>

            <p v-if="message" class="sec__status" :class="{ 'sec__status--error': failed }" role="status">
                {{ message }}
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
                This hub does not offer passkey sign-in yet — there is nothing to
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
