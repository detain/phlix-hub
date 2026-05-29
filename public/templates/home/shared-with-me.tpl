{extends file="layouts/base.tpl"}

{block name="title"}Shared With Me — Phlix Hub{/block}

{block name="content"}
<div class="shared-libraries">
    <div class="page-header">
        <h1>Shared With Me</h1>
        <a href="/manage-shares" class="btn btn-secondary">Manage My Shares</a>
    </div>

    <div class="library-list" id="shared-libraries-list">
        <div class="empty-state" id="empty-state">
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
    </div>
</div>
{/block}

{block name="scripts"}
<script src="/assets/js/shared-libraries.js" defer></script>
{/block}
