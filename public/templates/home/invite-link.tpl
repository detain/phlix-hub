{extends file="layouts/base.tpl"}

{block name="title"}Invite Link — Phlex Hub{/block}

{block name="content"}
<div class="invite-link-page">
    <div class="page-header">
        <h1>Invite Link Created</h1>
        <a href="/my-servers" class="btn btn-secondary">Back to My Servers</a>
    </div>

    <section class="invite-link-card">
        <div class="invite-link-url">
            <label>Share this link:</label>
            <div class="url-display">
                <input type="text" id="invite-url" value="{$invite_url|escape:'html'}" readonly>
                <button type="button" class="btn btn-copy" data-copy-target="invite-url">Copy</button>
            </div>
        </div>

        <div class="invite-link-meta">
            <p><strong>Permission:</strong> {if $permission == 'readwrite'}Read/Write{else}Read only{/if}</p>
            <p><strong>Max uses:</strong> {$max_uses|escape:'html'}</p>
            {if $expires_at}
                <p><strong>Expires:</strong> {$expires_at|date_format:'%Y-%m-%d %H:%M'|escape:'html'}</p>
            {else}
                <p><strong>Expires:</strong> Never</p>
            {/if}
            <p><strong>Server:</strong> {$server_name|escape:'html'}</p>
            {if $library_id}
                <p><strong>Library:</strong> {$library_name|escape:'html'}</p>
            {else}
                <p><strong>Library:</strong> All libraries</p>
            {/if}
        </div>

        <div class="invite-link-actions">
            <button type="button" class="btn btn-warning revoke-invite" data-link-id="{$link_id|escape:'html'}">
                Revoke Link
            </button>
        </div>
    </section>

    <section class="info-box">
        <h3>How invite links work</h3>
        <ul>
            <li>Share this link with friends or family</li>
            <li>They'll need a Phlex Hub account (free to sign up)</li>
            <li>Clicking the link grants them {$permission|escape:'html'} access to {if $library_id}the specified library{else}all your libraries{/if}</li>
            <li>Links can only be used a limited number of times</li>
            <li>You can revoke access at any time from your dashboard</li>
        </ul>
    </section>
</div>
{/block}

{block name="scripts"}
<script src="/assets/js/invite-link.js" defer></script>
{/block}