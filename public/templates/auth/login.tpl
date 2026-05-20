{extends file="layouts/base.tpl"}

{block name="title"}Log in — Phlix Hub{/block}

{block name="content"}
    <h2>Log in</h2>
    {if !empty($error)}
        <div class="error">{$error|escape:'html'}</div>
    {/if}
    <form method="post" action="/login">
        <div>
            <label for="username">Username or email</label>
            <input id="username" name="username" type="text" required value="{$username|default:''|escape:'html'}">
        </div>
        <div>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required>
        </div>
        <button type="submit">Log in</button>
    </form>
    <p style="margin-top: 1rem;">No account yet? <a href="/signup">Sign up</a>.</p>
{/block}
