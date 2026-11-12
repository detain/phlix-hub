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
 *  3. A11Y ROLES (HUB-51): a refusal is announced assertively (role="alert"),
 *     a success politely (role="status") — never one shared region; the input
 *     a refusal is about (pre-flight field, or server code → field) carries
 *     aria-invalid + aria-describedby → the alert; form-level refusals mark
 *     no input.
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
    checkPasswordForm,
    validatePasswordForm,
    passwordErrorField,
    passwordErrorSentence,
    PASSWORD_ERROR_FIELDS,
    PASSWORD_ERROR_SENTENCES,
    MIN_PASSWORD_LENGTH,
    MAX_PASSWORD_LENGTH,
    LOGIN_LOCKOUT_WINDOW_SECONDS,
    SERVER_WAIT_SENTENCE_PREFIX,
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
const RATE_LIMIT_EXCEPTION_SOURCE = readFileSync(
    fileURLToPath(new URL('../../src/Auth/RateLimitException.php', import.meta.url)),
    'utf8',
);
const RATE_LIMIT_PROFILES_SOURCE = readFileSync(
    fileURLToPath(new URL('../../src/Common/RateLimit/RateLimitProfiles.php', import.meta.url)),
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
            'Too many recent attempts from this network. Wait up to 15 minutes and try again.',
        );
        assert.equal(
            passwordErrorSentence('auth.required', 'Unauthorized', 'fb'),
            'Your session expired — sign in again before changing the password.',
        );
    });

    it('rate_limited: the server exact-wait sentence wins over the generic copy (HUB-19)', () => {
        assert.equal(
            passwordErrorSentence('rate_limited', 'Too many attempts — try again in 14 minutes.', 'fb'),
            'Too many attempts — try again in 14 minutes.',
        );
        assert.equal(
            passwordErrorSentence('rate_limited', 'Too many attempts — you may try again now.', 'fb'),
            'Too many attempts — you may try again now.',
        );
        // The bare machine label (central Application::rateLimitResponse
        // frame, no `message`) is NOT a wait sentence: the honest ceiling
        // copy shows instead.
        for (const label of ['Too Many Requests', '', null]) {
            assert.equal(
                passwordErrorSentence('rate_limited', label, 'fb'),
                PASSWORD_ERROR_SENTENCES.rate_limited,
            );
        }
    });

    it('rate_limited: the generic copy states the real lockout ceiling, never "a few minutes"', () => {
        const copy = PASSWORD_ERROR_SENTENCES.rate_limited;
        assert.ok(copy.includes(`up to ${LOGIN_LOCKOUT_WINDOW_SECONDS / 60} minutes`), copy);
        assert.ok(!/a few minutes/.test(copy), 'vague wait copy is back');
    });

    it('cross-language tripwire: lockout window mirrors RateLimitProfiles LOGIN default', () => {
        const login = RATE_LIMIT_PROFILES_SOURCE.match(
            /self::LOGIN\s*=>\s*\['key' => 'login',\s*'max' => (\d+),\s*'window' => (\d+)\]/,
        );
        assert.ok(login, 'RateLimitProfiles LOGIN default row vanished');
        assert.equal(Number(login[2]), LOGIN_LOCKOUT_WINDOW_SECONDS);
        assert.equal(LOGIN_LOCKOUT_WINDOW_SECONDS % 60, 0, 'window must read as whole minutes');
    });

    it('cross-language tripwire: the wait-sentence prefix mirrors RateLimitException::MESSAGE_PREFIX', () => {
        const prefix = RATE_LIMIT_EXCEPTION_SOURCE.match(/const string MESSAGE_PREFIX = '([^']+)';/);
        assert.ok(prefix, 'RateLimitException::MESSAGE_PREFIX vanished');
        assert.equal(prefix[1], SERVER_WAIT_SENTENCE_PREFIX);
    });

    it('the password 429 frame carries the exact-wait message sentence', () => {
        const limited = CONTROLLER_SOURCE.match(
            /private static function rateLimited\(RateLimitException \$e\): Response\s*\{[\s\S]*?\n {4}\}/,
        );
        assert.ok(limited, 'UserPasswordController::rateLimited() vanished');
        assert.match(limited[0], /'message' => RateLimitException::retryAfterMessage\(\$retryAfter\)/);
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
        // [review hub Minor 50] the label is catalog-driven; the byte-pin that
        // EN still renders 'Security' lives in hub-menu-catalog.test.ts.
        assert.match(
            MAIN_SOURCE,
            /\{ id: 'security', label: t\('menu\.security'\), to: '\/app\/settings\/security' \}/,
        );
    });

    it('the page posts the real endpoint and speaks no webauthn', () => {
        assert.match(PAGE_CODE, /http\.post<[^>]*>\('\/api\/v1\/me\/password'/);
        assert.ok(!PAGE_CODE.includes('webauthn'), 'hub security page code must not call webauthn endpoints');
    });

    it('the page states the passkeys truth instead of rendering a dead form', () => {
        assert.match(PAGE_SOURCE, /doesn’t offer passkey sign-in yet/);
    });

    it('the page reads `message` before `error` (the 429 sentence lives in message)', () => {
        const frame = PAGE_CODE.match(/function frameOf\(e: unknown\): FailureFrame \{[\s\S]*?\n\}/);
        assert.ok(frame, 'frameOf() vanished');
        const msgAt = frame[0].indexOf('body?.message');
        const errAt = frame[0].indexOf('body?.error ===');
        assert.ok(msgAt >= 0 && errAt > msgAt, 'frameOf must prefer body.message over body.error');
    });

    it('the page never submits when the pre-flight complains', () => {
        assert.match(PAGE_SOURCE, /checkPasswordForm\(\{[\s\S]*?\}\);[\s\S]*?complaint !== null[\s\S]*?return;/);
    });
});

