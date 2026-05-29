/**
 * Shared Libraries — client-side interactivity.
 *
 * Fetches incoming shares from GET /api/v1/me/shares and renders library cards.
 * Browse Library buttons link to /browse/{serverId}/{libraryId}.
 */
(function () {
    'use strict';

    const LIBRARY_LIST = document.getElementById('shared-libraries-list');
    const EMPTY_STATE = document.getElementById('empty-state');

    /** Escape HTML to prevent XSS */
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /** Build a card element for a single incoming share */
    function buildCard(lib) {
        const card = document.createElement('div');
        card.className = 'shared-library-card';

        const permLabel = lib.permissionLevel === 'readwrite' ? 'Can edit' : 'Read only';
        const permClass = lib.permissionLevel === 'readwrite' ? 'readwrite' : 'read';

        card.innerHTML = `
            <div class="library-info">
                <h2>${escapeHtml(lib.libraryName || 'Unknown library')}</h2>
                <p class="owner-info">
                    Shared by <strong>${escapeHtml(lib.ownerName || 'Unknown owner')}</strong>
                    on server <strong>${escapeHtml(lib.serverName || 'Unknown server')}</strong>
                </p>
                <p class="permission-badge permission-${escapeHtml(permClass)}">
                    ${escapeHtml(permLabel)}
                </p>
            </div>
            <div class="library-actions">
                <a href="/browse/${encodeURIComponent(lib.serverId || '')}/${encodeURIComponent(lib.libraryId || '')}"
                   class="btn btn-primary">Browse Library</a>
            </div>
        `;
        return card;
    }

    /** Show empty state and hide cards list */
    function showEmpty() {
        if (EMPTY_STATE) EMPTY_STATE.style.display = '';
        const cards = LIBRARY_LIST.querySelectorAll('.shared-library-card');
        cards.forEach(function (c) { c.remove(); });
    }

    /** Fetch and render incoming shares */
    async function loadSharedLibraries() {
        try {
            const resp = await fetch('/api/v1/me/shares', { credentials: 'include' });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const data = await resp.json();
            const incoming = data.incoming || [];

            // Remove existing cards (keep empty state element)
            const cards = LIBRARY_LIST.querySelectorAll('.shared-library-card');
            cards.forEach(function (c) { c.remove(); });

            if (incoming.length === 0) {
                if (EMPTY_STATE) EMPTY_STATE.style.display = '';
                return;
            }

            if (EMPTY_STATE) EMPTY_STATE.style.display = 'none';
            incoming.forEach(function (lib) {
                LIBRARY_LIST.appendChild(buildCard(lib));
            });
        } catch (err) {
            console.error('Failed to load shared libraries:', err);
            showEmpty();
        }
    }

    // ── Bootstrap ───────────────────────────────────────────────────────────

    if (LIBRARY_LIST) {
        loadSharedLibraries();
    }
})();
