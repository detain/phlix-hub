/**
 * Invite Links page interactivity.
 *
 * Handles:
 * - Modal open/close
 * - Server dropdown → fetch libraries
 * - Create form submit → POST to API
 * - Copy URL button → clipboard
 * - Revoke button → DELETE to API
 * - List rendering with badges
 *
 * @package Phlix\Hub
 */

(function () {
    'use strict';

    // DOM elements
    var btnNewInvite = document.getElementById('btn-new-invite');
    var modalOverlay = document.getElementById('modal-overlay');
    var createModal = document.getElementById('create-modal');
    var btnCloseModal = document.getElementById('btn-close-modal');
    var btnCancel = document.getElementById('btn-cancel');
    var createForm = document.getElementById('create-form');
    var serverSelect = document.getElementById('input-server');
    var librarySelect = document.getElementById('input-library');
    var linksList = document.getElementById('invite-links-list');
    var emptyState = document.getElementById('empty-state');

    /**
     * Get the access token from cookie.
     *
     * @return string|null
     */
    function getAccessToken()
    {
        var match = document.cookie.match(/(?:^|;\s*)phlix_hub_token=([^;]+)/);
        if (match) {
            return decodeURIComponent(match[1]);
        }
        return null;
    }

    /**
     * Fetch JSON with auth credentials.
     *
     * @param {string} url
     * @param {object} options
     * @return {Promise<object>}
     */
    function fetchJson(url, options)
    {
        var token = getAccessToken();
        var headers = {
            'Accept': 'application/json',
        };
        if (token) {
            headers['Authorization'] = 'Bearer ' + token;
        }
        return fetch(url, Object.assign({ credentials: 'include' }, options, { headers: Object.assign(headers, options.headers || {}) }))
            .then(function (resp) {
                return resp.json().then(function (data) {
                    return { ok: resp.ok, status: resp.status, data: data };
                });
            });
    }

    /**
     * Format a UNIX timestamp to a readable date string.
     *
     * @param {number|null} timestamp
     * @return {string}
     */
    function formatDate(timestamp)
    {
        if (timestamp === null || timestamp === undefined) {
            return 'Never';
        }
        var date = new Date(timestamp * 1000);
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return months[date.getMonth()] + ' ' + date.getDate() + ' ' + date.getFullYear();
    }

    /**
     * Format relative time (e.g. "5 minutes ago").
     *
     * @param {number|null} timestamp
     * @return {string}
     */
    function formatRelativeTime(timestamp)
    {
        if (timestamp === null || timestamp === undefined) {
            return 'never';
        }
        var diff = Date.now() - (timestamp * 1000);
        var seconds = Math.floor(diff / 1000);
        if (seconds < 60) {
            return 'just now';
        }
        var minutes = Math.floor(seconds / 60);
        if (minutes < 60) {
            return minutes + ' min ago';
        }
        var hours = Math.floor(minutes / 60);
        if (hours < 24) {
            return hours + ' hour' + (hours > 1 ? 's' : '') + ' ago';
        }
        var days = Math.floor(hours / 24);
        return days + ' day' + (days > 1 ? 's' : '') + ' ago';
    }

    /**
     * Get a human-readable expiry string.
     *
     * @param {number|null} expiresAt
     * @return {string}
     */
    function formatExpiry(expiresAt)
    {
        if (expiresAt === null || expiresAt === undefined) {
            return 'Never expires';
        }
        var now = Math.floor(Date.now() / 1000);
        if (expiresAt < now) {
            return 'Expired ' + formatDate(expiresAt);
        }
        var diff = expiresAt - now;
        var days = Math.floor(diff / 86400);
        if (days < 1) {
            return 'Expires today';
        }
        if (days === 1) {
            return 'Expires tomorrow';
        }
        if (days < 30) {
            return 'Expires in ' + days + ' days';
        }
        return 'Expires ' + formatDate(expiresAt);
    }

    /**
     * Get permission badge HTML.
     *
     * @param {string} permission
     * @return {string}
     */
    function permissionBadge(permission)
    {
        if (permission === 'readwrite') {
            return '<span class="badge badge-permission">Read/Write</span>';
        }
        return '<span class="badge badge-permission">Read only</span>';
    }

    /**
     * Get status badge HTML for exhausted/expired links.
     *
     * @param {object} link
     * @return {string}
     */
    function statusBadge(link)
    {
        if (link.use_count >= link.max_uses) {
            return '<span class="badge badge-exhausted">Exhausted</span>';
        }
        if (link.expires_at && (link.expires_at * 1000) < Date.now()) {
            return '<span class="badge badge-expired">Expired</span>';
        }
        return '';
    }

    /**
     * Render a single invite link card.
     *
     * @param {object} link
     * @return {string} HTML string
     */
    function renderLinkCard(link)
    {
        var serverName = link.server_name || link.server_id || 'Unknown Server';
        var libraryName = link.library_id ? (link.library_name || link.library_id) : 'All Libraries';
        var uses = link.use_count + '/' + link.max_uses;
        var status = statusBadge(link);
        var copyLabel = 'Copy URL';

        return '<div class="invite-link-card" data-link-id="' + link.id + '">' +
            '<div class="link-card-header">' +
                '<span class="link-server">' + escapeHtml(serverName) + '</span>' +
                '<div class="link-actions">' +
                    '<button type="button" class="btn btn-small btn-copy" data-url="' + escapeHtml(link.url) + '">' + copyLabel + '</button>' +
                    '<button type="button" class="btn btn-small btn-revoke" data-link-id="' + link.id + '">&#10005; Revoke</button>' +
                '</div>' +
            '</div>' +
            '<div class="link-card-body">' +
                '<span>Library: ' + escapeHtml(libraryName) + '</span> ' +
                permissionBadge(link.permission) + ' ' +
                status +
                ' <span class="link-uses">Uses: ' + uses + '</span>' +
            '</div>' +
            '<div class="link-card-footer">' +
                '<span>' + formatExpiry(link.expires_at) + '</span>' +
                ' · <span>Created: ' + formatDate(link.created_at) + '</span>' +
            '</div>' +
        '</div>';
    }

    /**
     * Escape HTML special characters.
     *
     * @param {string} str
     * @return {string}
     */
    function escapeHtml(str)
    {
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
     * Show the invite links list.
     *
     * @param {Array} links
     */
    function showLinks(links)
    {
        if (!linksList) {
            return;
        }
        if (links.length === 0) {
            linksList.innerHTML = '';
            if (emptyState) {
                emptyState.style.display = 'block';
            }
            return;
        }
        if (emptyState) {
            emptyState.style.display = 'none';
        }
        linksList.innerHTML = links.map(renderLinkCard).join('');
        attachCardListeners();
    }

    /**
     * Prepend a new link card to the list.
     *
     * @param {object} link
     */
    function prependLinkCard(link)
    {
        if (!linksList) {
            return;
        }
        if (emptyState) {
            emptyState.style.display = 'none';
        }
        var card = document.createElement('div');
        card.innerHTML = renderLinkCard(link);
        linksList.insertBefore(card.firstElementChild, linksList.firstChild);
        attachCardListeners();
    }

    /**
     * Remove a link card from the DOM.
     *
     * @param {string} linkId
     */
    function removeLinkCard(linkId)
    {
        var card = linksList && linksList.querySelector('[data-link-id="' + linkId + '"]');
        if (card) {
            card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            card.style.opacity = '0';
            card.style.transform = 'translateY(-10px)';
            setTimeout(function () {
                card.remove();
                if (linksList && linksList.querySelectorAll('.invite-link-card').length === 0) {
                    if (emptyState) {
                        emptyState.style.display = 'block';
                    }
                }
            }, 300);
        }
    }

    /**
     * Attach event listeners to link cards.
     */
    function attachCardListeners()
    {
        var copyButtons = document.querySelectorAll('.btn-copy');
        copyButtons.forEach(function (btn) {
            btn.addEventListener('click', handleCopyUrl);
        });

        var revokeButtons = document.querySelectorAll('.btn-revoke');
        revokeButtons.forEach(function (btn) {
            btn.addEventListener('click', handleRevoke);
        });
    }

    /**
     * Handle copy URL button click.
     *
     * @param {Event} e
     */
    function handleCopyUrl(e)
    {
        var btn = e.currentTarget;
        var url = btn.dataset.url;
        navigator.clipboard.writeText(url).then(function () {
            btn.textContent = 'Copied!';
            setTimeout(function () {
                btn.textContent = 'Copy URL';
            }, 2000);
        }).catch(function () {
            alert('Failed to copy URL');
        });
    }

    /**
     * Handle revoke button click.
     *
     * @param {Event} e
     */
    function handleRevoke(e)
    {
        var btn = e.currentTarget;
        var linkId = btn.dataset.linkId;
        if (!confirm('Revoke this invite link? This cannot be undone.')) {
            return;
        }
        var token = getAccessToken();
        if (!token) {
            alert('Session expired. Please log in again.');
            window.location.href = '/login';
            return;
        }
        fetchJson('/api/v1/me/invite-links/' + encodeURIComponent(linkId), {
            method: 'DELETE',
        }).then(function (result) {
            if (result.ok) {
                removeLinkCard(linkId);
            } else if (result.status === 401) {
                alert('Session expired. Please log in again.');
                window.location.href = '/login';
            } else {
                alert('Failed to revoke link: ' + (result.data.error || result.data.message || 'Unknown error'));
            }
        }).catch(function () {
            alert('Failed to revoke link: network error');
        });
    }

    /**
     * Open the create modal.
     */
    function openModal()
    {
        if (createModal) {
            createModal.style.display = 'block';
        }
        if (modalOverlay) {
            modalOverlay.style.display = 'block';
        }
        document.body.style.overflow = 'hidden';
        loadServers();
    }

    /**
     * Close the create modal.
     */
    function closeModal()
    {
        if (createModal) {
            createModal.style.display = 'none';
        }
        if (modalOverlay) {
            modalOverlay.style.display = 'none';
        }
        document.body.style.overflow = '';
        // Reset form
        if (createForm) {
            createForm.reset();
        }
        if (librarySelect) {
            librarySelect.innerHTML = '<option value="">Select a server first...</option>';
            librarySelect.disabled = true;
        }
    }

    /**
     * Load servers for the dropdown.
     */
    function loadServers()
    {
        fetchJson('/api/v1/me/servers').then(function (result) {
            if (!result.ok) {
                if (result.status === 401) {
                    window.location.href = '/login';
                }
                return;
            }
            var servers = result.data.servers || [];
            if (!serverSelect) {
                return;
            }
            serverSelect.innerHTML = '<option value="">Select a server...</option>';
            servers.forEach(function (server) {
                var opt = document.createElement('option');
                opt.value = server.id;
                opt.textContent = server.serverName || server.id;
                serverSelect.appendChild(opt);
            });
        });
    }

    /**
     * Load libraries for the selected server.
     *
     * @param {string} serverId
     */
    function loadLibraries(serverId)
    {
        if (!librarySelect) {
            return;
        }
        if (!serverId) {
            librarySelect.innerHTML = '<option value="">Select a server first...</option>';
            librarySelect.disabled = true;
            return;
        }
        librarySelect.innerHTML = '<option value="">Loading...</option>';
        librarySelect.disabled = true;
        fetchJson('/api/v1/me/libraries?server_id=' + encodeURIComponent(serverId)).then(function (result) {
            if (!result.ok) {
                librarySelect.innerHTML = '<option value="">Failed to load libraries</option>';
                return;
            }
            var libraries = result.data.libraries || [];
            librarySelect.innerHTML = '<option value="">All Libraries</option>';
            if (libraries.length === 0) {
                var hint = document.createElement('option');
                hint.value = '';
                hint.textContent = 'No libraries found (share one first)';
                hint.disabled = true;
                librarySelect.appendChild(hint);
            } else {
                libraries.forEach(function (lib) {
                    var opt = document.createElement('option');
                    opt.value = lib.id;
                    opt.textContent = lib.name || lib.id;
                    librarySelect.appendChild(opt);
                });
            }
            librarySelect.disabled = false;
        });
    }

    /**
     * Handle create form submission.
     *
     * @param {Event} e
     */
    function handleCreate(e)
    {
        e.preventDefault();
        var formData = new FormData(e.target);
        var data = {
            server_id: formData.get('server_id'),
            permission: formData.get('permission') || 'read',
            max_uses: parseInt(formData.get('max_uses'), 10) || 1,
        };
        var libraryId = formData.get('library_id');
        if (libraryId) {
            data.library_id = libraryId;
        }
        var expiresIn = formData.get('expires_in');
        if (expiresIn) {
            data.expires_in = parseInt(expiresIn, 10);
        }
        fetchJson('/api/v1/me/invite-links', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        }).then(function (result) {
            if (result.ok) {
                closeModal();
                // Reload the full list to get server/library names
                loadInviteLinks();
            } else if (result.status === 401) {
                alert('Session expired. Please log in again.');
                window.location.href = '/login';
            } else {
                alert('Failed to create invite link: ' + (result.data.error || result.data.message || 'Unknown error'));
            }
        }).catch(function () {
            alert('Failed to create invite link: network error');
        });
    }

    /**
     * Load and render the invite links list.
     */
    function loadInviteLinks()
    {
        fetchJson('/api/v1/me/invite-links').then(function (result) {
            if (!result.ok) {
                if (result.status === 401) {
                    window.location.href = '/login';
                    return;
                }
                return;
            }
            showLinks(result.data.invite_links || []);
        });
    }

    /**
     * Initialize the page.
     */
    function init()
    {
        // Event listeners for modal
        if (btnNewInvite) {
            btnNewInvite.addEventListener('click', openModal);
        }
        if (btnCloseModal) {
            btnCloseModal.addEventListener('click', closeModal);
        }
        if (btnCancel) {
            btnCancel.addEventListener('click', closeModal);
        }
        if (modalOverlay) {
            modalOverlay.addEventListener('click', closeModal);
        }
        if (createForm) {
            createForm.addEventListener('submit', handleCreate);
        }
        if (serverSelect) {
            serverSelect.addEventListener('change', function (e) {
                loadLibraries(e.target.value);
            });
        }

        // Load initial data
        loadInviteLinks();
    }

    document.addEventListener('DOMContentLoaded', init);
})();
