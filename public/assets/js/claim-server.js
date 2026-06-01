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

    // Normalise an autofilled/pasted initial value.
    input.value = normalize(input.value);

    var form = document.getElementById('claim-form');
    var result = document.getElementById('claim-result');

    /** Render a message into the result region. */
    function showResult(message, isError) {
        if (!result) {
            return;
        }
        result.textContent = message;
        result.className = 'claim-result ' + (isError ? 'error' : 'success');
    }

    if (form) {
        // A native form POST cannot send the `Accept-Phlix-Protocol: v1`
        // header that HubProtocolMiddleware requires, so it always failed with
        // HUB_PROTOCOL_UNSUPPORTED. Intercept the submit and POST via fetch
        // with the protocol header; the `phlix_hub_token` session cookie is
        // carried by `credentials: 'same-origin'` for AuthMiddleware.
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            input.value = normalize(input.value);

            var button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
            }
            showResult('Claiming server…', false);

            fetch(form.getAttribute('action') || '/api/v1/server-claims/claim', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Accept-Phlix-Protocol': 'v1'
                },
                body: JSON.stringify({ claim_code: input.value })
            }).then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (data) {
                    return { ok: response.ok, status: response.status, data: data };
                });
            }).then(function (res) {
                if (res.ok && res.data && res.data.server_id) {
                    showResult('Server claimed successfully (' + res.data.server_id + ').', false);
                    form.reset();
                } else {
                    var msg = (res.data && (res.data.message || res.data.error)) ||
                        ('Failed to claim server (HTTP ' + res.status + ').');
                    showResult(msg, true);
                }
            }).catch(function () {
                showResult('Network error — could not reach the hub. Please try again.', true);
            }).then(function () {
                if (button) {
                    button.disabled = false;
                }
            });
        });
    }
})();
