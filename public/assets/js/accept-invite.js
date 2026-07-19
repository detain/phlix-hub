/**
 * Accept Invite page interactivity.
 *
 * Handles:
 * - Form submission via fetch to POST /api/v1/me/invite-links/{token}/redeem
 * - Redirect to /app/shared-with-me on success
 * - Error message display on failure
 *
 * @package Phlix\Hub
 */

(function () {
    'use strict';

    var form = document.getElementById('accept-invite-form');
    var errorDiv = document.getElementById('accept-error');
    var submitBtn = document.getElementById('accept-submit');

    /**
     * Escape HTML special characters to prevent XSS.
     *
     * @param {string} str
     * @return {string}
     */
    function escapeHtml(str) {
        if (!str) {
            return '';
        }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /**
     * Show an error message in the error div.
     *
     * @param {string} message
     */
    function showError(message) {
        if (errorDiv) {
            errorDiv.textContent = message;
            errorDiv.style.display = 'block';
        } else {
            alert(message);
        }
    }

    /**
     * Hide the error message.
     */
    function hideError() {
        if (errorDiv) {
            errorDiv.style.display = 'none';
        }
    }

    /**
     * Handle form submission.
     *
     * @param {Event} e
     */
    function handleSubmit(e) {
        e.preventDefault();
        hideError();

        var token = form.dataset.token;
        if (!token) {
            showError('Invalid invite token.');
            return;
        }

        // Disable button to prevent double-submit
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Accepting...';
        }

        fetch('/api/v1/me/invite-links/' + encodeURIComponent(token) + '/redeem', {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Accept': 'application/json',
            },
        })
            .then(function (resp) {
                return resp.json().then(function (data) {
                    return { ok: resp.ok, status: resp.status, data: data };
                });
            })
            .then(function (result) {
                if (result.ok) {
                    // Success — redirect to shared-with-me page
                    window.location.href = '/app/shared-with-me';
                } else if (result.status === 401) {
                    showError('Session expired. Please log in again.');
                    window.location.href = '/login?redirect=/invite/' + encodeURIComponent(token);
                } else if (result.status === 410) {
                    showError('This invite link has expired or been exhausted.');
                } else if (result.status === 404) {
                    showError('This invite link was not found.');
                } else {
                    showError(result.data.error || result.data.message || 'Failed to accept invite.');
                }
            })
            .catch(function () {
                showError('Network error. Please try again.');
            })
            .finally(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Accept Invite';
                }
            });
    }

    // Attach event listener
    if (form) {
        form.addEventListener('submit', handleSubmit);
    }
})();
