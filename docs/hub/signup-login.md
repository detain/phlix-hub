# Sign up and log in

This guide walks you through creating an account on a `phlix-hub`
deployment and signing in afterwards. The hub is the cloud directory
+ reverse-tunnel relay for Phlix media servers — once you sign in,
you'll be able to claim local Phlix servers from this account (the
"claim a server" workflow ships in Phase C).

## Prerequisites

- A reachable `phlix-hub` instance (your own self-hosted one, or a
  shared deployment your team operates). The default URL during
  development is `http://localhost:8800`.
- A web browser. The MVP uses standard HTML forms — no client-side JS
  required.

## Create an account

1. Open `http://<your-hub>/signup` in a browser.
2. Fill in the form:
   - **Username** — 3 to 50 characters, must be unique on the hub.
   - **Email** — your email address, must be unique on the hub.
   - **Password** — minimum 8 characters. Hashed with Argon2ID before
     it's stored.
3. Submit the form. On success the hub:
   - Creates your account.
   - Sets two cookies (`phlix_hub_token` and `phlix_hub_refresh`).
   - Redirects you to `/my-servers`.

If you're the very first person to sign up on a fresh hub deployment
you're automatically promoted to **admin**. Subsequent users sign up
as regular users. See `docs/dev/architecture-hub.md` for why we chose
this bootstrap policy.

## Log in

1. Open `http://<your-hub>/login`.
2. Enter your username (or email) plus your password.
3. Submit. On success you'll be redirected to `/my-servers`.

The hub accepts either your username or your email in the "Username or
email" field — the form looks up both in turn.

## The dashboard

`/my-servers` is the post-login landing page. In B.7 it shows the
empty-state placeholder copy:

> You haven't claimed any servers yet. Open your local Phlix install
> and use the "Claim with Phlix Hub" flow to attach it to this
> account.

The actual server claim workflow + the populated server list arrive
in Phase C (steps C.3 and C.4 of `PHLIX_EXPANSION_PLAN.md`).

## Log out

Click "Log out" in the top-right corner of any authenticated page.
The hub clears your session cookies and bounces you back to `/`.

## Troubleshooting

| Symptom                                  | Likely cause                                                                                            |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| "Email already registered"               | Another account on this hub already uses that email. Use a different one or recover the existing account. |
| "Username already taken"                  | Pick a different username — they're unique per-hub.                                                     |
| "Password must be at least 8 characters" | Choose a longer password.                                                                               |
| "Invalid email format"                    | The address has to pass PHP's `FILTER_VALIDATE_EMAIL`.                                                  |
| "Invalid username or password" on login   | The username/email/password combination didn't match anything on file.                                  |
| Redirect loop to `/login`                 | Your `phlix_hub_token` cookie expired. Log in again.                                                    |

## JSON API alternative

If you're integrating with the hub programmatically rather than via a
browser, use the JSON endpoints under `/api/v1/auth/*` — see
`docs/reference/api/hub-auth.yaml` for the OpenAPI spec.

```bash
# Signup
curl -X POST http://localhost:8800/api/v1/auth/signup \
     -H 'Content-Type: application/json' \
     -d '{"username":"alice","email":"alice@example.com","password":"correct-horse"}'

# Login
curl -X POST http://localhost:8800/api/v1/auth/login \
     -H 'Content-Type: application/json' \
     -d '{"username":"alice","password":"correct-horse"}'
```

Both endpoints return `{ access_token, refresh_token, token_type,
expires_in, user, claims }`. Carry the `access_token` in
`Authorization: Bearer <token>` on subsequent calls.
