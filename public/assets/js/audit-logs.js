/**
 * Audit Logs page — client-side interactivity.
 *
 * Handles:
 * - Fetching audit logs from GET /api/v1/me/audit-logs
 * - Filtering by event, success, from/to dates
 * - Pagination (prev/next)
 * - Rendering log entries in a table
 *
 * @package Phlix\Hub
 */

const AuditLogsPage = {
    state: {
        entries: [],
        total: 0,
        limit: 50,
        offset: 0,
        loading: false,
        filters: {
            event: '',
            success: '',
            from: '',
            to: '',
        },
    },

    async init() {
        this.bindEvents();
        await this.loadLogs();
    },

    async loadLogs() {
        this.state.loading = true;
        this.showLoading(true);

        const params = this.buildParams();
        params.set('limit', String(this.state.limit));
        params.set('offset', String(this.state.offset));

        try {
            const res = await fetch(` / api / v1 / me / audit - logs ? ${params}`, {
                credentials: 'include',
            });
            if (!res.ok) {
                if (res.status === 401 || res.status === 403) {
                    window.location.href = '/login';
                    return;
                }
                throw new Error('HTTP ' + res.status);
            }
            const data = await res.json();
            this.state.entries = data.logs || [];
            this.state.total = data.total || 0;
            this.state.limit = data.limit || 50;
            this.state.offset = data.offset || 0;
        } catch (err) {
            console.error('Failed to load audit logs:', err);
            this.state.entries = [];
            this.state.total = 0;
        }

        this.state.loading = false;
        this.showLoading(false);
        this.render();
    },

    render() {
        const table = document.getElementById('logs-table');
        const tbody = document.getElementById('logs-body');
        const emptyState = document.getElementById('empty-state');
        const pagination = document.getElementById('pagination');

        if (!table || !tbody) {
            return;
        }

        if (this.state.entries.length === 0) {
            table.style.display = 'none';
            pagination.style.display = 'none';
            if (emptyState) {
                emptyState.style.display = 'block';
            }
            return;
        }

        if (emptyState) {
            emptyState.style.display = 'none';
        }
        table.style.display = '';
        pagination.style.display = '';

        tbody.innerHTML = this.state.entries.map((entry) => this.renderRow(entry)).join('');

        this.updatePagination();
    },

    renderRow(entry) {
        const time = this.formatTime(entry.created_at);
        const event = this.escapeHtml(entry.event || '');
        const userId = this.escapeHtml(entry.user_id || '—');
        const resource = this.escapeHtml(entry.resource || '—');
        const action = this.escapeHtml(entry.action || '—');
        const successIcon = entry.success ? '✅' : '❌';
        const ip = this.escapeHtml(entry.ip_address || '—');
        const details = this.truncateDetails(entry.context);

        return ` < tr >
            < td > ${time} < / td >
            < td > < span class = "event-badge" > ${event} < / span > < / td >
            < td > ${userId} < / td >
            < td > ${resource} < / td >
            < td > ${action} < / td >
            < td > ${successIcon} < / td >
            < td > ${ip} < / td >
            < td class = "details-cell" > ${details} < / td >
        <  / tr > `;
    },

    formatTime(timestamp) {
        if (!timestamp) {
            return '—';
        }
        try {
            const date = new Date(timestamp);
            if (isNaN(date.getTime())) {
                return timestamp;
            }
            return date.toLocaleString();
        } catch (_e) {
            return timestamp;
        }
    },

    truncateDetails(context) {
        if (!context) {
            return '—';
        }
        const json = JSON.stringify(context);
        if (json.length <= 50) {
            return this.escapeHtml(json);
        }
        return this.escapeHtml(json.substring(0, 50)) + '…';
    },

    escapeHtml(str) {
        if (str === null || str === undefined) {
            return '';
        }
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    },

    buildParams() {
        const params = new URLSearchParams();

        if (this.state.filters.event) {
            params.set('event', this.state.filters.event);
        }
        if (this.state.filters.success !== '') {
            params.set('success', this.state.filters.success);
        }
        if (this.state.filters.from) {
            const ts = Math.floor(new Date(this.state.filters.from).getTime() / 1000);
            if (!isNaN(ts)) {
                params.set('from', String(ts));
            }
        }
        if (this.state.filters.to) {
            const ts = Math.floor(new Date(this.state.filters.to).getTime() / 1000);
            if (!isNaN(ts)) {
                params.set('to', String(ts));
            }
        }

        return params;
    },

    showLoading(show) {
        const loading = document.getElementById('loading');
        if (loading) {
            loading.style.display = show ? 'block' : 'none';
        }
    },

    updatePagination() {
        const pageInfo = document.getElementById('page-info');
        const prevBtn = document.getElementById('prev-btn');
        const nextBtn = document.getElementById('next-btn');

        if (!pageInfo || !prevBtn || !nextBtn) {
            return;
        }

        const totalPages = Math.ceil(this.state.total / this.state.limit);
        const currentPage = Math.floor(this.state.offset / this.state.limit) + 1;

        pageInfo.textContent = `Page ${currentPage} of ${totalPages} (${this.state.total} total)`;
        prevBtn.disabled = this.state.offset <= 0;
        nextBtn.disabled = this.state.offset + this.state.limit >= this.state.total;
    },

    bindEvents() {
        const applyBtn = document.getElementById('apply-filters-btn');
        const prevBtn = document.getElementById('prev-btn');
        const nextBtn = document.getElementById('next-btn');
        const eventFilter = document.getElementById('filter-event');
        const successFilter = document.getElementById('filter-success');
        const fromFilter = document.getElementById('filter-from');
        const toFilter = document.getElementById('filter-to');

        if (applyBtn) {
            applyBtn.addEventListener('click', () => {
                this.state.filters.event = eventFilter ? eventFilter.value : '';
                this.state.filters.success = successFilter ? successFilter.value : '';
                this.state.filters.from = fromFilter ? fromFilter.value : '';
                this.state.filters.to = toFilter ? toFilter.value : '';
                this.state.offset = 0;
                this.loadLogs();
            });
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                if (this.state.offset > 0) {
                    this.state.offset = Math.max(0, this.state.offset - this.state.limit);
                    this.loadLogs();
                }
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                if (this.state.offset + this.state.limit < this.state.total) {
                    this.state.offset += this.state.limit;
                    this.loadLogs();
                }
            });
        }
    },
};

document.addEventListener('DOMContentLoaded', () => AuditLogsPage.init());
export default AuditLogsPage;
