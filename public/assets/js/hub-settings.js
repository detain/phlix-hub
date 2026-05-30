/**
 * Hub Settings — client-side interactivity.
 *
 * Handles:
 * - Fetching hub settings from GET /api/v1/me/hub-settings
 * - Rendering form fields based on their declared types
 * - Showing "overridden" badges on fields that differ from defaults
 * - Persisting settings via PUT /api/v1/me/hub-settings
 * - Resetting to defaults by omitting keys from the PUT payload
 */
(function () {
    'use strict';

    // ── DOM refs ──────────────────────────────────────────────────────────────

    var LOADING   = document.getElementById('hub-settings-loading');
    var CONTENT    = document.getElementById('hub-settings-content');
    var FORM       = document.getElementById('hub-settings-form');
    var SAVE_BTN   = document.getElementById('btn-save');
    var RESET_BTN  = document.getElementById('btn-reset');
    var MESSAGE    = document.getElementById('hub-settings-message');

    /** Map of setting key → current effective value */
    var settings = {};
    /** List of keys that have been overridden vs. config defaults */
    var overridden = [];
    /** Map of setting key → declared type */
    var types = {};
    /** Map of setting key → original value (for reset) */
    var originalValues = {};

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Show the success/error message banner.
     * @param {string} msg
     * @param {string} type  "success" | "error"
     */
    function showMessage(msg, type) {
        if (!MESSAGE) return;
        MESSAGE.textContent = msg;
        MESSAGE.className = 'settings-message ' + type;
        MESSAGE.style.display = '';
    }

    function hideMessage() {
        if (!MESSAGE) return;
        MESSAGE.style.display = 'none';
    }

    /**
     * Show the main content area and hide the loading spinner.
     */
    function showContent() {
        if (LOADING) LOADING.style.display = 'none';
        if (CONTENT) CONTENT.style.display = '';
    }

    /**
     * Escape HTML to prevent XSS in dynamic content.
     * @param {string} str
     * @returns {string}
     */
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    /**
     * Get the input/select/textarea element for a given setting key.
     * @param {string} key  Dotted key, e.g. "server.enrollment_ttl"
     * @returns {HTMLElement|null}
     */
    function fieldElement(key) {
        // The field names in the template match the dotted keys exactly.
        return document.querySelector('[name="' + escapeHtml(key) + '"]');
    }

    /**
     * Get the overridden-badge element for a given setting key.
     * @param {string} key
     * @returns {HTMLElement|null}
     */
    function badgeElement(key) {
        return document.getElementById('badge-' + key);
    }

    /**
     * Populate a single form field with its current effective value,
     * using the correct input type based on the declared type string.
     * @param {string} key
     * @param {mixed} value
     * @param {string} type  int|bool|float|string|json
     */
    function populateField(key, value, type) {
        var el = fieldElement(key);
        if (!el) return;

        originalValues[key] = value;

        if (type === 'bool') {
            el.checked = Boolean(value);
        } else if (type === 'json') {
            el.value = Array.isArray(value) ? JSON.stringify(value, null, 2) : '';
        } else if (type === 'int' || type === 'float') {
            el.value = value !== null && value !== undefined ? String(value) : '';
        } else {
            el.value = value !== null && value !== undefined ? String(value) : '';
        }
    }

    /**
     * Show the "overridden" badge for a key if it appears in the
     * overridden list.
     * @param {string} key
     */
    function showOverriddenBadge(key) {
        var badge = badgeElement(key);
        if (badge) badge.style.display = '';
    }

    /**
     * Fetch settings from the API and populate the form.
     */
    async function loadSettings() {
        try {
            var resp = await fetch('/api/v1/me/hub-settings', { credentials: 'include' });
            if (!resp.ok) {
                if (resp.status === 401 || resp.status === 403) {
                    window.location.href = '/login';
                    return;
                }
                throw new Error('HTTP ' + resp.status);
            }
            var data = await resp.json();

            if (!data.success) {
                throw new Error(data.message || 'Failed to load settings');
            }

            settings    = data.data.settings;
            overridden   = data.data.overridden || [];
            types       = data.data.types || {};

            // Populate each field.
            for (var key in settings) {
                if (!Object.prototype.hasOwnProperty.call(settings, key)) continue;
                populateField(key, settings[key], types[key] || 'string');
                if (overridden.indexOf(key) !== -1) {
                    showOverriddenBadge(key);
                }
            }

            showContent();
        } catch (err) {
            console.error('Failed to load hub settings:', err);
            if (LOADING) {
                LOADING.textContent = 'Failed to load settings. Please refresh the page.';
            }
        }
    }

    /**
     * Collect current form values and PUT them to the API.
     */
    async function saveSettings() {
        hideMessage();

        if (!FORM) return;

        // Collect only fields that are present in ALLOWED_KEYS.
        // The name attributes on the inputs match the dotted keys exactly.
        var payload = {};
        var elements = FORM.querySelectorAll('input, select, textarea');
        for (var i = 0; i < elements.length; i++) {
            var field = /** @type {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} */ (elements[i]);
            var name = field.name;
            if (!name) continue;

            // Skip fields not in ALLOWED_KEYS (just in case).
            if (!Object.prototype.hasOwnProperty.call(types, name)) continue;

            var type = types[name];
            var value;

            if (type === 'bool') {
                value = field.checked;
            } else if (type === 'int') {
                value = field.value !== '' ? parseInt(field.value, 10) : null;
            } else if (type === 'float') {
                value = field.value !== '' ? parseFloat(field.value) : null;
            } else if (type === 'json') {
                try {
                    value = field.value !== '' ? JSON.parse(field.value) : null;
                } catch (e) {
                    showMessage('Invalid JSON in channels field.', 'error');
                    return;
                }
            } else {
                value = field.value;
            }

            // Only include fields that differ from their original value (optimization)
            // or include all for all-or-nothing semantics.
            payload[name] = value;
        }

        var body = JSON.stringify({ settings: payload });

        try {
            setSavingState(true);
            var resp = await fetch('/api/v1/me/hub-settings', {
                method: 'PUT',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: body,
            });

            var result = await resp.json();

            if (!resp.ok || !result.success) {
                var msg = (result && result.message) ? result.message : 'Failed to save settings.';
                showMessage(msg, 'error');
                return;
            }

            showMessage('Settings saved successfully.', 'success');

            // Reload to reflect new effective values and overridden list.
            await loadSettings();
        } catch (err) {
            console.error('Failed to save hub settings:', err);
            showMessage('Failed to save settings. Please try again.', 'error');
        } finally {
            setSavingState(false);
        }
    }

    /**
     * Reset form to original (effective) values without hitting the API.
     */
    function resetForm() {
        for (var key in originalValues) {
            if (!Object.prototype.hasOwnProperty.call(originalValues, key)) continue;
            populateField(key, originalValues[key], types[key] || 'string');
        }
        hideMessage();
    }

    /**
     * Enable/disable the save button while a request is in flight.
     * @param {boolean} saving
     */
    function setSavingState(saving) {
        if (SAVE_BTN) {
            SAVE_BTN.disabled = saving;
            SAVE_BTN.textContent = saving ? 'Saving…' : 'Save Changes';
        }
        if (RESET_BTN) {
            RESET_BTN.disabled = saving;
        }
    }

    // ── Event listeners ───────────────────────────────────────────────────────

    if (FORM) {
        FORM.addEventListener('submit', function (e) {
            e.preventDefault();
            saveSettings();
        });
    }

    if (RESET_BTN) {
        RESET_BTN.addEventListener('click', resetForm);
    }

    // ── Bootstrap ───────────────────────────────────────────────────────────

    loadSettings();
})();
