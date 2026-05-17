# Subdomain Allocation (C.8)

## Overview

Each enrolled server gets a permanent public hostname under `*.phlex.media`
(e.g., `abc12345.phlex.media`). This allows clients to reach the server
without knowing its IP address or configuring DNS.

## How It Works

1. **Allocation**: When a server enrolls (C.3), it can request a subdomain via
   `POST /api/v1/servers/{id}/subdomain`. The hub generates a deterministic
   8-character subdomain based on `sha256(server_id)[:8]` — no coordination
   needed.

2. **DNS**: The hub operator configures a wildcard DNS `A` record for
   `*.phlex.media` pointing to the hub's IP address. `StaticZoneManager`
   writes zone files to `data/dns/zones/` for propagation.

3. **TLS**: The hub provisions a Let's Encrypt certificate for the subdomain
   via ACME HTTP-01 challenge. Certificates are stored in `data/tls/`
   and renewed automatically 60 days before expiry.

4. **Routing**: When a client requests `https://abc12345.phlex.media`, the
   hub's `RelayRouter` extracts the subdomain from the Host header, resolves
   it to a server ID, and routes the request over the server's relay tunnel.

## Configuration

### Hub Configuration (`config/hub.php`)

```php
return [
    // DNS provider: 'static' (zone files), 'cloudflare', 'route53'
    'dns_provider' => 'static',

    // Directory for DNS zone files
    'dns_zone_dir' => '/home/phlex/data/dns/zones',

    // Directory for TLS certificates
    'tls_certs_dir' => '/home/phlex/data/tls',

    // Email for Let's Encrypt account
    'acme_email' => 'admin@phlex.media',
];
```

### DNS Setup

The hub operator must configure wildcard DNS for `*.phlex.media`:

```
*.phlex.media.  IN A 203.0.113.1   # Hub's public IP
```

For `StaticZoneManager`, zone files are written to `data/dns/zones/phlex.media.zone`.

### TLS Requirements

Let's Encrypt ACME HTTP-01 challenge requires port 80 accessible from the
internet. The hub's HTTP server must serve `/.well-known/acme-challenge/`
on port 80 for certificate issuance.

## API Endpoints

### Allocate Subdomain

```
POST /api/v1/servers/{id}/subdomain
Authorization: Bearer <enrollment_jwt>

Response 200:
{
    "subdomain": "abc12345",
    "fqdn": "abc12345.phlex.media",
    "tls_cert_path": "/home/phlex/data/tls/abc12345.phlex.media/fullchain.pem",
    "tls_key_path": "/home/phlex/data/tls/abc12345.phlex.media/privkey.pem"
}
```

### Revoke Subdomain

```
DELETE /api/v1/servers/{id}/subdomain
Authorization: Bearer <enrollment_jwt>

Response 204: No Content
```

## Server-Side Integration

After enrollment, the server calls `SubdomainClient::claimSubdomain()` to
get its subdomain and TLS certificates. The server stores these in
`config/hub-subdomain.json`.

See [docs/dev/pairing-protocol.md](../dev/pairing-protocol.md) for the
updated enrollment flow with subdomain allocation.
