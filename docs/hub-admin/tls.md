# TLS Certificates for Server Subdomains (Operator Runbook)

This runbook describes how TLS certificates are provisioned for the
per-server subdomains (`*.phlix.media`) that the hub allocates when a
server enrolls.

## Important: automated ACME provisioning is NOT implemented

This build does **not** ship automated Let's Encrypt / ACME
certificate provisioning. The relevant code paths are deliberate,
honest stubs:

- `Phlix\Hub\Hub\TlsCertificateManager::provisionCertificate()` always
  throws a `\RuntimeException` with the stable, machine-grep-able
  message:

  ```
  ACME certificate provisioning is not implemented in this build.
  Provision certs out-of-band — see docs/hub-admin/tls.md.
  ```

- The explicit cert-refresh entry point
  (`SubdomainController::refreshCertificate()`) catches that exception
  and returns **HTTP 501 Not Implemented** with
  `{"error":"NOT_IMPLEMENTED","code":"tls.acme_not_implemented", ...}`
  and a `Link: </docs/hub-admin/tls.md>; rel="help"` header.

Because of this, operators must provision certificates **out-of-band**
(see below). DNS allocation does not depend on TLS: allocating a
subdomain (the DNS record + the `servers.subdomain` row) succeeds
independently, and `DnsAliasManager::allocateSubdomain()` logs a
warning rather than failing if a cert is not yet present.

## What "DNS wired, TLS pending" looks like

`GET`/`POST /api/v1/servers/{id}/subdomain` returns the allocated
`subdomain` and `fqdn` plus `tls_cert_path` and `tls_key_path`. Until
an operator installs certificate material at the conventional
location, those two fields come back as **empty strings**. Clients
should treat that as "DNS is wired up, TLS is pending operator
action" rather than a failure.

## Provisioning certificates out-of-band

The hub reads certificate material from on disk only — it never issues
it. The read-side helpers (`getCertificatePath()`,
`getPrivateKeyPath()`, `isProvisioned()`, `needsRenewal()`) report the
truth from these files.

### Expected layout

For a server allocated the subdomain `abc12345`, the FQDN is
`abc12345.phlix.media` and the hub expects two files:

```
<tls_certs_dir>/abc12345.phlix.media/fullchain.pem
<tls_certs_dir>/abc12345.phlix.media/privkey.pem
```

`<tls_certs_dir>` is the `tls_certs_dir` application-config key
(default `/home/phlix/data/tls`). `fullchain.pem` must contain the
PEM-encoded leaf certificate plus its issuing chain; `privkey.pem`
must contain the matching PEM-encoded private key.

`isProvisioned($subdomain)` returns `true` only when **both** files
exist; the read helpers return `null` (surfaced as empty strings to
clients) until then.

### Recommended approach: wildcard certificate

Because every server lives under `*.phlix.media`, the simplest
operational model is a single wildcard certificate (`*.phlix.media`,
DNS-01 challenge) issued by your tooling of choice — for example
`certbot`, `acme.sh`, `lego`, or your existing internal PKI:

```
certbot certonly --manual --preferred-challenges dns -d '*.phlix.media'
```

Then symlink (or copy) the issued material into each allocated
subdomain directory, e.g.:

```
mkdir -p /home/phlix/data/tls/abc12345.phlix.media
ln -sf /etc/letsencrypt/live/phlix.media/fullchain.pem \
       /home/phlix/data/tls/abc12345.phlix.media/fullchain.pem
ln -sf /etc/letsencrypt/live/phlix.media/privkey.pem \
       /home/phlix/data/tls/abc12345.phlix.media/privkey.pem
```

A wildcard cert covers every subdomain at once, so a single renewal
keeps all servers current.

### Renewal monitoring

`TlsCertificateManager::needsRenewal($subdomain)` is a pure read: it
shells out (via `proc_open` with an argv array, no shell) to
`openssl x509 -noout -enddate` and returns `true` when the cert is
missing or expires within 60 days. Wire it into a cron/monitoring
script if you want renewal alerts; the hub itself does not renew.

## When ACME lands

The `acme_email` config key and `TlsCertificateManager::getAcmeEmail()`
are retained for the eventual ACME implementation. Until then they are
diagnostic-only and do not trigger any issuance.
