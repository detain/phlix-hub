{extends file="layouts/base.tpl"}

{block name="title"}Invite Links — Phlix Hub{/block}

{block name="content"}
<div class="invite-links-page">
    <div class="page-header">
        <h1>Invite Links</h1>
        <button type="button" class="btn btn-primary" id="btn-new-invite">
            + New
        </button>
    </div>

    <div id="invite-links-list">
        <!-- Link cards rendered by JS -->
    </div>

    <div class="empty-state" id="empty-state" style="display: none;">
        <p>No invite links yet.</p>
        <p>Create one to share a library (or all libraries) with anyone.</p>
    </div>
</div>

<!-- Create Invite Link Modal -->
<div class="modal-overlay" id="modal-overlay" style="display: none;"></div>
<div class="modal" id="create-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal-header">
        <h2 id="modal-title">Create Invite Link</h2>
        <button type="button" class="modal-close" id="btn-close-modal" aria-label="Close">&times;</button>
    </div>
    <form id="create-form">
        <div class="form-group">
            <label for="input-server">Server</label>
            <select id="input-server" name="server_id" required>
                <option value="">Select a server...</option>
            </select>
        </div>
        <div class="form-group">
            <label for="input-library">Library</label>
            <select id="input-library" name="library_id" disabled>
                <option value="">Select a server first...</option>
            </select>
            <small class="form-hint">Select "All Libraries" to share everything on this server.</small>
        </div>
        <div class="form-group">
            <label for="input-permission">Permission</label>
            <select id="input-permission" name="permission">
                <option value="read">Read only</option>
                <option value="readwrite">Read/Write</option>
            </select>
        </div>
        <div class="form-group">
            <label for="input-max-uses">Max Uses</label>
            <input type="number" id="input-max-uses" name="max_uses" value="1" min="1" max="99" required>
        </div>
        <div class="form-group">
            <label for="input-expires">Expires In</label>
            <select id="input-expires" name="expires_in">
                <option value="604800">7 days</option>
                <option value="2592000">30 days</option>
                <option value="7776000">90 days</option>
                <option value="31536000">1 year</option>
                <option value="">Never</option>
            </select>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-secondary" id="btn-cancel">Cancel</button>
            <button type="submit" class="btn btn-primary" id="btn-create">Create</button>
        </div>
    </form>
</div>
{/block}

{block name="scripts"}
<script src="/assets/js/invite-links.js" defer></script>
{/block}
