{extends file="layouts/base.tpl"}

{block name="title"}Claim a Server — Phlix Hub{/block}

{block name="content"}
<div class="claim-server">
    <h1>Claim a Server</h1>
    <p class="claim-description">
        To claim a server, run <code>php scripts/pair-with-hub.php</code> on your
        Phlix server. It will display a claim code like <code>ABCD-1234</code>.
        Enter that code below to attach the server to your account.
    </p>
    <form id="claim-form" method="post" action="/api/v1/server-claims/claim">
        <div class="form-group">
            <label for="claim_code">Claim Code</label>
            <input type="text" id="claim_code" name="claim_code"
                   placeholder="ABCD-1234" pattern="[A-Z0-9]{4}-[A-Z0-9]{4}"
                   maxlength="9" required autocomplete="off" autofocus />
        </div>
        <button type="submit" class="btn btn-primary">Claim Server</button>
    </form>
    <div id="claim-result" class="claim-result" aria-live="polite"></div>
</div>
{/block}
