{extends file="layouts/base.tpl"}

{block name="title"}Admin: Request Queue — Phlix Hub{/block}

{block name="content"}
<div class="admin-requests-page">
    <div class="page-header">
        <h1>Pending Requests</h1>
        <p>Approve or deny incoming media requests. Approving will dispatch the
           title to your configured Sonarr/Radarr instance.</p>
    </div>

    <section class="pending-requests">
        <div id="admin-requests-list">Loading&hellip;</div>
    </section>
</div>
{/block}

{block name="scripts"}
{* Smarty treats `{...}` as a tag, which breaks JS object literals below. *}
{literal}
<script>
(function () {
    'use strict';

    async function loadAdminRequests() {
        const target = document.getElementById('admin-requests-list');
        try {
            const response = await fetch('/api/v1/admin/requests', {
                headers: {Accept: 'application/json'},
                credentials: 'include',
            });
            if (response.status === 403) {
                target.innerHTML = '<p class="error">You do not have admin access.</p>';
                return;
            }
            if (!response.ok) {
                target.innerHTML = '<p class="error">Failed to load requests.</p>';
                return;
            }
            const data = await response.json();
            if (!data.requests || data.requests.length === 0) {
                target.innerHTML = '<p class="empty-state">No pending requests.</p>';
                return;
            }
            target.innerHTML = data.requests.map(function (req) {
                return '<div class="admin-request-card" style="border:1px solid #ddd;padding:1rem;border-radius:6px;margin-bottom:0.75rem;">'
                    + '<strong>' + escapeHtml(req.title) + '</strong>'
                    + ' <span class="badge">' + escapeHtml(req.type) + '</span>'
                    + ' <span class="status">' + escapeHtml(req.status) + '</span>'
                    + '<br><small>User: ' + escapeHtml(req.user_id) + ' &middot; TMDB: ' + escapeHtml(String(req.tmdb_id)) + '</small>'
                    + '<br><button class="btn btn-approve" data-id="' + encodeURIComponent(req.id) + '" type="button">Approve</button>'
                    + ' <button class="btn btn-deny" data-id="' + encodeURIComponent(req.id) + '" type="button">Deny</button>'
                    + '</div>';
            }).join('');
            target.querySelectorAll('.btn-approve').forEach(function (btn) {
                btn.addEventListener('click', function () { approveRequest(btn.dataset.id); });
            });
            target.querySelectorAll('.btn-deny').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const reason = prompt('Reason (optional):') || '';
                    denyRequest(btn.dataset.id, reason);
                });
            });
        } catch (err) {
            target.innerHTML = '<p class="error">Failed to load requests.</p>';
        }
    }

    async function approveRequest(id) {
        try {
            const response = await fetch('/api/v1/admin/requests/' + encodeURIComponent(id) + '/approve', {
                method: 'POST',
                headers: {Accept: 'application/json'},
                credentials: 'include',
            });
            if (response.ok) {
                loadAdminRequests();
            } else {
                const data = await response.json().catch(function () { return {}; });
                alert('Approve failed: ' + (data.message || data.error || 'Unknown'));
            }
        } catch (err) {
            alert('Approve failed.');
        }
    }

    async function denyRequest(id, reason) {
        try {
            const response = await fetch('/api/v1/admin/requests/' + encodeURIComponent(id) + '/deny', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', Accept: 'application/json'},
                credentials: 'include',
                body: JSON.stringify({reason: reason}),
            });
            if (response.ok) {
                loadAdminRequests();
            } else {
                alert('Deny failed.');
            }
        } catch (err) {
            alert('Deny failed.');
        }
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    loadAdminRequests();
}());
</script>
{/literal}
{/block}
