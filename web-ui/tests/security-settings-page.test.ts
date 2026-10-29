/**
 * Security-page self-service password law — [review hub__casualgamer M7]
 * (M-HUB-07).
 *
 * Two families of pins (same posture as shared-with-me-liveness.test.ts):
 *
 *  1. TRUTH TABLE on `src/utils/passwordForm.ts` (pure module, importable
 *     under node's native TS type stripping): the pre-flight complaints and
 *     their order, the length bounds agreeing with the server policy numbers,
 *     and the code→sentence map covering exactly the codes the endpoint can
 *     answer with (zero-new-wire-codes tripwire: a code outside the contracts
 *     vocab this page knows about reddens the vocabulary pin against
 *     UserPasswordController.php read as text).
 *  2. WIRE-OF-COMPONENT law pins reading .vue / main.ts / PHP / openapi
 *     sources as text: the route mounts the hub-local page, main.ts no longer
 *     imports the vendored webauthn-only page at all, the menu entry exists
 *     (a route nobody can navigate to is the same defect in a different coat),
 *     the page posts the real endpoint and speaks NO webauthn, carries the
 *     honest passkeys statement, the server registers the route in the
 *     member-auth group (never admin), and the SPA constant names mirror the
 *     PHP constants (cross-language drift tripwire).
 *
 * Mutation teeth: re-adding the vendored import, dropping the menu entry,
 * swapping the POST path, or moving the route to the admin group each reddens
 * a NAMED assertion below.
 *
 * Runtime: node's built-in test runner (`npm test`).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import {
    validatePasswordForm,
    passwordErrorSentence,
    PASSWORD_ERROR_SENTENCES,
    MIN_PASSWORD_LENGTH,
    MAX_PASSWORD_LENGTH,
} from '../src/utils/passwordForm.ts';
import type { PasswordForm } from '../src/utils/passwordForm.ts';

const PAGE_SOURCE = readFileSync(
    fileURLToPath(new URL('../src/pages/HubSecuritySettingsPage.vue', import.meta.url)),
    'utf8',
);
const MAIN_SOURCE = readFileSync(
    fileURLToPath(new URL('../src/main.ts', import.meta.url)),
    'utf8',
);
const CONTROLLER_SOURCE = readFileSync(
    fileURLToPath(new URL('../../src/Http/Controllers/UserPasswordController.php', import.meta.url)),
    'utf8',
);
const APPLICATION_SOURCE = readFileSync(
    fileURLToPath(new URL('../../src/Application.php', import.meta.url)),
    'utf8',
);
const AUTH_MANAGER_SOURCE = readFileSync(
    fileURLToPath(new URL('../../src/Auth/AuthManager.php', import.meta.url)),
    'utf8',
);
const OPENAPI_SOURCE = readFileSync(
    fileURLToPath(new URL('../../openapi.yaml', import.meta.url)),
    'utf8',
);

function form(overrides: Partial<PasswordForm> = {}): PasswordForm {
    return {
        currentPassword: 'current-secret',
        newPassword: 'brand-new-secret',
        confirmPassword: 'brand-new-secret',
        ...overrides,
    };
}

/**
 * Drop comments so the forbidden-identifier pins speak to CODE only — the
 * docblocks legitimately tell the history ("this route used to mount the
 * vendored SecuritySettingsPage") while the import that made it live must
 * never come back. Neither file embeds `//` or `/*` inside string literals.
 */
function stripComments(source: string): string {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '');
}

const MAIN_CODE = stripComments(MAIN_SOURCE);
const PAGE_CODE = stripComments(PAGE_SOURCE);

describe('validatePasswordForm — client pre-flight', () => {
    it('accepts a well-formed matching pair', () => {
        assert.equal(validatePasswordForm(form()), null);
    });

    it('complains about the current password FIRST', () => {
        // An empty proof must not hide behind a confirm mismatch: the
        // endpoint's own order is proof-first, the form mirrors it.
        assert.equal(
            validatePasswordForm(form({ currentPassword: '', newPassword: 'x', confirmPassword: 'y' })),
            'Enter your current password.',
        );
    });

    it('requires a non-empty new password', () => {
        assert.equal(
            validatePasswordForm(form({ newPassword: '', confirmPassword: '' })),
            'Enter a new password.',
        );
    });

    it('enforces the server minimum length', () => {
        const tooShort = 'a'.repeat(MIN_PASSWORD_LENGTH - 1);
        assert.equal(
            validatePasswordForm(form({ newPassword: tooShort, confirmPassword: tooShort })),
            `The new password must be at least ${MIN_PASSWORD_LENGTH} characters.`,
        );
    });

    it('enforces the server maximum length', () => {
        const tooLong = 'a'.repeat(MAX_PASSWORD_LENGTH + 1);
        assert.equal(
            validatePasswordForm(form({ newPassword: tooLong, confirmPassword: tooLong })),
            `The new password must be at most ${MAX_PASSWORD_LENGTH} characters.`,
        );
    });

    it('catches confirmation mismatch only after length passes', () => {
        assert.equal(
            validatePasswordForm(form({ confirmPassword: 'different-secret' })),
            'The new passwords do not match.',
        );
    });
});

