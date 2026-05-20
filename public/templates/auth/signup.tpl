{extends file="layouts/base.tpl"}

{block name="title"}Sign up — Phlix Hub{/block}

{block name="content"}
    <h2>Create your account</h2>
    {if !empty($error)}
        <div class="error">{$error|escape:'html'}</div>
    {/if}
    <form method="post" action="/signup">
        <div>
            <label for="username">Username</label>
            <input id="username" name="username" type="text" required minlength="3" maxlength="50" value="{$username|default:''|escape:'html'}">
        </div>
        <div>
            <label for="email">Email</label>
            <input id="email" name="email" type="email" required value="{$email|default:''|escape:'html'}">
        </div>
        <div>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required minlength="8">
        </div>
        <button type="submit">Create account</button>
    </form>
    <p style="margin-top: 1rem;">Already have an account? <a href="/login">Log in</a>.</p>
{/block}
