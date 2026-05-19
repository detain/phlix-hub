{extends file="layouts/base.tpl"}

{block name="title"}Request Media — Phlex Hub{/block}

{block name="content"}
<div class="requests-page">
    <div class="page-header">
        <h1>Request Media</h1>
        <p>Submit a request for a movie or TV series to be added to your library.</p>
    </div>

    <section class="request-form-section">
        <h2>New Request</h2>
        <form id="new-request-form">
            <label for="req-type">Type</label>
            <select id="req-type" name="type">
                <option value="movie">Movie</option>
                <option value="series">TV Series</option>
            </select>

            <label for="req-tmdb-id">TMDB ID</label>
            <input type="number" id="req-tmdb-id" name="tmdb_id" required min="1">

            <label for="req-title">Title</label>
            <input type="text" id="req-title" name="title" required>

            <label for="req-poster-url">Poster URL (optional)</label>
            <input type="text" id="req-poster-url" name="poster_url">

            <button type="submit" class="btn btn-primary">Submit Request</button>
        </form>
    </section>

    <section class="my-requests" style="margin-top: 2rem;">
        <h2>My Requests</h2>
        <div id="my-requests-list">Loading&hellip;</div>
    </section>
</div>
{/block}

{block name="scripts"}
<script>
(function () {
    'use strict';

    async function loadMyRequests() {
        const target = document.getElementById('my-requests-list');
        try {
            const response = await fetch('/api/v1/me/requests', {
                headers: {Accept: 'application/json'},
                credentials: 'include',
            });
            if (!response.ok) {
                target.innerHTML = '<p class="error">Failed to load your requests.</p>';
                return;
            }
            const data = await response.json();
            if (!data.requests || data.requests.length === 0) {
                target.innerHTML = '<p class="empty-state">You have not submitted any requests yet.</p>';
                return;
            }
            target.innerHTML = data.requests.map(function (req) {
                return '<div class="request-card" style="border:1px solid #ddd;padding:1rem;border-radius:6px;margin-bottom:0.75rem;">'
                    + '<strong>' + escapeHtml(req.title) + '</strong>'
                    + ' <span class="badge">' + escapeHtml(req.type) + '</span>'
                    + ' <span class="status">' + escapeHtml(req.status) + '</span>'
                    + '<br><button class="btn-delete" data-id="' + encodeURIComponent(req.id) + '" type="button">Delete</button>'
                    + '</div>';
            }).join('');
            target.querySelectorAll('.btn-delete').forEach(function (btn) {
                btn.addEventListener('click', function () { deleteRequest(btn.dataset.id); });
            });
        } catch (err) {
            target.innerHTML = '<p class="error">Failed to load your requests.</p>';
        }
    }

    async function deleteRequest(id) {
        if (!confirm('Delete this request?')) return;
        try {
            const response = await fetch('/api/v1/me/requests/' + encodeURIComponent(id), {
                method: 'DELETE',
                credentials: 'include',
            });
            if (response.ok) {
                loadMyRequests();
            } else {
                alert('Failed to delete request.');
            }
        } catch (err) {
            alert('Failed to delete request.');
        }
    }

    document.getElementById('new-request-form').addEventListener('submit', async function (e) {
        e.preventDefault();
        const payload = {
            type: document.getElementById('req-type').value,
            tmdb_id: parseInt(document.getElementById('req-tmdb-id').value, 10),
            title: document.getElementById('req-title').value.trim(),
            poster_url: document.getElementById('req-poster-url').value.trim() || null,
        };
        try {
            const response = await fetch('/api/v1/me/requests', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', Accept: 'application/json'},
                credentials: 'include',
                body: JSON.stringify(payload),
            });
            if (response.ok) {
                e.target.reset();
                loadMyRequests();
            } else {
                const data = await response.json().catch(function () { return {}; });
                alert('Failed: ' + (data.message || data.error || 'Unknown error'));
            }
        } catch (err) {
            alert('Failed to submit request.');
        }
    });

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    loadMyRequests();
}());
</script>
{/block}
