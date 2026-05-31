{extends file="layouts/base.tpl"}

{block name="title"}Audit Logs — Phlix Hub{/block}

{block name="content"}
<div class="audit-logs-page">
    <div class="page-header">
        <h1>Audit Logs</h1>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
        <label>Event
            <select id="filter-event">
                <option value="">All</option>
                <option>login</option>
                <option>logout</option>
                <option>auth_failure</option>
                <option>permission_denied</option>
                <option>signup</option>
                <option>admin_action</option>
                <option>hub_connect</option>
                <option>hub_disconnect</option>
                <option>library_share_cross_hub</option>
                <option>admin_delegation</option>
            </select>
        </label>
        <label>Success
            <select id="filter-success">
                <option value="">All</option>
                <option value="1">Yes</option>
                <option value="0">No</option>
            </select>
        </label>
        <label>From <input type="datetime-local" id="filter-from" /></label>
        <label>To <input type="datetime-local" id="filter-to" /></label>
        <button type="button" class="btn btn-primary btn-small" id="apply-filters-btn">Apply</button>
    </div>

    <!-- Log Table -->
    <div id="loading" class="loading-state">Loading...</div>
    <table id="logs-table" class="shares-table" style="display:none">
        <thead>
            <tr>
                <th>Time</th>
                <th>Event</th>
                <th>User</th>
                <th>Resource</th>
                <th>Action</th>
                <th>Result</th>
                <th>IP</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody id="logs-body"></tbody>
    </table>

    <div class="empty-state" id="empty-state" style="display: none;">
        <p>No audit log entries found.</p>
    </div>

    <!-- Pagination -->
    <div id="pagination" class="pagination-bar" style="display:none">
        <button type="button" class="btn btn-secondary btn-small" id="prev-btn">Previous</button>
        <span id="page-info"></span>
        <button type="button" class="btn btn-secondary btn-small" id="next-btn">Next</button>
    </div>
</div>
{/block}

{block name="scripts"}
<style>
    .filters-bar { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; margin-bottom: 1.5rem; padding: 1rem; background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; }
    .filters-bar label { display: flex; flex-direction: column; gap: 0.25rem; font-weight: 500; color: #374151; }
    .filters-bar select, .filters-bar input { padding: 0.4rem 0.6rem; border: 1px solid #d1d5db; border-radius: 4px; font: inherit; }
    .filters-bar select:focus, .filters-bar input:focus { outline: none; border-color: #4f46e5; box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.15); }
    .loading-state { text-align: center; padding: 3rem 1rem; color: #6b7280; }
    .pagination-bar { display: flex; justify-content: center; align-items: center; gap: 1rem; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e5e7eb; }
    .pagination-bar #page-info { color: #6b7280; font-size: 0.9rem; }
</style>
<script src="/assets/js/audit-logs.js" type="module"></script>
{/block}
