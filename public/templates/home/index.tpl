{extends file="layouts/base.tpl"}

{block name="title"}Phlix Hub{/block}

{block name="content"}
    <h2>Welcome to Phlix Hub</h2>
    <p>Phlix Hub is the central directory + reverse-tunnel relay for your Phlix media servers. Sign in once, reach any of your servers from anywhere.</p>
    {if $is_authenticated|default:false}
        <p><a href="/my-servers">Go to your servers &rarr;</a></p>
    {else}
        <p><a href="/signup">Create an account</a> or <a href="/login">log in</a>.</p>
    {/if}
{/block}
