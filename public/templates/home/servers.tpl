{**
 * Server detail page (H.3).
 *
 * Fetches server detail, active relay session, and heartbeat history from
 * `GET /api/v1/me/servers/{id}` client-side.
 *
 * @package Phlix\Hub
 *}
{extends file="layouts/base.tpl"}

{block name="title"}Server Detail — Phlix Hub{/block}

{block name="content"}
<div class="server-detail-page">
    <div class="page-header">
        <h1 id="server-page-title">Loading…</h1>
        <a href="/my-servers" class="btn btn-secondary">Back to My Servers</a>
    </div>

    <div id="server-error" class="error" style="display:none;"></div>

    {* ── Server Info ── *}
    <section class="detail-section" id="server-info-section">
        <h2>Server Info</h2>
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">Status</span>
                <span id="server-status" class="info-value"></span>
            </div>
            <div class="info-item">
                <span class="info-label">Version</span>
                <span id="server-version" class="info-value"></span>
            </div>
            <div class="info-item">
                <span class="info-label">Last Seen</span>
                <span id="server-last-seen" class="info-value"></span>
            </div>
        </div>

        <div id="server-hostnames" class="hostname-list" style="display:none;">
            <span class="info-label">Hostname Candidates</span>
            <div id="hostname-candidates"></div>
        </div>
    </section>

    {* ── Relay Session ── *}
    <section class="detail-section" id="relay-session-section">
        <h2>Relay Session</h2>
        <div id="relay-session-active" style="display:none;">
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Worker Node</span>
                    <span id="relay-worker-node" class="info-value"></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Opened At</span>
                    <span id="relay-opened-at" class="info-value"></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Bytes In</span>
                    <span id="relay-bytes-in" class="info-value"></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Bytes Out</span>
                    <span id="relay-bytes-out" class="info-value"></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Last Frame</span>
                    <span id="relay-last-frame" class="info-value"></span>
                </div>
            </div>
        </div>
        <div id="relay-session-empty" class="empty-state" style="display:none;">
            <p>No active relay session.</p>
        </div>
    </section>

    {* ── Heartbeat History ── *}
    <section class="detail-section" id="heartbeat-history-section">
        <h2>
            Heartbeat History
            <button type="button" id="heartbeat-toggle" class="btn btn-small btn-secondary"
                    aria-expanded="false">Show</button>
        </h2>
        <div id="heartbeat-list-container" style="display:none;">
            <table class="heartbeat-table">
                <thead>
                    <tr>
                        <th>Received At</th>
                        <th>Version</th>
                        <th>Uptime</th>
                        <th>Sessions</th>
                        <th>Transcodes</th>
                    </tr>
                </thead>
                <tbody id="heartbeat-tbody">
                </tbody>
            </table>
        </div>
        <div id="heartbeat-empty" class="empty-state" style="display:none;">
            <p>No heartbeat history available.</p>
        </div>
    </section>
</div>
{/block}

{block name="scripts"}
<script src="/assets/js/servers.js" defer></script>
{/block}
