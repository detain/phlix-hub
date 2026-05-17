{extends file="layouts/base.tpl"}

{block name="title"}Manage Library Shares — Phlex Hub{/block}

{block name="content"}
<div class="manage-shares">
    <div class="page-header">
        <h1>Manage Library Shares</h1>
        <a href="/shared-with-me" class="btn btn-secondary">View Shared With Me</a>
    </div>

    <section class="outgoing-shares">
        <h2>Libraries I've Shared</h2>

        {if empty($outgoingShares)}
            <div class="empty-state">
                <p>You haven't shared any libraries yet.</p>
                <p>Go to a server's library and use the "Share" option to share with others.</p>
            </div>
        {else}
            <table class="shares-table">
                <thead>
                    <tr>
                        <th>Library</th>
                        <th>Shared With</th>
                        <th>Permission</th>
                        <th>Shared On</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach $outgoingShares as $share}
                        <tr>
                            <td>
                                <strong>{$share.library_name|escape:'html'}</strong>
                                <br><small>on {$share.server_name|escape:'html'}</small>
                            </td>
                            <td>{$share.collaborator_email|escape:'html'}</td>
                            <td>
                                <span class="permission-badge permission-{$share.permission_level|escape:'html'}">
                                    {if $share.permission_level == 'readwrite'}
                                        Read/Write
                                    {else}
                                        Read only
                                    {/if}
                                </span>
                            </td>
                            <td>{$share.created_at|date_format:'%Y-%m-%d'}</td>
                            <td class="actions">
                                <button type="button" class="btn btn-small btn-warning revoke-share"
                                        data-share-id="{$share.id|escape:'html'}">
                                    Revoke
                                </button>
                            </td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        {/if}
    </section>
</div>
{/block}

{block name="scripts"}
<script src="/assets/js/manage-shares.js" defer></script>
{/block}
