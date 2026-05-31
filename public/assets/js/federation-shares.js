/**
 * Federation Library Shares page interactivity.
 *
 * Handles:
 * - Fetching incoming offers from GET /api/v1/me/federation/library-shares/incoming
 * - Fetching outgoing shares from GET /api/v1/me/federation/library-shares/outgoing
 * - Accepting incoming offers via POST /api/v1/me/federation/library-shares/incoming/{id}/accept
 * - Rejecting incoming offers via POST /api/v1/me/federation/library-shares/incoming/{id}/reject
 * - Revoking outgoing shares via DELETE /api/v1/me/federation/library-shares/outgoing/{id}
 *
 * @package Phlix\Hub
 */

const FederationSharesPage = {
    state: {
        activeTab: 'incoming',
        incoming: [],
        outgoing: [],
    },

    async init() {
        this.bindEvents();
        await this.loadAll();
        this.renderActiveTab();
    },

    async loadAll() {
        await Promise.all([this.loadIncoming(), this.loadOutgoing()]);
    },

    async loadIncoming() {
        const res = await fetch('/api/v1/me/federation/library-shares/incoming', { credentials: 'include' });
        if (!res.ok) {
            return;
        }
        const data = await res.json();
        this.state.incoming = data.offers ?  ? data;
    },

    async loadOutgoing() {
        const res = await fetch('/api/v1/me/federation/library-shares/outgoing', { credentials: 'include' });
        if (!res.ok) {
            return;
        }
        const data = await res.json();
        this.state.outgoing = data.shares ?  ? data;
    },

    renderActiveTab() {
        const { activeTab } = this.state;

        // Tab buttons
        document.querySelectorAll('.tab-btn').forEach((btn) => {
            btn.classList.toggle('active', btn.dataset.tab === activeTab);
        });

        // Tab panels
        const incomingPanel = document.getElementById('tab-incoming');
        const outgoingPanel = document.getElementById('tab-outgoing');

    if (incomingPanel) {
        incomingPanel.style.display = activeTab === 'incoming' ? '' : 'none';
    }
    if (outgoingPanel) {
        outgoingPanel.style.display = activeTab === 'outgoing' ? '' : 'none';
    }

    if (activeTab === 'incoming') {
        this.renderIncoming();
    } else {
        this.renderOutgoing();
    }
    },

    renderIncoming() {
        const incomingLoading = document.getElementById('incoming-loading');
        const incomingTable = document.getElementById('incoming-table');
        const incomingBody = document.getElementById('incoming-body');

        const list = Array.isArray(this.state.incoming) ? this.state.incoming : [];

        if (incomingLoading) {
            incomingLoading.style.display = 'none';
        }

        if (list.length === 0) {
            if (incomingTable) {
                incomingTable.style.display = 'none';
            }
            return;
        }

        if (incomingTable) {
            incomingTable.style.display = '';
        }

        if (incomingBody) {
            incomingBody.innerHTML = list.map((offer) => {
                const escape = this.escapeHtml;
                const peerName = escape(offer.peer_name || offer.peer_id || '');
                const libraryName = escape(offer.library_name || offer.library_id || '');
                const permission = escape(offer.permission || 'read');
                const status = escape(offer.status || 'pending');

                return '<tr>' +
                    '<td>' + peerName + '</td>' +
                    '<td>' + libraryName + '</td>' +
                    '<td><span class="permission-badge permission-' + permission + '">' + permission + '</span></td>' +
                    '<td>' + status + '</td>' +
                    '<td>' +
                    '<button type="button" class="btn btn-small btn-primary accept-offer" data-id="' + escape(offer.id) + '">Accept</button> ' +
                    '<button type="button" class="btn btn-small reject-offer" data-id="' + escape(offer.id) + '">Reject</button>' +
                    '</td>' +
                    '</tr>';
            }).join('');
        }
    },

    renderOutgoing() {
        const outgoingLoading = document.getElementById('outgoing-loading');
        const outgoingTable = document.getElementById('outgoing-table');
        const outgoingBody = document.getElementById('outgoing-body');

        const list = Array.isArray(this.state.outgoing) ? this.state.outgoing : [];

        if (outgoingLoading) {
            outgoingLoading.style.display = 'none';
        }

        if (list.length === 0) {
            if (outgoingTable) {
                outgoingTable.style.display = 'none';
            }
            return;
        }

        if (outgoingTable) {
            outgoingTable.style.display = '';
        }

        if (outgoingBody) {
            outgoingBody.innerHTML = list.map((share) => {
                const escape = this.escapeHtml;
                const libraryName = escape(share.library_name || share.library_id || '');
                const peerName = escape(share.peer_name || share.peer_id || '');
                const permission = escape(share.permission || 'read');
                const status = escape(share.status || 'pending');

                return '<tr>' +
                    '<td>' + libraryName + '</td>' +
                    '<td>' + peerName + '</td>' +
                    '<td><span class="permission-badge permission-' + permission + '">' + permission + '</span></td>' +
                    '<td>' + status + '</td>' +
                    '<td>' +
                    '<button type="button" class="btn btn-small btn-warning revoke-share" data-id="' + escape(share.id) + '">Revoke</button>' +
                    '</td>' +
                    '</tr>';
            }).join('');
        }
    },

    bindEvents() {
        // Tab switching
        document.querySelectorAll('.tab-btn').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                this.state.activeTab = e.target.dataset.tab;
                this.renderActiveTab();
            });
        });

        // Accept incoming offer
        document.querySelectorAll('.accept-offer').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                const id = e.target.dataset.id;
                if (id && confirm('Accept this library share offer?')) {
                    this.acceptOffer(id);
                }
            });
        });

        // Reject incoming offer
        document.querySelectorAll('.reject-offer').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                const id = e.target.dataset.id;
                if (id && confirm('Reject this library share offer?')) {
                    this.rejectOffer(id);
                }
            });
        });

        // Revoke outgoing share
        document.querySelectorAll('.revoke-share').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                const id = e.target.dataset.id;
                if (id && confirm('Revoke this library share? This cannot be undone.')) {
                    this.revokeShare(id);
                }
            });
        });
    },

    async acceptOffer(id) {
        if (!id) {
            return;
        }

        try {
            const res = await fetch(
                '/api/v1/me/federation/library-shares/incoming/' + encodeURIComponent(id) + '/accept',
                {
                    method: 'POST',
                    credentials: 'include',
                },
            );

            if (res.ok) {
                await this.loadIncoming();
                this.renderIncoming();
                this.bindEvents();
            } else {
                alert('Failed to accept offer');
            }
        } catch (err) {
            alert('Failed to accept offer');
        }
    },

    async rejectOffer(id) {
        if (!id) {
            return;
        }

        try {
            const res = await fetch(
                '/api/v1/me/federation/library-shares/incoming/' + encodeURIComponent(id) + '/reject',
                {
                    method: 'POST',
                    credentials: 'include',
                },
            );

            if (res.ok) {
                await this.loadIncoming();
                this.renderIncoming();
                this.bindEvents();
            } else {
                alert('Failed to reject offer');
            }
        } catch (err) {
            alert('Failed to reject offer');
        }
    },

    async revokeShare(id) {
        if (!id) {
            return;
        }

        try {
            const res = await fetch('/api/v1/me/federation/library-shares/outgoing/' + encodeURIComponent(id), {
                method: 'DELETE',
                credentials: 'include',
            });

            if (res.ok) {
                await this.loadOutgoing();
                this.renderOutgoing();
                this.bindEvents();
            } else {
                alert('Failed to revoke share');
            }
        } catch (err) {
            alert('Failed to revoke share');
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

document.addEventListener('DOMContentLoaded', () => FederationSharesPage.init());

export default FederationSharesPage;
