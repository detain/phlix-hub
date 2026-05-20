{extends file="layouts/base.tpl"}

{block name="title"}Accept Invite — Phlix Hub{/block}

{block name="content"}
<div class="accept-invite-page">
    {if $is_authenticated}
        <div class="page-header">
            <h1>Accept Invite</h1>
        </div>

        <section class="invite-info">
            <p>You've been invited to access a library!</p>

            {if $error}
                <div class="error">
                    <p>{$error|escape:'html'}</p>
                </div>
            {/if}

            {if $success}
                <div class="success-box">
                    <p>You've been granted access to the library!</p>
                    <a href="/shared-with-me" class="btn btn-primary">View Shared Libraries</a>
                </div>
            {else}
                <form method="post" action="/invite/accept">
                    <input type="hidden" name="token" value="{$token|escape:'html'}">
                    <button type="submit" class="btn btn-primary">Accept Invite</button>
                </form>
            {/if}
        </section>
    {else}
        <div class="page-header">
            <h1>Accept Invite</h1>
        </div>

        <section class="login-prompt">
            <p>To accept this invite, please log in or create an account.</p>

            <div class="auth-buttons">
                <a href="/login?redirect=/invite/{$token|escape:'html'}" class="btn btn-primary">Log In</a>
                <a href="/signup?redirect=/invite/{$token|escape:'html'}" class="btn btn-secondary">Sign Up</a>
            </div>
        </section>
    {/if}
</div>
{/block}

{block name="scripts"}
<script src="/assets/js/accept-invite.js" defer></script>
{/block}