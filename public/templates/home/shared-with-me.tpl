{extends file="layouts/base.tpl"}

{block name="title"}Shared With Me — Phlex Hub{/block}

{block name="content"}
<div class="shared-libraries">
    <div class="page-header">
        <h1>Shared With Me</h1>
        <a href="/manage-shares" class="btn btn-secondary">Manage My Shares</a>
    </div>

    <div class="library-list">
        {if empty($sharedLibraries)}
            <div class="empty-state">
                <div class="empty-icon">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </div>
                <h2>No libraries shared with you yet</h2>
                <p>When someone shares a library with you, it will appear here.</p>
            </div>
        {else}
            {foreach $sharedLibraries as $lib}
                <div class="shared-library-card">
                    <div class="library-info">
                        <h2>{$lib.libraryName|escape:'html'}</h2>
                        <p class="owner-info">
                            Shared by <strong>{$lib.ownerName|escape:'html'}</strong>
                            on server <strong>{$lib.serverName|escape:'html'}</strong>
                        </p>
                        <p class="permission-badge permission-{$lib.permissionLevel|escape:'html'}">
                            {if $lib.permissionLevel == 'readwrite'}
                                Can edit
                            {else}
                                Read only
                            {/if}
                        </p>
                    </div>
                    <div class="library-actions">
                        <a href="/browse/{$lib.serverId|escape:'url'}/{$lib.libraryId|escape:'url'}"
                           class="btn btn-primary">Browse Library</a>
                    </div>
                </div>
            {/foreach}
        {/if}
    </div>
</div>
{/block}

{block name="scripts"}
<script src="/assets/js/shared-libraries.js" defer></script>
{/block}
