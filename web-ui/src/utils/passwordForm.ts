/**
 * Self-service password change form law — Security page [review hub__casualgamer M7].
 *
 * M-HUB-07 gave hub account owners their first own-password path:
 * `POST /api/v1/me/password` (proof-first: the CURRENT secret is verified
 * before anything else, so a stolen token alone cannot lock the owner out).
 * This module is THE single client truth table for that form: the pre-flight
 * checks (so a hopeless submit never round-trips) and the code→sentence map
 * for every failure frame the endpoint can answer with.
 *
 * Deliberately dependency-free (Law 3, same posture as shareLiveness.ts):
 * `npm test` (node --test) pins the table without a DOM and without importing
 * the @phlix/ui tarball bundle.
 *
 * The server messages for `validation_failed` ("Password must be at least 8
 * characters" / "at most 4096") are already human sentences and ride through
 * untranslated — the client minimum here is `MIN_PASSWORD_LENGTH`, identical
 * to the server policy so the two can never disagree about WHAT is accepted
 * (only about WHICH error text arrives first).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

/** Same ceiling as `AuthManager::MAX_PASSWORD_LENGTH` on the wire. */
export const MIN_PASSWORD_LENGTH = 8;
export const MAX_PASSWORD_LENGTH = 4096;

export interface PasswordForm {
    currentPassword: string;
    newPassword: string;
    confirmPassword: string;
}

/**
 * Client pre-flight: first complaint as a human sentence, or `null` when the
 * form may be submitted. Order matters — an empty current password must not
 * be hidden behind a confirm-mismatch message.
 */
export function validatePasswordForm(form: PasswordForm): string | null {
    if (form.currentPassword === '') {
        return 'Enter your current password.';
    }
    if (form.newPassword === '') {
        return 'Enter a new password.';
    }
    if (form.newPassword.length < MIN_PASSWORD_LENGTH) {
        return `The new password must be at least ${MIN_PASSWORD_LENGTH} characters.`;
    }
    if (form.newPassword.length > MAX_PASSWORD_LENGTH) {
        return `The new password must be at most ${MAX_PASSWORD_LENGTH} characters.`;
    }
    if (form.newPassword !== form.confirmPassword) {
        return 'The new passwords do not match.';
    }
    return null;
}

/**
 * The wire codes `POST /api/v1/me/password` can answer with, mapped to what
 * the OWNER should read. Every code is in the contracts vocab already used by
 * the login/signup siblings — this map adds no new machine surface, only
 * human text. `validation_failed` frames carry the server's own sentence (the
 * registration policy wording), so they are intentionally absent here and
 * resolve through the pass-through fallback.
 */
export const PASSWORD_ERROR_SENTENCES: Record<string, string> = {
    'auth.invalid_credentials': 'That current password is not correct.',
    'auth.missing_credentials': 'Both the current and the new password are required.',
    'auth.required': 'Your session expired — sign in again before changing the password.',
    'rate_limited': 'Too many recent attempts from this network. Wait a few minutes and try again.',
};

/**
 * Resolve a failure to its display sentence: registered-code map first, then
 * whatever human text the server sent (hub frames keep the sentence in
 * `error`, mirrored to `message` per the M2 envelope law), then the caller's
 * fallback. `code`/`serverText` arrive pre-extracted by the page (it owns the
 * ApiError shape); keeping them primitives is what makes this pure.
 */
export function passwordErrorSentence(
    code: string | null,
    serverText: string | null,
    fallback: string,
): string {
    if (code !== null && Object.prototype.hasOwnProperty.call(PASSWORD_ERROR_SENTENCES, code)) {
        return PASSWORD_ERROR_SENTENCES[code];
    }
    if (serverText !== null && serverText !== '') {
        return serverText;
    }
    return fallback;
}
