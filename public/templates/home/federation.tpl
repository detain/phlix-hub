{extends file="layouts/base.tpl"}

{block name="title"}Federation — Phlix Hub{/block}

{block name="content"}
<h1>Federation</h1>

<!-- Hub Config Section -->
<h2>Hub Configuration</h2>
<div id="hub-config-loading">Loading...</div>
<div id="hub-config-content" style="display:none">
  <form id="hub-config-form">
    <div class="form-row">
      <label>Hub Role: <span id="hub-role"></span></label>
    </div>
    <div class="form-row">
      <label for="hub-url">Hub URL</label>
      <input type="url" id="hub-url" />
    </div>
    <div class="form-row">
      <label for="hub-public-key">Public Key (base64)</label>
      <textarea id="hub-public-key" readonly rows=3></textarea>
    </div>
    <div class="form-row">
      <label><input type="checkbox" id="hub-active" /> Federation Active</label>
    </div>
    <div class="form-actions">
      <button type="button" id="save-config-btn">Save</button>
      <span id="config-save-status"></span>
    </div>
  </form>
</div>

<!-- Peers Section -->
<h2>Federation Peers</h2>
<div id="peers-loading">Loading...</div>
<table id="peers-table" style="display:none">
  <thead><tr><th>Name</th><th>URL</th><th>Relay</th><th>Admin Delegation</th><th>Actions</th></tr></thead>
  <tbody id="peers-body"></tbody>
</table>
<button type="button" id="add-peer-btn">+ Add Peer</button>

<!-- Add Peer Modal -->
<div id="add-peer-modal" class="modal" style="display:none">
  <div class="modal-content">
    <h3>Add Federation Peer</h3>
    <form id="add-peer-form">
      <div class="form-row">
        <label for="peer-name">Peer Hub Name</label>
        <input type="text" id="peer-name" required />
      </div>
      <div class="form-row">
        <label for="peer-url">Peer Hub URL <span class="hint">e.g. https://peer.example.com</span></label>
        <input type="url" id="peer-url" placeholder="https://peer.example.com" required />
      </div>
      <div class="form-row">
        <label for="peer-public-key">Peer Public Key (base64)</label>
        <textarea id="peer-public-key" rows=4 required></textarea>
      </div>
      <div class="form-actions">
        <button type="submit">Add Peer</button>
        <button type="button" id="cancel-add-peer">Cancel</button>
      </div>
    </form>
  </div>
</div>
{/block}

{block name="scripts"}
<style>
  .form-row { margin-bottom: 1rem; }
  .form-row label { display: block; font-weight: 600; margin-bottom: 0.25rem; }
  .form-row input[type="url"],
  .form-row input[type="text"],
  .form-row textarea { width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; box-sizing: border-box; font: inherit; }
  .form-row textarea { resize: vertical; }
  .form-actions { display: flex; gap: 0.5rem; align-items: center; }
  .hint { font-weight: normal; color: #6b7280; font-size: 0.875rem; }
  .modal-content { background: white; border-radius: 8px; padding: 1.5rem; max-width: 480px; width: 90%; }
  .modal-content h3 { margin: 0 0 1rem; }
  #config-save-status { margin-left: 0.5rem; font-size: 0.875rem; }
  #config-save-status.success { color: #065f46; }
  #config-save-status.error { color: #c00; }
</style>
<script src="/assets/js/federation.js" type="module"></script>
{/block}
