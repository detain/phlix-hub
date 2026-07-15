# phlix-ui Deployment — phlix-hub

This document describes how to update `@phlix/ui` and rebuild the Vite assets on **phlix-hub**.

## Two-Level Architecture

`@phlix/ui` is published as an npm package (referenced as a GitHub tarball in `package.json`). `phlix-hub`'s `web-ui/` imports it and runs `vite build` to produce **Vite code-split chunks** in `public/assets/app/assets/`. The hub serves these assets directly.

```
@phlix/ui npm package (dist/player.js, dist/phlix-ui.js, ...)
        ↓  npm install
phlix-hub/web-ui/node_modules/@phlix/ui/
        ↓  vite build
phlix-hub/public/assets/app/assets/    ←  served by hub Workerman
```

## Prerequisites

- SSH access to `root@153.75.226.242`
- `npm` installed on the build machine (local or server)
- `rsync` for syncing built assets to the live hub

## Hub-Specific Env

The hub requires `HUB_JWT_SECRET` to start. This is read from `/etc/phlix-hub.env` when sourcing env vars.

**Hub directories on the live server:**
- Hub code: `/opt/phlix-hub/`
- Built web assets: `/opt/phlix-hub/public/assets/app/assets/`
- Hub env file: `/etc/phlix-hub.env`
- Hub logs: `/opt/phlix-hub/.logs/`

## Deploy Steps

### Option A — Build locally, sync to server

**1. Build `@phlix/ui` (if you made source changes):**

```bash
cd /home/sites/phlix/phlix-ui
npm run build
git add dist/
git commit -m "chore(phlix-ui): bump to vX.Y.Z"
git tag vX.Y.Z
git push && git push --tags
```

**2. Update hub's `web-ui/package.json` to the new version:**

```json
"@phlix/ui": "https://github.com/detain/phlix-ui/archive/refs/tags/vX.Y.Z.tar.gz"
```

**3. Build hub web-ui locally:**

```bash
cd /home/sites/phlix/phlix-hub/web-ui
npm install
npm run build
```

**4. Sync built assets to the live hub:**

```bash
rsync -av --delete \
  /home/sites/phlix/phlix-hub/public/assets/app/assets/ \
  root@153.75.226.242:/opt/phlix-hub/public/assets/app/assets/
```

**5. Verify on live server:**

```bash
# Check chunk names
ssh root@153.75.226.242 "ls /opt/phlix-hub/public/assets/app/assets/index-*.js"
```

---

### Option B — Build directly on the server

**1. SSH to the server:**

```bash
ssh root@153.75.226.242
```

**2. Find and kill the current hub master process:**

```bash
# Find hub PID
ps aux | grep 'start_file=/opt/phlix-hub/start.php' | grep -v grep | awk '{print $2}'

# Kill the master (workers will die too)
kill <hub_pid>

# Or use pkill
pkill -f 'start_file=/opt/phlix-hub/start.php'
```

**3. Pull latest code:**

```bash
cd /opt/phlix-hub
git fetch origin
git checkout master
git pull origin master
```

**4. Build the web-ui:**

```bash
source /etc/phlix-hub.env
cd /opt/phlix-hub/web-ui
npm install
npm run build
```

**5. Commit the new assets (recommended):**

```bash
cd /opt/phlix-hub
git add public/assets/app/
git commit -m "chore(hub): rebuild web-ui assets"
git push
```

**6. Start the hub:**

```bash
source /etc/phlix-hub.env
cd /opt/phlix-hub
nohup php start.php start > /opt/phlix-hub/.logs/startup.log 2>&1 &
sleep 3
curl -s http://localhost:8800/health
```

## Hub Health Check

```bash
# Via curl on server
curl -s http://localhost:8800/health

# Response should be:
# {"status":"ok","service":"phlix-hub","version":"0.2.0",...}
```

## Verifying the Update

**1. Check chunk hashes changed:**

```bash
ssh root@153.75.226.242 "ls -la /opt/phlix-hub/public/assets/app/assets/index-*.js"
```

**2. Verify Q key shortcut works:**
- Open DevTools (F12) → **Network** tab → check "Disable cache" → reload the player page
- Press **Q** during playback
- Should see Quality menu (transcoded) or "Direct Stream" toast (direct)

**3. Check hub workers are running:**

```bash
ssh root@153.75.226.242 "ps aux | grep 'phlix-hub-http' | grep -v grep | wc -l"
# Should show 4 or more worker lines
```

## Troubleshooting

### "No JWT secret configured" when starting hub
The hub requires `HUB_JWT_SECRET` from `/etc/phlix-hub.env`. Always source the env before starting:

```bash
source /etc/phlix-hub.env
cd /opt/phlix-hub && php start.php start
```

### Hub workers not responding
Check the hub health endpoint:
```bash
curl -s http://localhost:8800/health
```

If it returns an error, check hub logs:
```bash
tail -20 /opt/phlix-hub/.logs/error-$(date +%Y-%m-%d).log
```

### TDZ / "can't access lexical declaration before initialization" error
Same root cause as phlix-server. The error appears in `Select.vue` when a `const` is accessed before its declaration line is evaluated. Fix: move the `const` declaration **before** the code that triggers it (e.g., before a `{ immediate: true }` watch that calls a function using that `const`).

## Hub vs Server — Key Differences

| Item | phlix-server | phlix-hub |
|------|-------------|-----------|
| Code path | `/var/www/phlix/` | `/opt/phlix-hub/` |
| Web assets | `/var/www/phlix/public/assets/app/assets/` | `/opt/phlix-hub/public/assets/app/assets/` |
| HTTP port | 8096 | 8800 |
| Hub relay port | 8802 | — (this IS the hub) |
| Env file | `/etc/phlix/env` | `/etc/phlix-hub.env` |
| Database | `phlix` (MySQL) | `phlix_hub` (MySQL) — separate DB |
| Profile management | Has `UserProfileManager` | Delegates to server |
| Static asset serving | Via CDN/public proxy | Via hub Workerman |
