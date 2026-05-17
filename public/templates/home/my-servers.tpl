{extends file="layouts/base.tpl"}

{block name="title"}My Servers — Phlex Hub{/block}

{block name="content"}
    <h2>My Servers</h2>
    {assign var="is_authenticated" value=true}
    {if empty($servers)}
        <div class="empty-state">
            <p><strong>You haven't claimed any servers yet.</strong></p>
            <p>Open your local Phlex install and use the "Claim with Phlex Hub" flow to attach it to {if !empty($user.email)}<code>{$user.email|escape:'html'}</code>{else}this account{/if}.</p>
            <p style="color: #999; font-size: 0.875rem;">Server claim instructions land in Phase C.3 of the expansion plan.</p>
        </div>
    {else}
        <ul>
            {foreach $servers as $server}
                <li>{$server.server_name|escape:'html'}</li>
            {/foreach}
        </ul>
    {/if}
{/block}
