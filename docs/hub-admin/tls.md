# TLS Provisioning for `*.{public_domain}` (Out-of-Band)

## Status

Automated Let's Encrypt ACME provisioning advertised by **Step C.8** in
the CHANGELOG is **not implemented** in this build. Calling
`POST /api/v1/servers/{id}/subdomain` will still allocate the
subdomain (DNS record) but TLS material must be installed by the hub
operator out-of-band. The cert manager throws a clear
`RuntimeException` rather than silently pretending to succeed, and the
API returns **HTTP 501 Not Implemented** on the explicit cert-refresh
path.

This document tells you how to install certs by hand so the hub can
serve them.

## Where the hub looks for cert material

Configured by `config/hub.php` key `tls_certs_dir` (default:
`/home/phlix/data/tls`). For each provisioned subdomain
`abc12345.{public_domain}` the hub expects:

```
{tls_certs_dir}/{fqdn}/fullchain.pem
{tls_certs_dir}/{fqdn}/privkey.pem
```

For example, with the defaults:

```
/home/phlix/data/tls/abc12345.phlix.media/fullchain.pem
/home/phlix/data/tls/abc12345.phlix.media/privkey.pem
```

`TlsCertificateManager::getCertificatePath()` and `getPrivateKeyPath()`
return the absolute path iff both files exist, otherwise `null`. The
caller (e.g. `SubdomainController::allocate`) returns the paths in the
JSON response when present and empty strings when absent — so a client
can tell at a glance whether TLS is wired up.

## Recommended: certbot DNS-01 wildcard

A single wildcard cert covers every subdomain at once and avoids
exposing port 80 on the hub.

1. Pick a DNS-01 plugin for your DNS provider, e.g.
   `python3-certbot-dns-cloudflare`, `…-route53`, `…-rfc2136`.
2. Issue a wildcard cert for `*.{public_domain}`:

   ```bash
   sudo certbot certonly \
       --dns-cloudflare \
       --dns-cloudflare-credentials /etc/letsencrypt/cloudflare.ini \
       -d "*.phlix.media" \
       --email admin@phlix.media \
       --agree-tos --non-interactive
   ```

3. Symlink (or copy) the live cert into every per-FQDN directory the
   hub will look at:

   ```bash
   for fqdn in $(ls /home/phlix/data/tls); do
       sudo ln -sf /etc/letsencrypt/live/phlix.media/fullchain.pem \
           "/home/phlix/data/tls/${fqdn}/fullchain.pem"
       sudo ln -sf /etc/letsencrypt/live/phlix.media/privkey.pem \
           "/home/phlix/data/tls/${fqdn}/privkey.pem"
   done
   ```

   You can run that loop from a cron job after `certbot renew`.

4. Reload the hub so it picks up the new files. The hub re-reads
   cert material on each TLS handshake, so a HUP/restart is only
   needed if the listening socket has cached an SNI context.

## Alternative: per-subdomain certs (manual)

If you do not control DNS for the apex and have to issue per-subdomain
certs with HTTP-01:

1. Make sure `{fqdn}` resolves to the hub's public IP (the static
   zone manager handles this for new subdomains).
2. Stop or front the hub behind something that can serve
   `/.well-known/acme-challenge/` on port 80 for that name.
3. Run certbot:

   ```bash
   sudo certbot certonly --standalone \
       -d abc12345.phlix.media \
       --email admin@phlix.media \
       --agree-tos --non-interactive
   ```

4. Place the resulting files at the paths shown above.

## File permissions

The hub process must be able to read both files; nothing else should.

```bash
sudo chown -R phlix:phlix /home/phlix/data/tls
sudo find /home/phlix/data/tls -type d -exec chmod 750 {} \;
sudo find /home/phlix/data/tls -name 'fullchain.pem' -exec chmod 640 {} \;
sudo find /home/phlix/data/tls -name 'privkey.pem'   -exec chmod 600 {} \;
```

If you symlinked into `/etc/letsencrypt/live/...`, also confirm the
hub user can traverse `/etc/letsencrypt/{live,archive}` (Debian's
default is root-only — `sudo chmod 0755 /etc/letsencrypt/{live,archive}`
is the conventional fix, with the keyfile itself staying 0640 in a
`ssl-cert` group the hub user is a member of).

## Renewal

Certbot installs a systemd timer or cron entry that runs
`certbot renew` twice a day. Add a `--deploy-hook` that touches the
hub or reloads the listener so cached SNI contexts get refreshed:

```ini
# /etc/letsencrypt/renewal-hooks/deploy/phlix-hub.sh
#!/bin/sh
systemctl reload phlix-hub.service
```

## When automated ACME lands

`TlsCertificateManager::provisionCertificate()` will be implemented to
drive ACME v2 (HTTP-01 or DNS-01) end-to-end, write `fullchain.pem`
and `privkey.pem` at the same paths documented above, and the 501
response from `/api/v1/servers/{id}/subdomain` will go away. Until
then, this manual workflow is the supported path.
