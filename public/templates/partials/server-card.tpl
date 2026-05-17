{**
 * Individual server card partial.
 *
 * Expects $server array with ServerInfoDto fields:
 *   - serverId, serverName, version, status, lastSeenAt,
 *     hostnameCandidates, relayActive
 *
 * @param array $server
 *}
<div class="server-card" data-server-id="{$server.serverId|escape:'html'}">
    <div class="server-status status-{$server.status|escape:'html'}">
        <span class="status-dot"></span>
        <span class="status-label">{$server.status|escape:'html'|capitalize}</span>
    </div>

    <div class="server-info">
        <h2 class="server-name">{$server.serverName|escape:'html'}</h2>
        <p class="server-version">Phlex {$server.version|escape:'html'}</p>

        <p class="server-last-seen">
            {if $server.lastSeenAt}
                Last seen: {$server.lastSeenAt|date_format:'%Y-%m-%d %H:%M'}
            {else}
                Never connected
            {/if}
        </p>

        {if $server.hostnameCandidates && $server.hostnameCandidates|@count > 0}
            <div class="server-hostnames">
                <span class="hostname-label">Direct access:</span>
                {foreach $server.hostnameCandidates as $hostname}
                    <code class="hostname">{$hostname|escape:'html'}</code>
                {/foreach}
            </div>
        {/if}

        <div class="server-meta">
            {if $server.relayActive}
                <span class="meta-badge relay-active">Relay Active</span>
            {/if}
        </div>

        <div class="server-actions">
            <button class="btn btn-remove" data-server-id="{$server.serverId|escape:'html'}"
                    type="button">Remove</button>
        </div>
    </div>
</div>
