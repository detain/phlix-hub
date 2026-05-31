/**
 * claim-server.js — live-normalise the claim-code input.
 *
 * The browser's HTML5 `pattern` rejected valid codes that weren't already in
 * the exact UPPER `ABCD-1234` shape — so a code typed in lower case, with a
 * stray space, or pasted with an en-dash (common on mobile autocorrect) failed
 * with "Please match the requested format" even though the (tolerant) server
 * would have accepted it.
 *
 * This mirrors the server normalisation (`strtoupper` + strip non-alphanumeric)
 * client-side and re-inserts the canonical dash as the user types/pastes, so
 * the field always holds `XXXX-XXXX` upper-case and the pattern is satisfied.
 */
(function () {
    'use strict';

    var input = document.getElementById('claim_code');
    if (!input) {
        return;
    }

    /** Strip to A-Z0-9 (upper), cap at 8 chars, re-insert the dash after 4. */
    function normalize(raw) {
        var s = String(raw).toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8);
        if (s.length > 4) {
            s = s.slice(0, 4) + '-' + s.slice(4);
        }
        return s;
    }

    input.addEventListener('input', function () {
        var next = normalize(input.value);
        if (next !== input.value) {
            input.value = next;
            try {
                input.setSelectionRange(next.length, next.length);
            } catch (e) {
                /* setSelectionRange can throw on some input types — ignore. */
            }
        }
    });

    // Normalise an autofilled/pasted initial value, and again on submit as a
    // final guard before the POST.
    input.value = normalize(input.value);

    var form = document.getElementById('claim-form');
    if (form) {
        form.addEventListener('submit', function () {
            input.value = normalize(input.value);
        });
    }
})();
