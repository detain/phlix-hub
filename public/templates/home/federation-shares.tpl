{extends file="layouts/base.tpl"}

{block name="title"}Federation Library Shares — Phlix Hub{/block}

{block name="content"}
<h1>Federation Library Shares</h1>

<div class="tabs">
  <button class="tab-btn active" data-tab="incoming">Incoming</button>
  <button class="tab-btn" data-tab="outgoing">Outgoing</button>
</div>

<div id="tab-incoming">
  <div id="incoming-loading">Loading...</div>
  <table id="incoming-table" style="display:none">
    <thead><tr><th>Peer</th><th>Library</th><th>Permission</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody id="incoming-body"></tbody>
  </table>
</div>

<div id="tab-outgoing" style="display:none">
  <div id="outgoing-loading">Loading...</div>
  <table id="outgoing-table" style="display:none">
    <thead><tr><th>Library</th><th>Peer</th><th>Permission</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody id="outgoing-body"></tbody>
  </table>
</div>
{/block}

{block name="scripts"}
<style>
  .tabs { display: flex; gap: 0; margin-bottom: 1.5rem; border-bottom: 2px solid #e5e7eb; }
  .tab-btn { padding: 0.75rem 1.5rem; border: none; background: none; cursor: pointer; font: inherit; font-weight: 500; color: #6b7280; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: color 0.2s, border-color 0.2s; }
  .tab-btn:hover { color: #374151; }
  .tab-btn.active { color: #4f46e5; border-bottom-color: #4f46e5; }
  .tab-content { display: none; }
  .tab-content.active { display: block; }
  #incoming-loading, #outgoing-loading { text-align: center; padding: 2rem; color: #6b7280; }
</style>
<script src="/assets/js/federation-shares.js" type="module"></script>
{/block}
