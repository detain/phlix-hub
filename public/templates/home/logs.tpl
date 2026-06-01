{extends file="layouts/base.tpl"}

{block name="title"}Logs — Phlix Hub{/block}

{block name="content"}
<div class="logs-page">
    <div class="page-header">
        <h1>Logs</h1>
    </div>

    <div class="logs-controls">
        <label>File
            <select id="log-file" aria-label="Log file">
                <option value="">(loading…)</option>
            </select>
        </label>
        <label>Lines
            <select id="log-lines" aria-label="Line count">
                <option value="200">200</option>
                <option value="500">500</option>
                <option value="1000">1000</option>
                <option value="2000">2000</option>
            </select>
        </label>
        <button type="button" class="btn btn-secondary btn-small" id="log-refresh">Refresh</button>
        <label class="logs-toggle">
            <input type="checkbox" id="log-auto" />
            <span>Auto-refresh (5s)</span>
        </label>
    </div>

    <p id="log-truncated" class="logs-note" style="display:none"></p>
    <pre id="log-output" class="logs-output" aria-live="polite">(loading…)</pre>
</div>
{/block}

{block name="scripts"}
<style>
    .logs-controls { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; margin-bottom: 1rem; padding: 1rem; background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; }
    .logs-controls label { display: flex; flex-direction: column; gap: 0.25rem; font-weight: 500; color: #374151; }
    .logs-controls select { padding: 0.4rem 0.6rem; border: 1px solid #d1d5db; border-radius: 4px; font: inherit; }
    .logs-toggle { flex-direction: row !important; align-items: center; gap: 0.4rem !important; }
    .logs-note { color: #92400e; background: #fef3c7; border: 1px solid #fcd34d; padding: 0.5rem 0.75rem; border-radius: 4px; font-size: 0.85rem; }
    .logs-output { background: #0f172a; color: #e2e8f0; padding: 1rem; border-radius: 6px; overflow: auto; max-height: 70vh; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.8rem; line-height: 1.45; white-space: pre; }
</style>
<script src="/assets/js/logs.js" type="module"></script>
{/block}
