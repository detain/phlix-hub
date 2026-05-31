/**
 * Federation page interactivity.
 *
 * Handles:
 * - Fetching hub config from GET /api/v1/me/federation/hub-config
 * - Fetching peers from GET /api/v1/me/federation/peers
 * - Saving config via PUT /api/v1/me/federation/hub-config
 * - Adding peers via POST /api/v1/me/federation/peers
 * - Removing peers via DELETE /api/v1/me/federation/peers/{id}
 * - Toggling relay via PUT /api/v1/me/federation/peers/{id}/relay
 * - Toggling admin delegation via PUT /api/v1/me/federation/peers/{id}/admin-delegation
 *
 * @package Phlix\Hub
 */

const FederationPage = {
    state: { hubConfig: null, peers: [] },

    async init() {
        await Promise.all([this.loadHubConfig(), this.loadPeers()]);
        this.render();
        this.bindEvents();
    },

    async loadHubConfig() {
        const res = await fetch('/api/v1/me/federation/hub-config', { credentials: 'include' });
        if (!res.ok) {
            return;
        }
        this.state.hubConfig = await res.json();
    },

    async loadPeers() {
        const res = await fetch('/api/v1/me/federation/peers', { credentials: 'include' });
        if (!res.ok) {
            return;
        }
        const data = await res.json();
        this.state.peers = data.peers ?  ? data;
    },

    render() {
        const { hubConfig, peers } = this.state;
        const loadingEl = document.getElementById('hub-config-loading');
        const contentEl = document.getElementById('hub-config-content');
        const peersLoadingEl = document.getElementById('peers-loading');
        const peersTableEl = document.getElementById('peers-table');

        if (!hubConfig) {
            return;
        }

        // Show config form
        if (loadingEl) {
            loadingEl.style.display = 'none';
        }
        if (contentEl) {
            contentEl.style.display = '';
        }

        const role = hubConfig.role || 'leaf';
        const roleEl = document.getElementById('hub-role');
        if (roleEl) {
            roleEl.textContent = role.charAt(0).toUpperCase() + role.slice(1);
        }

        const urlEl = document.getElementById('hub-url');
        if (urlEl) {
            urlEl.value = hubConfig.url || '';
        }

        const keyEl = document.getElementById('hub-public-key');
        if (keyEl) {
            keyEl.value = hubConfig.public_key || hubConfig.publicKey || '';
        }

        const activeEl = document.getElementById('hub-active');
        if (activeEl) {
            activeEl.checked = Boolean(hubConfig.is_active);
        }

        // Render peers table
        if (peersLoadingEl) {
            peersLoadingEl.style.display = 'none';
        }

        const peerList = Array.isArray(peers) ? peers : [];
        if (peerList.length === 0) {
            if (peersTableEl) {
                peersTableEl.style.display = 'none';
            }
            return;
        }

        if (peersTableEl) {
            peersTableEl.style.display = '';
        }

        const tbody = document.getElementById('peers-body');
        if (tbody) {
            tbody.innerHTML = peerList.map((peer) => {
                const escape = this.escapeHtml;
                const peerName = escape(peer.name || peer.id || '');
                const peerUrl = escape(peer.url || '');
                const relayEnabled = peer.relay_enabled ? 'checked' : '';
                const adminEnabled = peer.admin_delegation_enabled ? 'checked' : '';
                const status = escape(peer.status || 'pending');

                return '<tr>' +
                    '<td>' + peerName + '</td>' +
                    '<td>' + peerUrl + '</td>' +
                    '<td><input type="checkbox" class="toggle-relay" data-id="' + escape(peer.id) + '" ' + relayEnabled + '></td>' +
                    '<td><input type="checkbox" class="toggle-admin" data-id="' + escape(peer.id) + '" ' + adminEnabled + '></td>' +
                    '<td><button type="button" class="btn btn-small remove-peer" data-id="' + escape(peer.id) + '">Remove</button></td>' +
                    '</tr>';
            }).join('');
        }
    },

    bindEvents() {
        const saveBtn = document.getElementById('save-config-btn');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.saveConfig());
        }

        const addPeerBtn = document.getElementById('add-peer-btn');
        if (addPeerBtn) {
            addPeerBtn.addEventListener('click', () => this.showAddPeerModal());
        }

        const cancelBtn = document.getElementById('cancel-add-peer');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => this.hideAddPeerModal());
        }

        const form = document.getElementById('add-peer-form');
        if (form) {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.addPeer();
            });
        }

        // Relay toggles
        document.querySelectorAll('.toggle-relay').forEach((cb) => {
            cb.addEventListener('change', (e) => {
                this.toggleRelay(e.target.dataset.id, e.target.checked);
            });
        });

        // Admin delegation toggles
        document.querySelectorAll('.toggle-admin').forEach((cb) => {
            cb.addEventListener('change', (e) => {
                this.toggleAdmin(e.target.dataset.id, e.target.checked);
            });
        });

        // Remove peer buttons
        document.querySelectorAll('.remove-peer').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                const id = e.target.dataset.id;
                if (id && confirm('Remove this peer? This cannot be undone.')) {
                    this.removePeer(id);
                }
            });
        });
    },

    async saveConfig() {
        const urlEl = document.getElementById('hub-url');
        const activeEl = document.getElementById('hub-active');
        const statusEl = document.getElementById('config-save-status');

        const url = urlEl ? urlEl.value : '';
        const is_active = activeEl ? activeEl.checked : false;

        try {
            const res = await fetch('/api/v1/me/federation/hub-config', {
                method: 'PUT',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ url, is_active }),
            });

            if (statusEl) {
                if (res.ok) {
                    statusEl.textContent = 'Saved';
                    statusEl.className = 'success';
                } else {
                    statusEl.textContent = 'Failed to save';
                    statusEl.className = 'error';
                }
            }
        } catch (err) {
            if (statusEl) {
                statusEl.textContent = 'Failed to save';
                statusEl.className = 'error';
            }
        }
    },

    showAddPeerModal() {
        const modal = document.getElementById('add-peer-modal');
        if (modal) {
            modal.style.display = 'block';
        }
    },

    hideAddPeerModal() {
        const modal = document.getElementById('add-peer-modal');
        if (modal) {
            modal.style.display = 'none';
        }
        const form = document.getElementById('add-peer-form');
        if (form) {
            form.reset();
        }
    },

    async addPeer() {
        const nameEl = document.getElementById('peer-name');
        const urlEl = document.getElementById('peer-url');
        const keyEl = document.getElementById('peer-public-key');

        const name = nameEl ? nameEl.value.trim() : '';
        const peerUrl = urlEl ? urlEl.value.trim() : '';
        const publicKey = keyEl ? keyEl.value.trim() : '';

        if (!name || !peerUrl || !publicKey) {
            return;
        }

        try {
            const res = await fetch('/api/v1/me/federation/peers', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: name,
                    url: peerUrl,
                    public_key: publicKey,
                }),
            });

            if (res.ok) {
                this.hideAddPeerModal();
                await this.loadPeers();
                this.render();
                this.bindEvents();
            } else {
                alert('Failed to add peer');
            }
        } catch (err) {
            alert('Failed to add peer');
        }
    },

    async removePeer(id) {
        if (!id) {
            return;
        }

        try {
            const res = await fetch('/api/v1/me/federation/peers/' + encodeURIComponent(id), {
                method: 'DELETE',
                credentials: 'include',
            });

            if (res.ok) {
                await this.loadPeers();
                this.render();
                this.bindEvents();
            } else {
                alert('Failed to remove peer');
            }
        } catch (err) {
            alert('Failed to remove peer');
        }
    },

    async toggleRelay(id, enabled) {
        if (!id) {
            return;
        }

        try {
            await fetch('/api/v1/me/federation/peers/' + encodeURIComponent(id) + '/relay', {
                method: 'PUT',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ enabled: Boolean(enabled) }),
            });
        } catch (err) {
            // Silently fail; checkbox state is already committed visually
        }
    },

    async toggleAdmin(id, enabled) {
        if (!id) {
            return;
        }

        try {
            await fetch('/api/v1/me/federation/peers/' + encodeURIComponent(id) + '/admin-delegation', {
                method: 'PUT',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ enabled: Boolean(enabled) }),
            });
        } catch (err) {
            // Silently fail; checkbox state is already committed visually
        }
    },

    escapeHtml(str) {
        if (str === null || str === undefined) {
            return '';
        }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    },
};

document.addEventListener('DOMContentLoaded', () => FederationPage.init());

export default FederationPage;