describe('passwordErrorSentence — failure vocabulary', () => {
    it('maps every frame the endpoint answers with to owner-facing prose', () => {
        assert.equal(
            passwordErrorSentence('auth.invalid_credentials', 'Current password is incorrect', 'fb'),
            'That current password is not correct.',
        );
        assert.equal(
            passwordErrorSentence('rate_limited', 'Too Many Requests', 'fb'),
            'Too many recent attempts from this network. Wait a few minutes and try again.',
        );
        assert.equal(
            passwordErrorSentence('auth.required', 'Unauthorized', 'fb'),
            'Your session expired — sign in again before changing the password.',
        );
    });

    it('passes through the server sentence for unmapped codes (validation_failed carries registration wording)', () => {
        assert.equal(
            passwordErrorSentence('validation_failed', 'Password must be at least 8 characters', 'fb'),
            'Password must be at least 8 characters',
        );
    });

    it('falls back when neither code nor text is available', () => {
        assert.equal(passwordErrorSentence(null, null, 'offline shrug'), 'offline shrug');
        assert.equal(passwordErrorSentence(null, '', 'offline shrug'), 'offline shrug');
    });

    it('cross-language tripwire: the mapped codes are exactly literals this controller emits', () => {
        // auth.required is the defensive frame; the others ride errorFrame().
        // A code in the SPA map that the controller never emits (or the
        // reverse, for the errorFrame family) means the two drifted.
        const controllerCodes = [...CONTROLLER_SOURCE.matchAll(/errorFrame\(\d+, '([a-z_.]+)'/g)].map(
            (m) => m[1],
        );
        const mapped = new Set(Object.keys(PASSWORD_ERROR_SENTENCES));
        for (const code of new Set(controllerCodes)) {
            // validation_failed intentionally passes through server prose.
            if (code === 'validation_failed') {
                continue;
            }
            assert.ok(mapped.has(code), `controller emits ${code} but the SPA sentence map lacks it`);
        }
        const defensive = /'code'\s+=>\s+'auth\.required'/.test(CONTROLLER_SOURCE);
        assert.ok(defensive, 'controller lost its defensive auth.required frame');
        assert.ok(mapped.has('auth.required'), 'SPA map names a code the controller no longer emits');
    });
});

describe('length constants agree with the server policy', () => {
    it('pins MIN to the register/change floor', () => {
        assert.match(AUTH_MANAGER_SOURCE, /strlen\(\$password\) < 8\)/);
        assert.equal(MIN_PASSWORD_LENGTH, 8);
    });

    it('pins MAX to AuthManager::MAX_PASSWORD_LENGTH', () => {
        const phpMax = AUTH_MANAGER_SOURCE.match(/MAX_PASSWORD_LENGTH = (\d+)/);
        assert.ok(phpMax, 'PHP ceiling constant vanished');
        assert.equal(Number(phpMax[1]), MAX_PASSWORD_LENGTH);
    });
});

describe('wire-of-component — hub security page', () => {
    it('main.ts mounts the hub-local page at /app/settings/security', () => {
        assert.match(
            MAIN_SOURCE,
            /import HubSecuritySettingsPage from '\.\/pages\/HubSecuritySettingsPage\.vue';/,
        );
        assert.match(MAIN_SOURCE, /path: '\/app\/settings\/security'[\s\S]*?component: HubSecuritySettingsPage,/);
    });

    it('main.ts no longer imports the vendored webauthn-only SecuritySettingsPage', () => {
        // The cure: that page cannot work on the hub (no webauthn endpoints),
        // so referencing it in CODE at all is the defect — comments may tell
        // the history. Named assertion for teeth. \b keeps the substring
        // inside `HubSecuritySettingsPage` from false-tripping the pin.
        assert.ok(
            !/\bSecuritySettingsPage\b/.test(MAIN_CODE),
            'vendored SecuritySettingsPage reference is back — /app/settings/security must render the hub page',
        );
    });

    it('the Security menu entry exists and targets the route', () => {
        assert.match(
            MAIN_SOURCE,
            /\{ id: 'security', label: 'Security', to: '\/app\/settings\/security' \}/,
        );
    });

    it('the page posts the real endpoint and speaks no webauthn', () => {
        assert.match(PAGE_CODE, /http\.post<[^>]*>\('\/api\/v1\/me\/password'/);
        assert.ok(!PAGE_CODE.includes('webauthn'), 'hub security page code must not call webauthn endpoints');
    });

    it('the page states the passkeys truth instead of rendering a dead form', () => {
        assert.match(PAGE_SOURCE, /does not offer passkey sign-in yet/);
    });

    it('the page never submits when the pre-flight complains', () => {
        assert.match(PAGE_SOURCE, /validatePasswordForm\(\{[\s\S]*?\}\);[\s\S]*?complaint !== null[\s\S]*?return;/);
    });
});

describe('server registration law', () => {
    it('the route lives in the member-auth /me group, never the admin group', () => {
        const registrar = APPLICATION_SOURCE.match(
            /private function registerUserPasswordRoutes\(\): void\s*\{[\s\S]*?\n {4}\}/,
        );
        assert.ok(registrar, 'registerUserPasswordRoutes() vanished');
        assert.match(registrar[0], /\[\$authMiddleware\]/);
        assert.ok(!registrar[0].includes('adminMiddleware'), 'self password change must NOT be admin-gated');
        assert.match(registrar[0], /\$r->post\('\/password'/);
        assert.match(registrar[0], /group\('\/api\/v1\/me'/);
    });

    it('openapi carries the path with the AuthMiddleware stamp', () => {
        const block = OPENAPI_SOURCE.match(/"\/api\/v1\/me\/password":\n([\s\S]*?)\n {2}"/);
        assert.ok(block, 'openapi lost /api/v1/me/password');
        assert.match(block[1], /x-phlix-middleware: \[AuthMiddleware\]/);
        assert.match(block[1], /operationId: "post_api_v1_me_password"/);
    });
});