describe('[HUB-51] password-form a11y roles — field truth table', () => {
    const cases: Array<[string, Partial<PasswordForm>, string]> = [
        ['empty current', { currentPassword: '', newPassword: 'x', confirmPassword: 'y' }, 'currentPassword'],
        ['empty new', { newPassword: '', confirmPassword: '' }, 'newPassword'],
        ['too short', { newPassword: 'short', confirmPassword: 'short' }, 'newPassword'],
        [
            'too long',
            { newPassword: 'a'.repeat(MAX_PASSWORD_LENGTH + 1), confirmPassword: 'a'.repeat(MAX_PASSWORD_LENGTH + 1) },
            'newPassword',
        ],
        ['mismatch', { confirmPassword: 'different-secret' }, 'confirmPassword'],
    ];
    for (const [name, overrides, field] of cases) {
        it(`pre-flight "${name}" names the ${field} input`, () => {
            const complaint = checkPasswordForm(form(overrides));
            assert.ok(complaint, `${name} must complain`);
            assert.equal(complaint.field, field);
            // The sentence-only view is the same truth, never a second table.
            assert.equal(validatePasswordForm(form(overrides)), complaint.sentence);
        });
    }

    it('a well-formed pair names no input', () => {
        assert.equal(checkPasswordForm(form()), null);
    });

    it('server refusals mark the input they are about, form-level ones mark none', () => {
        assert.equal(passwordErrorField('auth.invalid_credentials'), 'currentPassword');
        assert.equal(passwordErrorField('validation_failed'), 'newPassword');
        for (const code of ['rate_limited', 'auth.required', 'auth.missing_credentials', 'toString', null]) {
            assert.equal(passwordErrorField(code), null, `${String(code)} is form-level`);
        }
    });

    it('cross-language tripwire: every field-mapped code is one the controller emits', () => {
        const controllerCodes = new Set(
            [...CONTROLLER_SOURCE.matchAll(/errorFrame\(\d+, '([a-z_.]+)'/g)].map((m) => m[1]),
        );
        for (const code of Object.keys(PASSWORD_ERROR_FIELDS)) {
            assert.ok(controllerCodes.has(code), `SPA marks an input for ${code}, which the controller never emits`);
        }
    });
});

describe('[HUB-51] password-form a11y roles — wire-of-component', () => {
    it('a refusal renders in a role="alert" region carrying the describedby id', () => {
        assert.match(
            PAGE_SOURCE,
            /<p v-if="refusal" :id="REFUSAL_ID" class="sec__status sec__status--error" role="alert">\s*\{\{ refusal \}\}/,
        );
    });

    it('success renders in its own role="status" region', () => {
        assert.match(PAGE_SOURCE, /<p v-if="success" class="sec__status" role="status">\s*\{\{ success \}\}/);
    });

    it('no single region announces both outcomes (the old shared role="status" split)', () => {
        const regions = [...PAGE_SOURCE.matchAll(/<p [^>]*role="(alert|status)"[^>]*>\s*\{\{ (\w+) \}\}/g)];
        assert.deepEqual(
            regions.map((m) => [m[1], m[2]]),
            [
                ['alert', 'refusal'],
                ['status', 'success'],
            ],
        );
        assert.ok(!/sec__status--error': failed/.test(PAGE_CODE), 'the role-agnostic failed toggle is back');
    });

    it('every input binds its invalid state', () => {
        for (const field of ['currentPassword', 'newPassword', 'confirmPassword']) {
            const input = PAGE_SOURCE.match(new RegExp(`<input\\s+v-model="${field}"[\\s\\S]*?/>`));
            assert.ok(input, `${field} input vanished`);
            assert.ok(
                input[0].includes(`v-bind="invalidAttrs('${field}')"`),
                `${field} input does not bind its own aria-invalid state`,
            );
        }
    });

    it('invalidAttrs marks aria-invalid and points aria-describedby at the alert', () => {
        const fn = PAGE_CODE.match(/function invalidAttrs\(field: PasswordField\)[\s\S]*?\n\}/);
        assert.ok(fn, 'invalidAttrs() vanished');
        assert.match(fn[0], /invalidField\.value === field/);
        assert.match(fn[0], /'aria-invalid': 'true', 'aria-describedby': REFUSAL_ID/);
    });

    it('both refusal paths name their input: pre-flight field and server code field', () => {
        assert.match(PAGE_CODE, /refuse\(complaint\.sentence, complaint\.field\)/);
        assert.match(PAGE_CODE, /passwordErrorField\(code\),\s*\);/);
    });

    it('editing a field withdraws the invalid mark', () => {
        assert.match(
            PAGE_CODE,
            /watch\(\[currentPassword, newPassword, confirmPassword\], \(\) => \{\s*invalidField\.value = null;/,
        );
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
