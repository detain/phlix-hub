/**
 * Member-facing error-envelope contract tests — review hub__casualgamer M5.
 *
 * The portal renders failures with the pinned @phlix/ui client's
 * `extractError`, which returns `payload.error` first and `payload.message`
 * second. The hub cannot edit that pinned dependency (it is a remote tarball
 * install; the client belongs to the phlix-ui repo), so the hub cures the
 * defect at the wire: every member-facing envelope in LibraryShareController
 * and InviteLinkController carries the SAME human sentence in `error` and
 * `message` (machine code lives in `code`). These pins guard that contract
 * from the client side:
 *
 *  1. the pinned client still checks `error` before `message` — if phlix-ui
 *     ever flips precedence or reorders fields, this pin goes red and the
 *     hub re-probes (mirrored fields make an inversion harmless, but the
 *     law must be re-verified, HUB-04 style);
 *  2. the pinned client uses a `typeof === 'string'` test, so an EMPTY
 *     `error` string would render verbatim — the server must never ship one
 *     (enforced server-side by the controllers' memberError() normalization,
 *     pinned by tests/Unit/Http/Controllers/MemberErrorProseTest.php);
 *  3. fixture envelopes resolve to human prose under the pinned chain.
 *
 * Runtime: node's built-in test runner (`npm test`) with native TS type
 * stripping — zero dev dependencies, matching web-ui's lean footprint.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const CLIENT_SOURCE = readFileSync(
    fileURLToPath(new URL('../node_modules/@phlix/ui/src/api/client.ts', import.meta.url)),
    'utf8',
);

/** The member-facing HTTP reason phrases that must never ride the wire again. */
const HTTP_PHRASES = new Set([
    'Conflict',
    'Bad Request',
    'Not Found',
    'Gone',
    'Unauthorized',
    'Forbidden',
    'Internal Server Error',
    'Unprocessable Entity',
]);

/**
 * Mirror of the pinned `extractError` chain (error field first, message
 * second, fixed fallback). Kept honest by the source pins below: if the
 * real client text drifts from this shape, pin 1 or 2 reddens first.
 */
function resolveMemberFacingError(payload: Record<string, unknown>): string {
    if (typeof payload['error'] === 'string') {
        return payload['error'];
    }
    if (typeof payload['message'] === 'string') {
        return payload['message'];
    }
    return 'Request failed';
}

/** Post-cure hub envelopes, one per failure class (byte-copied wire shapes). */
const HUB_ENVELOPES: Array<Record<string, unknown>> = [
    { error: 'Sign in to share your libraries', code: 'auth.required', message: 'Sign in to share your libraries' },
    { error: 'A request body is required', code: 'invalid_body', message: 'A request body is required' },
    {
        error: 'Enter the email address of the person you want to share with',
        code: 'missing_collaborator_email',
        message: 'Enter the email address of the person you want to share with',
    },
    {
        error: 'This server has reached its collaborator limit',
        code: 'quota.exceeded',
        message: 'This server has reached its collaborator limit',
    },
    { error: 'No user found with that email address', code: 'user_not_found', message: 'No user found with that email address' },
    { error: 'You do not own this server', code: 'not_server_owner', message: 'You do not own this server' },
    { error: 'That share no longer exists', code: 'share_not_found', message: 'That share no longer exists' },
    { error: 'You do not own this share', code: 'not_share_owner', message: 'You do not own this share' },
    {
        error: 'This library is already shared with you',
        code: 'share_exists',
        message: 'This library is already shared with you',
    },
    {
        error: 'That invite link no longer exists',
        code: 'invite_link_not_found',
        message: 'That invite link no longer exists',
    },
    {
        error: 'Something went wrong on the hub. Please try again.',
        code: 'unknown_error',
        message: 'Something went wrong on the hub. Please try again.',
    },
];

describe('pinned client error resolution (@phlix/ui extractError)', () => {
    it('checks the error field BEFORE the message field', () => {
        const errorAt = CLIENT_SOURCE.indexOf("typeof obj['error'] === 'string'");
        const messageAt = CLIENT_SOURCE.indexOf("typeof obj['message'] === 'string'");
        assert.notEqual(errorAt, -1, 'pinned client lost its error-field check entirely');
        assert.notEqual(messageAt, -1, 'pinned client lost its message-field check entirely');
        assert.ok(
            errorAt < messageAt,
            'pinned client changed field precedence — hub envelopes mirror error/message, but '
                + 're-verify the member-facing law (MemberErrorProseTest) against the new client',
        );
    });

    it('accepts ANY string (including the empty one) — server must never ship empty error', () => {
        assert.match(CLIENT_SOURCE, /typeof obj\['error'\] === 'string'/);
        // The truthiness trap this pin exists for: '' is a string, so a
        // hypothetical empty hub `error` would render as a blank message.
        assert.equal(resolveMemberFacingError({ error: '', code: 'x', message: 'fallback' }), '');
    });
});

describe('hub member-facing envelopes (post-M5 wire law)', () => {
    it('resolve to a non-empty human sentence for every failure class', () => {
        for (const envelope of HUB_ENVELOPES) {
            const resolved = resolveMemberFacingError(envelope);
            assert.notEqual(resolved, '', `empty error shipped: ${String(envelope['code'])}`);
            assert.ok(!HTTP_PHRASES.has(resolved), `HTTP phrase reached the member channel: ${resolved}`);
            assert.equal(
                resolved,
                envelope['message'],
                'error and message must mirror each other so precedence cannot change what members see',
            );
        }
    });

    it('keeps the machine code in the code field, untouched', () => {
        for (const envelope of HUB_ENVELOPES) {
            assert.equal(typeof envelope['code'], 'string');
            assert.ok((envelope['code'] as string).length > 0);
        }
    });
});
