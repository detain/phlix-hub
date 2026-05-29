{extends file="layouts/base.tpl"}

{block name="title"}Manage Library Shares — Phlix Hub{/block}

{block name="content"}
<div class="manage-shares">
    <div class="page-header">
        <h1>Manage Library Shares</h1>
        <div class="header-actions">
            <button type="button" class="btn btn-primary" id="share-library-btn">+ Share Library</button>
            <a href="/shared-with-me" class="btn btn-secondary">View Shared With Me</a>
        </div>
    </div>

    <section class="outgoing-shares">
        <h2>Libraries I've Shared</h2>

        <div class="empty-state" id="empty-state">
            <p>You haven't shared any libraries yet.</p>
            <p>Click "+ Share Library" above to share a library with another user.</p>
        </div>

        <table class="shares-table" id="shares-table" style="display:none;">
            <thead>
                <tr>
                    <th>Library</th>
                    <th>Shared With</th>
                    <th>Permission</th>
                    <th>Shared On</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="shares-tbody">
            </tbody>
        </table>
    </section>
</div>

<!-- Share Library Modal -->
<div class="modal-overlay" id="share-modal" style="display:none;">
    <div class="modal">
        <div class="modal-header">
            <h2>Share Library</h2>
            <button type="button" class="modal-close" id="modal-close" aria-label="Close">&times;</button>
        </div>
        <form id="share-form">
            <div class="form-group">
                <label for="server-select">Server</label>
                <select id="server-select" name="server_id" required>
                    <option value="">Select a server...</option>
                </select>
            </div>
            <div class="form-group">
                <label for="library-select">Library</label>
                <select id="library-select" name="library_id" required disabled>
                    <option value="">Select a server first...</option>
                </select>
            </div>
            <div class="form-group">
                <label for="collaborator-email">Share with (email)</label>
                <input type="email" id="collaborator-email" name="collaborator_email" required
                       placeholder="user@example.com">
            </div>
            <div class="form-group">
                <label>Permission</label>
                <div class="radio-group">
                    <label class="radio-label">
                        <input type="radio" name="permission" value="read" checked>
                        Read only
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="permission" value="readwrite">
                        Read/Write
                    </label>
                </div>
            </div>
            <div class="form-group">
                <label for="expires-select">Expires</label>
                <select id="expires-select" name="expires_at">
                    <option value="">Never</option>
                    <option value="7">7 days</option>
                    <option value="30">30 days</option>
                    <option value="90">90 days</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" id="modal-cancel">Cancel</button>
                <button type="submit" class="btn btn-primary">Share</button>
            </div>
        </form>
    </div>
</div>
{/block}

{block name="scripts"}
<script src="/assets/js/manage-shares.js" defer></script>
{/block}
