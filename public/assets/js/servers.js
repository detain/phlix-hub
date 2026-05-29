/**
 * Server detail page interactivity (H.3 + H.4).
 *
 * Fetches and renders:
 * - Server info (status badge, version, last seen)
 * - Active relay session details
 * - TLS status (subdomain, certificate, renewal) — H.4
 * - Recent heartbeat history
 *
 * @package Phlix\Hub
 * @since 0.4.0
 */

window.PhlixApp = window.PhlixApp || {};

(function () {
    'use strict';

    /**
     * Extract the server ID from the current URL path.
     * Expected format: /servers/{id}
     *
     * @return {string|null}
     */
    function getServerIdFromUrl() {
        const match = window.location.pathname.match(/^\/servers\/([^/]+)/);
        return match ? match[1] : null;
    }

    /**
     * Format a UNIX timestamp as a relative time string (e.g. "2 minutes ago").
     *
     * @param {number} timestamp UNIX seconds.
     * @return {string}
     */
    function formatRelativeTime(timestamp) {
        if (!timestamp || timestamp === 0) {
            return 'Never';
        }
        const now = Math.floor(Date.now() / 1000);
        const diff = now - timestamp;
        if (diff < 60) {
            return diff + 's ago';
        }
        if (diff < 3600) {
            return Math.floor(diff / 60) + 'm ago';
        }
        if (diff < 86400) {
            return Math.floor(diff / 3600) + 'h ago';
        }
        return Math.floor(diff / 86400) + 'd ago';
    }

    /**
     * Format uptime seconds as a human-readable string.
     *
     * @param {number} seconds
     * @return {string}
     */
    function formatUptime(seconds) {
        if (!seconds || seconds === 0) {
            return '0s';
        }
        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const parts = [];
        if (days > 0) {
            parts.push(days + 'd');
        }
        if (hours > 0) {
            parts.push(hours + 'h');
        }
        if (minutes > 0) {
            parts.push(minutes + 'm');
        }
        if (parts.length === 0) {
            return seconds + 's';
        }
        return parts.join(' ');
    }

    /**
     * Format bytes as a human-readable string.
     *
     * @param {number} bytes
     * @return {string}
     */
    function formatBytes(bytes) {
        if (!bytes || bytes === 0) {
            return '0 B';
        }
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let unitIndex = 0;
        let value = bytes;
        while (value >= 1024 && unitIndex < units.length - 1) {
            value /= 1024;
            unitIndex++;
        }
        return value.toFixed(unitIndex === 0 ? 0 : 1) + ' ' + units[unitIndex];
    }

    /**
     * Format an ISO datetime string (from relay session opened_at) as relative time.
     *
     * @param {string} isoString
     * @return {string}
     */
    function formatOpenedAt(isoString) {
        if (!isoString) {
            return 'Unknown';
        }
        const timestamp = Math.floor(new Date(isoString).getTime() / 1000);
        return formatRelativeTime(timestamp);
    }

    /**
     * Return a CSS class for a status badge.
     *
     * @param {string} status
     * @return {string}
     */
    function statusBadgeClass(status) {
        switch (status) {
            case 'online':
                return 'status-online';
            case 'offline':
                return 'status-offline';
            case 'claiming':
                return 'status-claiming';
            case 'disabled':
                return 'status-disabled';
            default:
                return 'status-unknown';
        }
    }

    /**
     * Render the server info section.
     *
     * @param {object} server
     */
    function renderServerInfo(server) {
        const titleEl = document.getElementById('server-page-title');
        if (titleEl) {
            titleEl.textContent = server.server_name || 'Unknown Server';
        }

        const statusEl = document.getElementById('server-status');
        if (statusEl) {
            statusEl.textContent = server.status || 'unknown';
            statusEl.className = 'info-value status-badge ' + statusBadgeClass(server.status);
        }

        const versionEl = document.getElementById('server-version');
        if (versionEl) {
            versionEl.textContent = server.version || 'unknown';
        }

        const lastSeenEl = document.getElementById('server-last-seen');
        if (lastSeenEl) {
            const ts = server.last_seen_at;
            lastSeenEl.textContent = ts ? formatRelativeTime(ts) : 'Never';
        }

        // Hostname candidates
        const hostnamesContainer = document.getElementById('server-hostnames');
        const hostnamesEl = document.getElementById('hostname-candidates');
        if (
            server.hostname_candidates &&
            Array.isArray(server.hostname_candidates) &&
            server.hostname_candidates.length > 0
        ) {
            if (hostnamesContainer) {
                hostnamesContainer.style.display = 'block';
            }
            if (hostnamesEl) {
                hostnamesEl.innerHTML = server.hostname_candidates
                    .map(function (h) {
                        return '<code class="hostname">' + escHtml(h) + '</code>';
                    })
                    .join(' ');
            }
        }
    }

    /**
     * Escape HTML special characters.
     *
     * @param {string} str
     * @return {string}
     */
    function escHtml(str) {
        if (str === null || str === undefined) {
            return '';
        }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /**
     * Render the relay session section (or empty state).
     *
     * @param {object|null} relaySession
     */
    function renderRelaySession(relaySession) {
        const activeEl = document.getElementById('relay-session-active');
        const emptyEl = document.getElementById('relay-session-empty');

        if (!relaySession) {
            if (activeEl) {
                activeEl.style.display = 'none';
            }
            if (emptyEl) {
                emptyEl.style.display = 'block';
            }
            return;
        }

        if (activeEl) {
            activeEl.style.display = 'block';
        }
        if (emptyEl) {
            emptyEl.style.display = 'none';
        }

        const workerEl = document.getElementById('relay-worker-node');
        if (workerEl) {
            workerEl.textContent = relaySession.worker_node || 'Unknown';
        }

        const openedEl = document.getElementById('relay-opened-at');
        if (openedEl) {
            openedEl.textContent = formatOpenedAt(relaySession.opened_at);
        }

        const bytesInEl = document.getElementById('relay-bytes-in');
        if (bytesInEl) {
            bytesInEl.textContent = formatBytes(relaySession.bytes_in);
        }

        const bytesOutEl = document.getElementById('relay-bytes-out');
        if (bytesOutEl) {
            bytesOutEl.textContent = formatBytes(relaySession.bytes_out);
        }

        const lastFrameEl = document.getElementById('relay-last-frame');
        if (lastFrameEl) {
            const ts = relaySession.last_frame_at;
            lastFrameEl.textContent = ts ? formatRelativeTime(ts) : 'Never';
        }
    }

    /**
     * Render the heartbeat history table.
     *
     * @param {Array} history
     */
    function renderHeartbeatHistory(history) {
        const containerEl = document.getElementById('heartbeat-list-container');
        const emptyEl = document.getElementById('heartbeat-empty');
        const tbody = document.getElementById('heartbeat-tbody');

        if (!history || history.length === 0) {
            if (containerEl) {
                containerEl.style.display = 'none';
            }
            if (emptyEl) {
                emptyEl.style.display = 'block';
            }
            return;
        }

        if (containerEl) {
            containerEl.style.display = 'block';
        }
        if (emptyEl) {
            emptyEl.style.display = 'none';
        }

        if (tbody) {
            tbody.innerHTML = history
                .map(function (hb) {
                    return (
                        '<tr>' +
                        '<td>' + escHtml(formatRelativeTime(hb.received_at)) + '</td>' +
                        '<td>' + escHtml(hb.version || '') + '</td>' +
                        '<td>' + escHtml(formatUptime(hb.uptime_seconds)) + '</td>' +
                        '<td>' + escHtml(String(hb.active_sessions)) + '</td>' +
                        '<td>' + escHtml(String(hb.active_transcodes)) + '</td>' +
                        '</tr>'
                    );
                })
                .join('');
        }
    }

    /**
     * Render the TLS status section (or empty state if no subdomain).
     *
     * @param {object|null} tlsStatus
     * @param {string|null} fqdn
     */
    function renderTlsStatus(tlsStatus, fqdn) {
        const contentEl = document.getElementById('tls-status-content');
        const emptyEl = document.getElementById('tls-status-empty');

        if (!tlsStatus || !fqdn) {
            if (contentEl) {
                contentEl.style.display = 'none';
            }
            if (emptyEl) {
                emptyEl.style.display = 'block';
            }
            return;
        }

        if (contentEl) {
            contentEl.style.display = 'block';
        }
        if (emptyEl) {
            emptyEl.style.display = 'none';
        }

        const subdomainEl = document.getElementById('tls-subdomain');
        if (subdomainEl) {
            subdomainEl.textContent = fqdn;
        }

        const provisionedEl = document.getElementById('tls-provisioned');
        if (provisionedEl) {
            if (tlsStatus.provisioned) {
                provisionedEl.textContent = 'Provisioned';
                provisionedEl.className = 'info-value status-provisioned';
            } else {
                provisionedEl.textContent = 'Not provisioned';
                provisionedEl.className = 'info-value status-not-provisioned';
            }
        }

        const renewalEl = document.getElementById('tls-renewal');
        if (renewalEl) {
            if (tlsStatus.needs_renewal) {
                renewalEl.textContent = 'Expiring soon';
                renewalEl.className = 'info-value status-warning';
            } else {
                renewalEl.textContent = 'OK';
                renewalEl.className = 'info-value status-ok';
            }
        }
    }

    /**
     * Show an error message in the page.
     *
     * @param {string} message
     */
    function showError(message) {
        const errorEl = document.getElementById('server-error');
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.style.display = 'block';
        }
    }

    /**
     * Fetch and render the server detail page.
     *
     * @param {string} serverId
     */
    async function loadServerDetail(serverId) {
        try {
            const resp = await fetch(
                '/api/v1/me/servers/' + encodeURIComponent(serverId),
                { credentials: 'include' }
            );

            if (resp.status === 401) {
                window.location.href = '/login';
                return;
            }

            if (resp.status === 403) {
                showError('You do not have permission to view this server.');
                return;
            }

            if (resp.status === 404) {
                showError('Server not found.');
                return;
            }

            if (!resp.ok) {
                showError('Failed to load server details. Please try again.');
                return;
            }

            const data = await resp.json();

            renderServerInfo(data.server);
            renderRelaySession(data.relay_session);
            renderTlsStatus(data.tls_status, data.server.fqdn);
            renderHeartbeatHistory(data.heartbeat_history);
        } catch (err) {
            showError('Network error — could not load server details.');
        }
    }

    /**
     * Initialise the heartbeat history toggle.
     */
    function initHeartbeatToggle() {
        const toggleBtn = document.getElementById('heartbeat-toggle');
        const container = document.getElementById('heartbeat-list-container');
        if (!toggleBtn || !container) {
            return;
        }

        toggleBtn.addEventListener('click', function () {
            const isExpanded = toggleBtn.getAttribute('aria-expanded') === 'true';
            if (isExpanded) {
                container.style.display = 'none';
                toggleBtn.textContent = 'Show';
                toggleBtn.setAttribute('aria-expanded', 'false');
            } else {
                container.style.display = 'block';
                toggleBtn.textContent = 'Hide';
                toggleBtn.setAttribute('aria-expanded', 'true');
            }
        });
    }

    /**
     * Initialise the page.
     */
    function init() {
        const serverId = getServerIdFromUrl();
        if (!serverId) {
            showError('Invalid server ID in URL.');
            return;
        }

        loadServerDetail(serverId);
        initHeartbeatToggle();
    }

    document.addEventListener('DOMContentLoaded', init);

    window.PhlixApp.ServerDetailPage = {
        init: init,
        loadServerDetail: loadServerDetail,
    };
})();
