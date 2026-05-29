/**
 * Manage Library Shares — client-side interactivity.
 *
 * Handles:
 * - Loading and rendering outgoing shares table
 * - Share Library modal open/close
 * - Server → library dropdown chaining
 * - Share creation via POST /api/v1/me/shares
 * - Inline permission editing via PATCH
 * - Share revocation via DELETE
 */
(function () {
    'use strict';

    const TABLE_BODY = document.getElementById('shares-tbody');
    const EMPTY_STATE = document.getElementById('empty-state');
    const SHARES_TABLE = document.getElementById('shares-table');
    const SHARE_BTN = document.getElementById('share-library-btn');
    const MODAL = document.getElementById('share-modal');
    const MODAL_CLOSE = document.getElementById('modal-close');
    const MODAL_CANCEL = document.getElementById('modal-cancel');
    const SHARE_FORM = document.getElementById('share-form');
    const SERVER_SELECT = document.getElementById('server-select');
    const LIBRARY_SELECT = document.getElementById('library-select');

    /** Show the shares table, hide empty state */
    function showTable() {
        if (EMPTY_STATE) EMPTY_STATE.style.display = 'none';
        if (SHARES_TABLE) SHARES_TABLE.style.display = '';
    }

    /** Show empty state, hide table */
    function showEmpty() {
        if (SHARES_TABLE) SHARES_TABLE.style.display = 'none';
        if (EMPTY_STATE) EMPTY_STATE.style.display = '';
    }

    /** Escape HTML to prevent XSS */
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /** Format a Unix timestamp as YYYY-MM-DD */
    function formatDate(timestamp) {
        if (!timestamp) return '';
        const d = new Date(timestamp * 1000);
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    /** Build a table row for a single share */
    function buildRow(share) {
        const tr = document.createElement('tr');
        tr.dataset.shareId = share.id;

        const permLabel = share.permission_level === 'readwrite' ? 'Read/Write' : 'Read only';
        const permClass = share.permission_level === 'readwrite' ? 'readwrite' : 'read';

        tr.innerHTML = `
            <td>
                <strong>${escapeHtml(share.library_name)}</strong>
                <br><small>on ${escapeHtml(share.server_name || 'Unknown server')}</small>
            </td>
            <td>${escapeHtml(share.collaborator_email)}</td>
            <td>
                <select class="permission-select" data-share-id="${escapeHtml(share.id)}">
                    <option value="read" ${share.permission_level === 'read' ? 'selected' : ''}>Read only</option>
                    <option value="readwrite" ${share.permission_level === 'readwrite' ? 'selected' : ''}>Read/Write</option>
                </select>
            </td>
            <td>${formatDate(share.created_at)}</td>
            <td class="actions">
                <button type="button" class="btn btn-small btn-warning revoke-share"
                        data-share-id="${escapeHtml(share.id)}">
                    Revoke
                </button>
            </td>
        `;
        return tr;
    }

    /** Prepend a new row to the table with fade-in animation */
    function prependRow(share) {
        const row = buildRow(share);
        row.style.opacity = '0';
        row.style.transform = 'translateY(-10px)';
        TABLE_BODY.insertBefore(row, TABLE_BODY.firstChild);
        requestAnimationFrame(() => {
            row.style.transition = 'opacity 0.3s, transform 0.3s';
            row.style.opacity = '1';
            row.style.transform = 'translateY(0)';
        });
    }

    /** Remove a row with fade-out animation */
    function removeRow(shareId) {
        const row = TABLE_BODY.querySelector(`tr[data-share-id="${CSS.escape(shareId)}"]`);
        if (row) {
            row.style.transition = 'opacity 0.3s';
            row.style.opacity = '0';
            setTimeout(() => row.remove(), 300);
        }
    }

    /** Update the permission select after a successful PATCH */
    function updatePermission(shareId, newLevel) {
        const row = TABLE_BODY.querySelector(`tr[data-share-id="${CSS.escape(shareId)}"]`);
        if (!row) return;
        const select = row.querySelector('.permission-select');
        if (select) {
            select.value = newLevel;
        }
    }

    /** Fetch and render the shares table */
    async function loadShares() {
        try {
            const resp = await fetch('/api/v1/me/shares', { credentials: 'include' });
            if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
            const data = await resp.json();
            const outgoing = data.outgoing || [];

            if (outgoing.length === 0) {
                showEmpty();
                return;
            }

            showTable();
            TABLE_BODY.innerHTML = '';
            outgoing.forEach(function (share) {
                TABLE_BODY.appendChild(buildRow(share));
            });
        } catch (err) {
            console.error('Failed to load shares:', err);
            showEmpty();
        }
    }

    /** Populate the server dropdown */
    async function loadServers() {
        try {
            const resp = await fetch('/api/v1/me/servers', { credentials: 'include' });
            if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
            const data = await resp.json();
            const servers = data.servers || [];

            SERVER_SELECT.innerHTML = '<option value="">Select a server...</option>';
            servers.forEach(function (srv) {
                const opt = document.createElement('option');
                opt.value = srv.id;
                opt.textContent = srv.name || srv.hostname || srv.id;
                SERVER_SELECT.appendChild(opt);
            });
        } catch (err) {
            console.error('Failed to load servers:', err);
        }
    }

    /** Populate the library dropdown based on selected server */
    async function loadLibraries(serverId) {
        LIBRARY_SELECT.disabled = true;
        LIBRARY_SELECT.innerHTML = '<option value="">Loading...</option>';

        if (!serverId) {
            LIBRARY_SELECT.innerHTML = '<option value="">Select a server first...</option>';
            return;
        }

        try {
            const resp = await fetch(`/api/v1/me/libraries?server_id=${encodeURIComponent(serverId)}`, {
                credentials: 'include',
            });
            if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
            const data = await resp.json();
            const libraries = data.libraries || [];

            LIBRARY_SELECT.innerHTML = '<option value="">Select a library...</option>';
            libraries.forEach(function (lib) {
                const opt = document.createElement('option');
                opt.value = lib.id;
                opt.textContent = lib.name || lib.library_name || lib.id;
                LIBRARY_SELECT.appendChild(opt);
            });
            LIBRARY_SELECT.disabled = false;
        } catch (err) {
            console.error('Failed to load libraries:', err);
            LIBRARY_SELECT.innerHTML = '<option value="">Failed to load libraries</option>';
        }
    }

    /** Open the modal and load initial data */
    function openModal() {
        if (MODAL) MODAL.style.display = '';
        loadServers();
        if (SHARE_FORM) SHARE_FORM.reset();
        if (SERVER_SELECT) SERVER_SELECT.value = '';
        if (LIBRARY_SELECT) {
            LIBRARY_SELECT.innerHTML = '<option value="">Select a server first...</option>';
            LIBRARY_SELECT.disabled = true;
        }
    }

    /** Close the modal */
    function closeModal() {
        if (MODAL) MODAL.style.display = 'none';
    }

    /** Submit the share form */
    async function submitShare(e) {
        e.preventDefault();

        const formData = new FormData(e.target);
        const payload = {
            server_id: formData.get('server_id'),
            library_id: formData.get('library_id'),
            collaborator_email: formData.get('collaborator_email'),
            permission: formData.get('permission') || 'read',
        };

        const expiresVal = formData.get('expires_at');
        if (expiresVal) {
            const days = parseInt(expiresVal, 10);
            if (days > 0) {
                payload.expires_at = Math.floor(Date.now() / 1000) + (days * 86400);
            }
        }

        try {
            const resp = await fetch('/api/v1/me/shares', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });

            if (!resp.ok) {
                const err = await resp.json().catch(() => ({ error: 'Share failed' }));
                alert(err.error || 'Failed to share library');
                return;
            }

            const data = await resp.json();
            closeModal();
            showTable();
            prependRow(data.share);
        } catch (err) {
            console.error('Share failed:', err);
            alert('Failed to share library. Please try again.');
        }
    }

    /** Revoke a share */
    async function revokeShare(shareId) {
        if (!confirm('Are you sure you want to revoke this share?')) return;

        try {
            const resp = await fetch(`/api/v1/me/shares/${encodeURIComponent(shareId)}`, {
                method: 'DELETE',
                credentials: 'include',
            });

            if (!resp.ok && resp.status !== 204) {
                const err = await resp.json().catch(() => ({ error: 'Revoke failed' }));
                alert(err.error || 'Failed to revoke share');
                return;
            }

            removeRow(shareId);

            // Check if table is now empty
            if (TABLE_BODY && TABLE_BODY.children.length === 0) {
                showEmpty();
            }
        } catch (err) {
            console.error('Revoke failed:', err);
            alert('Failed to revoke share. Please try again.');
        }
    }

    /** Update permission via PATCH */
    async function updatePermissionLevel(shareId, newLevel) {
        try {
            const resp = await fetch(`/api/v1/me/shares/${encodeURIComponent(shareId)}`, {
                method: 'PATCH',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ permission: newLevel }),
            });

            if (!resp.ok) {
                const err = await resp.json().catch(() => ({ error: 'Update failed' }));
                alert(err.error || 'Failed to update permission');
                return;
            }

            const data = await resp.json();
            if (data.share) {
                updatePermission(shareId, data.share.permission_level);
            }
        } catch (err) {
            console.error('Permission update failed:', err);
            alert('Failed to update permission. Please try again.');
        }
    }

    // ── Event listeners ────────────────────────────────────────────────────────

    if (SHARE_BTN) {
        SHARE_BTN.addEventListener('click', openModal);
    }

    if (MODAL_CLOSE) {
        MODAL_CLOSE.addEventListener('click', closeModal);
    }

    if (MODAL_CANCEL) {
        MODAL_CANCEL.addEventListener('click', closeModal);
    }

    if (MODAL) {
        MODAL.addEventListener('click', function (e) {
            if (e.target === MODAL) closeModal();
        });
    }

    if (SERVER_SELECT) {
        SERVER_SELECT.addEventListener('change', function () {
            loadLibraries(this.value);
        });
    }

    if (SHARE_FORM) {
        SHARE_FORM.addEventListener('submit', submitShare);
    }

    // Delegate events for dynamically-added rows
    if (TABLE_BODY) {
        TABLE_BODY.addEventListener('click', function (e) {
            const btn = e.target.closest('.revoke-share');
            if (btn) {
                const shareId = btn.dataset.shareId;
                if (shareId) revokeShare(shareId);
            }
        });

        TABLE_BODY.addEventListener('change', function (e) {
            const sel = e.target.closest('.permission-select');
            if (sel) {
                const shareId = sel.dataset.shareId;
                const newLevel = sel.value;
                if (shareId && newLevel) {
                    updatePermissionLevel(shareId, newLevel);
                }
            }
        });
    }

    // ── Bootstrap ───────────────────────────────────────────────────────────

    loadShares();
})();
