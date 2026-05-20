{extends file="layouts/base.tpl"}

{block name="title"}My Servers — Phlix Hub{/block}

{block name="content"}
<div class="my-servers">
    <div class="page-header">
        <h1>My Servers</h1>
        <a href="/claim-server" class="btn btn-primary">Claim a New Server</a>
    </div>

    <div class="server-list">
        {if empty($servers)}
            <div class="empty-state">
                <div class="empty-icon">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <rect x="2" y="3" width="20" height="14" rx="2"/>
                        <line x1="8" y1="21" x2="16" y2="21"/>
                        <line x1="12" y1="17" x2="12" y2="21"/>
                    </svg>
                </div>
                <h2>No servers yet</h2>
                <p>You haven't claimed any servers yet.</p>
                <p>To get started, run <code>php scripts/pair-with-hub.php</code>
                   on your Phlix server and enter the claim code below.</p>
                <a href="/claim-server" class="btn btn-primary">Claim a Server</a>
            </div>
        {else}
            {foreach $servers as $server}
                {include file="partials/server-card.tpl" server=$server}
            {/foreach}
        {/if}
    </div>
</div>
{/block}

{block name="scripts"}
<script src="/assets/js/my-servers.js" defer></script>
{/block}
