/**
 * My Servers page interactivity.
 *
 * Handles:
 * - Remove server button (confirmation + DELETE request)
 * - Fade animation on card removal
 * - Empty state reveal when all servers are removed
 *
 * @package Phlix\Hub
 * @since 0.4.0
 */

window.PhlixApp = window.PhlixApp || {};

(function () {
    'use strict';

    /**
     * Get the access token from cookie or localStorage.
     *
     * @return string|null
     */
    function getAccessToken() {
        const match = document.cookie.match(/(?:^|;\s*)phlix_hub_token=([^;]+)/);
        if (match) {
            return decodeURIComponent(match[1]);
        }
        return null;
    }

    /**
     * Show the empty state in the server list container.
     */
    function showEmptyState() {
        const list = document.querySelector('.server-list');
        if (!list) return;

        list.innerHTML = `
            <div class="empty-state">
                <div class="empty-icon">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <rect x="2" y="3" width="20" height="14" rx="2"/>
                        <line x1="8" y1="21" x2="16" y2="21"/>
                        <line x1="12" y1="17" x2="12" y2="21"/>
                    </svg>
                </div>
                <h2>No servers yet</h2>
                <p>You haven't claimed any servers yet.</p>
                <p>To get started, run <code>php scripts/pair-with-hub.php</code>
                   on your Phlix server and enter the claim code below.</p>
                <a href="/claim-server" class="btn btn-primary">Claim a Server</a>
            </div>
        `;
    }

    /**
     * Remove a server card from the DOM with a fade animation.
     *
     * @param {HTMLElement} card
     */
    function fadeOutCard(card) {
        card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
        card.style.opacity = '0';
        card.style.transform = 'translateY(-10px)';
        setTimeout(function () {
            card.remove();
            const remaining = document.querySelectorAll('.server-card');
            if (remaining.length === 0) {
                showEmptyState();
            }
        }, 300);
    }

    /**
     * Handle remove button click.
     *
     * @param {Event} e
     */
    async function handleRemove(e) {
        const btn = e.currentTarget;
        const serverId = btn.dataset.serverId;

        if (!confirm('Remove this server? This cannot be undone.')) {
            return;
        }

        const token = getAccessToken();
        if (!token) {
            alert('Session expired. Please log in again.');
            window.location.href = '/login';
            return;
        }

        try {
            const resp = await fetch('/api/v1/me/servers/' + encodeURIComponent(serverId), {
                method: 'DELETE',
                headers: {
                    'Authorization': 'Bearer ' + token,
                    'Accept': 'application/json',
                },
            });

            if (resp.ok) {
                const card = btn.closest('.server-card');
                if (card) {
                    fadeOutCard(card);
                }
            } else if (resp.status === 401) {
                alert('Session expired. Please log in again.');
                window.location.href = '/login';
            } else {
                const data = await resp.json().catch(function () { return {}; });
                alert('Failed to remove server: ' + (data.message || data.error || 'Unknown error'));
            }
        } catch (err) {
            alert('Failed to remove server: network error');
        }
    }

    /**
     * Initialise the My Servers page.
     */
    function init() {
        document.querySelectorAll('.btn-remove').forEach(function (btn) {
            btn.addEventListener('click', handleRemove);
        });
    }

    document.addEventListener('DOMContentLoaded', init);

    window.PhlixApp.MyServersPage = {
        init: init,
    };
})();
