/**
 * Self-service password change form law — Security page [review hub__casualgamer M7].
 *
 * M-HUB-07 gave hub account owners their first own-password path:
 * `POST /api/v1/me/password` (proof-first: the CURRENT secret is verified
 * before anything else, so a stolen token alone cannot lock the owner out).
 * This module is THE single client truth table for that form: the pre-flight
 * checks (so a hopeless submit never round-trips) and the code→sentence map
 * for every failure frame the endpoint can answer with — plus, for each
 * complaint, WHICH input it is about (HUB-51: the page marks only that input
 * `aria-invalid`; a form-level refusal marks none).
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

/** The three inputs of the form, by model key (the page's `ref` names). */
export type PasswordField = keyof PasswordForm;

/**
 * One pre-flight complaint: the sentence to announce AND the input it is
 * about, so the page can mark exactly that input `aria-invalid` and point it
 * at the announced sentence with `aria-describedby` (HUB-51).
 */
export interface PasswordComplaint {
    field: PasswordField;
    sentence: string;
}

/**
 * Client pre-flight: first complaint (sentence + offending input), or `null`
 * when the form may be submitted. Order matters — an empty current password
 * must not be hidden behind a confirm-mismatch message.
 */
export function checkPasswordForm(form: PasswordForm): PasswordComplaint | null {
    if (form.currentPassword === '') {
        return { field: 'currentPassword', sentence: 'Enter your current password.' };
    }
    if (form.newPassword === '') {
        return { field: 'newPassword', sentence: 'Enter a new password.' };
    }
    if (form.newPassword.length < MIN_PASSWORD_LENGTH) {
        return {
            field: 'newPassword',
            sentence: `The new password must be at least ${MIN_PASSWORD_LENGTH} characters.`,
        };
    }
    if (form.newPassword.length > MAX_PASSWORD_LENGTH) {
        return {
            field: 'newPassword',
            sentence: `The new password must be at most ${MAX_PASSWORD_LENGTH} characters.`,
        };
    }
    if (form.newPassword !== form.confirmPassword) {
        return { field: 'confirmPassword', sentence: 'The new passwords do not match.' };
    }
    return null;
}

/** Sentence-only view of {@link checkPasswordForm}. */
export function validatePasswordForm(form: PasswordForm): string | null {
    return checkPasswordForm(form)?.sentence ?? null;
}

/**
 * Which input a SERVER refusal is about, or `null` for a form-level refusal
 * (throttle, expired session, missing fields, network) — those mark no input
 * invalid, because no single input is wrong. A wrong proof is the current
 * password; a policy rejection (`validation_failed`) is the new one.
 */
export const PASSWORD_ERROR_FIELDS: Record<string, PasswordField> = {
    'auth.invalid_credentials': 'currentPassword',
    'validation_failed': 'newPassword',
};

export function passwordErrorField(code: string | null): PasswordField | null {
    if (code !== null && Object.prototype.hasOwnProperty.call(PASSWORD_ERROR_FIELDS, code)) {
        return PASSWORD_ERROR_FIELDS[code];
    }
    return null;
}

/**
 * The login limiter's default window (`RateLimitProfiles::LOGIN`, 5 / 900 s)
 * — failed password-change proofs spend that same shared budget, so a
 * throttled owner waits AT MOST this long. Pinned to the PHP default by a
 * cross-language test (HUB-19).
 */
export const LOGIN_LOCKOUT_WINDOW_SECONDS = 900;

/**
 * Leading words of every `RateLimitException::retryAfterMessage()` sentence
 * (`MESSAGE_PREFIX` on the PHP side, pinned by a cross-language test). A
 * `rate_limited` frame whose text starts with this carries the server's EXACT
 * wait ("… try again in 14 minutes.") and wins over the generic sentence
 * below; the bare `'Too Many Requests'` machine label does not.
 */
export const SERVER_WAIT_SENTENCE_PREFIX = 'Too many attempts — ';

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
    'rate_limited': `Too many recent attempts from this network. Wait up to ${LOGIN_LOCKOUT_WINDOW_SECONDS / 60} minutes and try again.`,
};

/**
 * Resolve a failure to its display sentence. A `rate_limited` frame that
 * carries the server's exact-wait sentence shows THAT (server-exact text
 * wins: it names the real remaining wait, and stays honest when an operator
 * tuned the window away from the default). Otherwise: registered-code map
 * first, then whatever human text the server sent (hub frames keep the
 * sentence in `message`, mirrored from `error` per the M2 envelope law),
 * then the caller's fallback. `code`/`serverText` arrive pre-extracted by
 * the page (it owns the ApiError shape); keeping them primitives is what
 * makes this pure.
 */
export function passwordErrorSentence(
    code: string | null,
    serverText: string | null,
    fallback: string,
): string {
    if (
        code === 'rate_limited' &&
        serverText !== null &&
        serverText.startsWith(SERVER_WAIT_SENTENCE_PREFIX)
    ) {
        return serverText;
    }
    if (code !== null && Object.prototype.hasOwnProperty.call(PASSWORD_ERROR_SENTENCES, code)) {
        return PASSWORD_ERROR_SENTENCES[code];
    }
    if (serverText !== null && serverText !== '') {
        return serverText;
    }
    return fallback;
}
