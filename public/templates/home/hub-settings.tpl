{extends file="layouts/base.tpl"}

{block name="title"}Hub Settings — Phlix Hub{/block}

{block name="content"}
<div class="hub-settings-page">
    <div class="page-header">
        <h1>Hub Settings</h1>
    </div>

    <div id="hub-settings-loading" class="loading-state">
        <p>Loading settings…</p>
    </div>

    <div id="hub-settings-content" style="display:none;">
        <form id="hub-settings-form">
            <section class="settings-section">
                <h2>Server Settings</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="input-enrollment_ttl">
                            Enrollment TTL
                            <span class="field-badge overridden-badge" id="badge-server.enrollment_ttl" style="display:none;">overridden</span>
                        </label>
                        <input type="number" id="input-enrollment_ttl" name="server.enrollment_ttl"
                               min="60" step="1" placeholder="e.g. 3600">
                        <small class="form-hint">How long a server claim code remains valid (seconds).</small>
                    </div>
                    <div class="form-group">
                        <label for="input-relay_ping_interval">
                            Relay Ping Interval
                            <span class="field-badge overridden-badge" id="badge-server.relay_ping_interval" style="display:none;">overridden</span>
                        </label>
                        <input type="number" id="input-relay_ping_interval" name="server.relay_ping_interval"
                               min="5" step="1" placeholder="e.g. 30">
                        <small class="form-hint">Heartbeat interval between hub and servers (seconds).</small>
                    </div>
                    <div class="form-group">
                        <label for="input-max_servers_per_user">
                            Max Servers Per User
                            <span class="field-badge overridden-badge" id="badge-server.max_servers_per_user" style="display:none;">overridden</span>
                        </label>
                        <input type="number" id="input-max_servers_per_user" name="server.max_servers_per_user"
                               min="1" step="1" placeholder="e.g. 10">
                        <small class="form-hint">Maximum servers a single user can claim.</small>
                    </div>
                    <div class="form-group">
                        <label for="input-public_domain">
                            Public Domain
                            <span class="field-badge overridden-badge" id="badge-server.public_domain" style="display:none;">overridden</span>
                        </label>
                        <input type="text" id="input-public_domain" name="server.public_domain"
                               placeholder="e.g. phlix.media">
                        <small class="form-hint">Domain used for server subdomains and FQDNs.</small>
                    </div>
                </div>
            </section>

            <section class="settings-section">
                <h2>Authentication</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="input-access_token_ttl">
                            Access Token TTL
                            <span class="field-badge overridden-badge" id="badge-auth.access_token_ttl" style="display:none;">overridden</span>
                        </label>
                        <input type="number" id="input-access_token_ttl" name="auth.access_token_ttl"
                               min="60" step="1" placeholder="e.g. 3600">
                        <small class="form-hint">Access token lifetime (seconds).</small>
                    </div>
                    <div class="form-group">
                        <label for="input-refresh_token_ttl">
                            Refresh Token TTL
                            <span class="field-badge overridden-badge" id="badge-auth.refresh_token_ttl" style="display:none;">overridden</span>
                        </label>
                        <input type="number" id="input-refresh_token_ttl" name="auth.refresh_token_ttl"
                               min="60" step="1" placeholder="e.g. 604800">
                        <small class="form-hint">Refresh token lifetime (seconds).</small>
                    </div>
                </div>
            </section>

            <section class="settings-section">
                <h2>Logging</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="input-logger_level">
                            Log Level
                            <span class="field-badge overridden-badge" id="badge-logger.level" style="display:none;">overridden</span>
                        </label>
                        <select id="input-logger_level" name="logger.level">
                            <option value="debug">debug</option>
                            <option value="info">info</option>
                            <option value="warning">warning</option>
                            <option value="error">error</option>
                            <option value="fatal">fatal</option>
                        </select>
                        <small class="form-hint">Minimum log level to record.</small>
                    </div>
                    <div class="form-group">
                        <label for="input-logger_channels">
                            Log Channels
                            <span class="field-badge overridden-badge" id="badge-logger.channels" style="display:none;">overridden</span>
                        </label>
                        <textarea id="input-logger_channels" name="logger.channels" rows="3"
                                  placeholder='["hub", "relay", "http"]'></textarea>
                        <small class="form-hint">JSON array of enabled log channels.</small>
                    </div>
                </div>
            </section>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" id="btn-reset">Reset to Defaults</button>
                <button type="submit" class="btn btn-primary" id="btn-save">Save Changes</button>
            </div>
        </form>

        <div id="hub-settings-message" class="settings-message" style="display:none;"></div>
    </div>
</div>
{/block}

{block name="scripts"}
<style>
    .settings-section { margin-bottom: 2rem; }
    .settings-section h2 { font-size: 1.1rem; margin-bottom: 1rem; color: #374151; border-bottom: 1px solid #e5e7eb; padding-bottom: 0.5rem; }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
    .form-group { display: flex; flex-direction: column; gap: 0.25rem; }
    .form-group label { font-weight: 600; color: #374151; display: flex; align-items: center; gap: 0.5rem; }
    .form-group input, .form-group select, .form-group textarea { padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px; font: inherit; }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #4f46e5; box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.15); }
    .form-hint { color: #6b7280; font-size: 0.8rem; }
    .form-actions { display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e5e7eb; }
    .overridden-badge { font-size: 0.7rem; padding: 0.15rem 0.4rem; background: #fef3c7; color: #92400e; border-radius: 3px; font-weight: 600; }
    .settings-message { margin-top: 1rem; padding: 0.75rem 1rem; border-radius: 4px; font-size: 0.9rem; }
    .settings-message.success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
    .settings-message.error { background: #fee; color: #c00; border: 1px solid #f99; }
    .loading-state { text-align: center; padding: 3rem 1rem; color: #6b7280; }
</style>
<script src="/assets/js/hub-settings.js" defer></script>
{/block}
