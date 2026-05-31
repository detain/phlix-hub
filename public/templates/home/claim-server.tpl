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
            {* Pattern is case-insensitive with an optional dash; claim-server.js
               live-normalises to the canonical UPPER `ABCD-1234` form, and the
               server itself strips non-alphanumerics + upper-cases. So a code
               typed in any case / with a stray space or en-dash still works. *}
            <input type="text" id="claim_code" name="claim_code"
                   placeholder="ABCD-1234" pattern="[A-Za-z0-9]{4}-?[A-Za-z0-9]{4}"
                   maxlength="9" required autocomplete="off" autocapitalize="characters"
                   inputmode="text" autofocus
                   title="8-character claim code, e.g. ABCD-1234"
                   style="text-transform: uppercase" />
        </div>
        <button type="submit" class="btn btn-primary">Claim Server</button>
    </form>
    <div id="claim-result" class="claim-result" aria-live="polite"></div>
</div>
{/block}

{block name="scripts"}
<script src="/assets/js/claim-server.js" defer></script>
{/block}
