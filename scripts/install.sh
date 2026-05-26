#!/usr/bin/env bash
#
# Phlix Hub one-shot installer for Ubuntu/Debian.
#
# Installs system packages, creates the MySQL database + user, fetches the
# application code, writes the environment file, generates a JWT secret,
# runs database migrations, installs a systemd service, and (optionally)
# sets up an HAProxy reverse proxy with a Let's Encrypt certificate that
# auto-renews monthly.
#
# Usage:
#   sudo bash install.sh [options]
#   curl -fsSL https://raw.githubusercontent.com/detain/phlix-hub/master/scripts/install.sh | sudo bash
#
# Run with --help for the full option list.

set -euo pipefail

# ---------------------------------------------------------------------------
# Defaults (override via flags or interactive prompts)
# ---------------------------------------------------------------------------
REPO_URL="https://github.com/detain/phlix-hub.git"
BRANCH="master"
INSTALL_PATH="/opt/phlix-hub"
SERVICE_USER="www-data"
ENV_FILE="/etc/phlix-hub.env"
SERVICE_FILE="/etc/systemd/system/phlix-hub.service"

DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_NAME="phlix_hub"
DB_USER="phlix_hub"
DB_PASS=""              # generated if empty

HUB_PORT="8800"
RELAY_PORT="8802"
CLIENT_RELAY_PORT="8803"
HUB_WORKERS="4"

DOMAIN=""               # public hostname; enables TLS when set with --admin-email
ADMIN_EMAIL=""          # Let's Encrypt registration email
JWT_SECRET=""           # generated if empty

WANT_TLS="auto"         # auto|yes|no
INTERACTIVE="auto"      # auto|yes|no
ASSUME_YES="no"
ACTION="install"        # install|update|uninstall
PURGE="no"              # uninstall: also drop the DB and delete the Let's Encrypt cert

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------
if [ -t 1 ]; then
  C_BOLD=$'\033[1m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_RED=$'\033[31m'; C_RESET=$'\033[0m'
else
  C_BOLD=""; C_GREEN=""; C_YELLOW=""; C_RED=""; C_RESET=""
fi
log()  { printf '%s==>%s %s\n' "$C_GREEN$C_BOLD" "$C_RESET" "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '%s[warn]%s %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2; }
die()  { printf '%s[error]%s %s\n' "$C_RED" "$C_RESET" "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Usage
# ---------------------------------------------------------------------------
usage() {
  cat <<'EOF'
Phlix Hub installer

Usage:
  sudo bash install.sh [options]

Options:
  --install-path PATH     Where to install the code      (default: /opt/phlix-hub)
  --domain HOST           Public hostname for the hub     (enables TLS with --admin-email)
  --admin-email EMAIL     Email for Let's Encrypt registration
  --db-name NAME          Database name to create         (default: phlix_hub)
  --db-user USER          Database user to create         (default: phlix_hub)
  --db-pass PASS          Database password               (default: random)
  --db-host HOST          Database host                   (default: 127.0.0.1)
  --db-port PORT          Database port                   (default: 3306)
  --jwt-secret SECRET     JWT signing secret              (default: random 32-byte)
  --service-user USER     System user to run as           (default: www-data)
  --workers N             HTTP worker processes           (default: 4)
  --branch NAME           Git branch to install           (default: master)
  --repo URL              Git repository URL              (default: detain/phlix-hub)
  --tls                   Force TLS / HAProxy + certbot setup
  --no-tls                Skip TLS; HAProxy serves plain HTTP on :80
  --update                Update an existing install (reuses env file, pulls
                          new code, runs composer + migrations, restarts service)
  --uninstall             Remove an existing (possibly partial) install
                          (prompts before each destructive step)
  --purge                 With --uninstall, also DROP the database and DELETE
                          the Let's Encrypt certificate (data loss)
  -y, --non-interactive   Never prompt; use defaults/flags (auto when no TTY)
  --interactive           Force prompts even when piped
  -h, --help              Show this help and exit

Examples:
  # Interactive install
  sudo bash install.sh

  # Fully unattended with TLS
  sudo bash install.sh -y --domain hub.example.com --admin-email me@example.com

  # One-liner (auto non-interactive; add flags for TLS)
  curl -fsSL https://raw.githubusercontent.com/detain/phlix-hub/master/scripts/install.sh | sudo bash

  # Interactive uninstall (keeps DB + cert unless you confirm each)
  sudo bash install.sh --uninstall

  # Fully unattended uninstall, including DB drop and cert deletion
  sudo bash install.sh --uninstall --purge -y

  # Update an existing install to the latest master (preserves env + JWT secret)
  sudo bash install.sh --update -y

  # Update and switch to a different branch / tag
  sudo bash install.sh --update --branch v0.2.0 -y
EOF
}

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
while [ $# -gt 0 ]; do
  case "$1" in
    --install-path) INSTALL_PATH="$2"; shift 2;;
    --domain)       DOMAIN="$2"; shift 2;;
    --admin-email)  ADMIN_EMAIL="$2"; shift 2;;
    --db-name)      DB_NAME="$2"; shift 2;;
    --db-user)      DB_USER="$2"; shift 2;;
    --db-pass)      DB_PASS="$2"; shift 2;;
    --db-host)      DB_HOST="$2"; shift 2;;
    --db-port)      DB_PORT="$2"; shift 2;;
    --jwt-secret)   JWT_SECRET="$2"; shift 2;;
    --service-user) SERVICE_USER="$2"; shift 2;;
    --workers)      HUB_WORKERS="$2"; shift 2;;
    --branch)       BRANCH="$2"; shift 2;;
    --repo)         REPO_URL="$2"; shift 2;;
    --tls)          WANT_TLS="yes"; shift;;
    --no-tls)       WANT_TLS="no"; shift;;
    --update)       ACTION="update"; shift;;
    --uninstall)    ACTION="uninstall"; shift;;
    --purge)        PURGE="yes"; ACTION="uninstall"; shift;;
    -y|--non-interactive|--yes) INTERACTIVE="no"; ASSUME_YES="yes"; shift;;
    --interactive)  INTERACTIVE="yes"; shift;;
    -h|--help)      usage; exit 0;;
    *) die "Unknown option: $1 (try --help)";;
  esac
done

# ---------------------------------------------------------------------------
# Environment checks
# ---------------------------------------------------------------------------
[ "$(id -u)" -eq 0 ] || die "Please run as root (e.g. with sudo)."
command -v apt-get >/dev/null 2>&1 || die "This installer targets Ubuntu/Debian (apt-get not found)."

# Decide interactivity: explicit flag wins, otherwise auto-detect a TTY on stdin.
if [ "$INTERACTIVE" = "auto" ]; then
  if [ -t 0 ]; then INTERACTIVE="yes"; else INTERACTIVE="no"; fi
fi

prompt() {
  # prompt VAR "message" "default"
  local __var="$1" __msg="$2" __def="$3" __ans=""
  if [ "$INTERACTIVE" = "yes" ] && [ -e /dev/tty ]; then
    read -r -p "$__msg [$__def]: " __ans </dev/tty || true
  fi
  printf -v "$__var" '%s' "${__ans:-$__def}"
}

confirm() {
  # confirm "message" -> returns 0 for yes
  [ "$ASSUME_YES" = "yes" ] && return 0
  [ "$INTERACTIVE" = "yes" ] && [ -e /dev/tty ] || return 0
  local __ans=""
  read -r -p "$1 [Y/n]: " __ans </dev/tty || true
  case "${__ans:-y}" in [Nn]*) return 1;; *) return 0;; esac
}

rand_hex() { openssl rand -hex "${1:-32}"; }
rand_pass() { openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 24; }

# ---------------------------------------------------------------------------
# Uninstall
# ---------------------------------------------------------------------------
do_uninstall() {
  log "Phlix Hub uninstaller"

  # Prefer the install path recorded in the systemd unit (the operator may
  # have used a non-default --install-path on the original run). An explicit
  # --install-path flag on this invocation overrides everything.
  local svc_workdir=""
  if [ -f "$SERVICE_FILE" ] && [ "$INSTALL_PATH" = "/opt/phlix-hub" ]; then
    svc_workdir="$(awk -F= '/^WorkingDirectory=/{print $2; exit}' "$SERVICE_FILE" 2>/dev/null || true)"
    [ -n "$svc_workdir" ] && INSTALL_PATH="$svc_workdir"
  fi

  # Pull DB / domain details from the env file when present so we can clean
  # up matching MySQL grants and HAProxy/Let's Encrypt artefacts.
  local env_db_name="" env_db_user="" env_db_host="" env_domain=""
  if [ -f "$ENV_FILE" ]; then
    env_db_name="$(awk -F= '/^HUB_DB_NAME=/{print $2; exit}' "$ENV_FILE")"
    env_db_user="$(awk -F= '/^HUB_DB_USER=/{print $2; exit}' "$ENV_FILE")"
    env_db_host="$(awk -F= '/^HUB_DB_HOST=/{print $2; exit}' "$ENV_FILE")"
    env_domain="$(awk -F= '/^HUB_PUBLIC_DOMAIN=/{print $2; exit}'  "$ENV_FILE")"
  fi
  # Flags win over env values; otherwise use the env value (then defaults).
  local U_DB_NAME="${env_db_name:-$DB_NAME}"
  local U_DB_USER="${env_db_user:-$DB_USER}"
  local U_DB_HOST="${env_db_host:-$DB_HOST}"
  local U_DOMAIN="${DOMAIN:-$env_domain}"

  # Detect artefacts.
  local found=0
  local svc="" envf="" instdir=""
  local hapcfg_bak="" hapcert="" hapcfg_modified="no"
  local cron="" hook="" le_dir=""
  [ -f "$SERVICE_FILE" ] && svc="$SERVICE_FILE" && found=1
  [ -f "$ENV_FILE" ]     && envf="$ENV_FILE"   && found=1
  [ -d "$INSTALL_PATH" ] && instdir="$INSTALL_PATH" && found=1
  [ -f /etc/haproxy/haproxy.cfg.phlix.bak ] \
      && hapcfg_bak="/etc/haproxy/haproxy.cfg.phlix.bak" && found=1
  if [ -n "$U_DOMAIN" ] && [ -f "/etc/haproxy/certs/${U_DOMAIN}.pem" ]; then
    hapcert="/etc/haproxy/certs/${U_DOMAIN}.pem"; found=1
  fi
  [ -f /etc/cron.d/phlix-hub-certbot ] \
      && cron="/etc/cron.d/phlix-hub-certbot" && found=1
  [ -f /etc/letsencrypt/renewal-hooks/deploy/phlix-haproxy.sh ] \
      && hook="/etc/letsencrypt/renewal-hooks/deploy/phlix-haproxy.sh" && found=1
  if [ -n "$U_DOMAIN" ] && [ -d "/etc/letsencrypt/live/${U_DOMAIN}" ]; then
    le_dir="/etc/letsencrypt/live/${U_DOMAIN}"; found=1
  fi
  # HAProxy config we wrote but for which no backup was preserved (i.e.
  # haproxy was unconfigured before install): leave it but warn.
  if [ -z "$hapcfg_bak" ] && [ -f /etc/haproxy/haproxy.cfg ] \
     && grep -qE 'be_hub|be_client_relay' /etc/haproxy/haproxy.cfg; then
    hapcfg_modified="yes"; found=1
  fi

  # Database lookup is best-effort: requires mysql client + running server.
  local has_db="no"
  if [ -n "$U_DB_NAME" ] && command -v mysql >/dev/null 2>&1; then
    if mysql -N -e "SHOW DATABASES LIKE '${U_DB_NAME}';" 2>/dev/null \
         | grep -qx "${U_DB_NAME}"; then
      has_db="yes"; found=1
    fi
  fi

  if [ "$found" -eq 0 ]; then
    info "No Phlix Hub artefacts found — nothing to uninstall."
    return 0
  fi

  echo
  log "Found the following Phlix Hub artefacts:"
  [ -n "$svc" ]                  && info " - systemd service      : $svc"
  [ -n "$envf" ]                 && info " - environment file     : $envf"
  [ -n "$instdir" ]              && info " - install directory    : $instdir"
  [ "$has_db" = "yes" ]          && info " - MySQL database       : ${U_DB_NAME} (user '${U_DB_USER}'@'${U_DB_HOST}')"
  [ -n "$hapcfg_bak" ]           && info " - HAProxy backup       : $hapcfg_bak (will be restored)"
  [ "$hapcfg_modified" = "yes" ] && info " - HAProxy config has Phlix backends but no backup of a previous config"
  [ -n "$hapcert" ]              && info " - HAProxy TLS cert     : $hapcert"
  [ -n "$hook" ]                 && info " - Certbot deploy hook  : $hook"
  [ -n "$cron" ]                 && info " - Certbot renewal cron : $cron"
  [ -n "$le_dir" ]               && info " - Let's Encrypt cert   : $le_dir"
  echo

  # Destructive opt-ins (DB drop, cert delete). --purge says yes to both;
  # otherwise we only ask interactively. -y alone keeps the data.
  local drop_db="no"
  if [ "$has_db" = "yes" ]; then
    if [ "$PURGE" = "yes" ]; then
      drop_db="yes"
    elif [ "$ASSUME_YES" != "yes" ] && [ "$INTERACTIVE" = "yes" ] && [ -e /dev/tty ]; then
      confirm "Drop MySQL database '${U_DB_NAME}' and user '${U_DB_USER}'@'${U_DB_HOST}'? (DATA LOSS)" \
        && drop_db="yes"
    fi
  fi

  local revoke_cert="no"
  if [ -n "$le_dir" ]; then
    if [ "$PURGE" = "yes" ]; then
      revoke_cert="yes"
    elif [ "$ASSUME_YES" != "yes" ] && [ "$INTERACTIVE" = "yes" ] && [ -e /dev/tty ]; then
      confirm "Delete Let's Encrypt certificate for '${U_DOMAIN}' via 'certbot delete'?" \
        && revoke_cert="yes"
    fi
  fi

  [ "$has_db" = "yes" ] && { [ "$drop_db"     = "yes" ] && info "Will DROP database '${U_DB_NAME}' and user '${U_DB_USER}'@'${U_DB_HOST}'." \
                                                       || info "Will KEEP MySQL database and user."; }
  [ -n "$le_dir" ]      && { [ "$revoke_cert" = "yes" ] && info "Will DELETE Let's Encrypt certificate '${U_DOMAIN}'." \
                                                       || info "Will KEEP Let's Encrypt certificate '${U_DOMAIN}'."; }
  echo

  # Final gate. Piped/non-interactive runs require explicit -y.
  if [ "$ASSUME_YES" != "yes" ]; then
    if [ "$INTERACTIVE" = "yes" ] && [ -e /dev/tty ]; then
      confirm "Proceed with uninstall?" || die "Aborted by user."
    else
      die "Refusing to uninstall non-interactively without -y."
    fi
  fi

  # ---- Execute ----

  # 1. systemd
  if [ -n "$svc" ]; then
    log "Stopping and removing phlix-hub service"
    systemctl stop phlix-hub      >/dev/null 2>&1 || true
    systemctl disable phlix-hub   >/dev/null 2>&1 || true
    rm -f "$svc"
    systemctl daemon-reload       >/dev/null 2>&1 || true
  fi

  # 2. HAProxy config: restore the pre-install backup, or warn if we wrote
  # the config from scratch (no original to fall back to).
  if [ -n "$hapcfg_bak" ]; then
    log "Restoring previous HAProxy configuration"
    mv "$hapcfg_bak" /etc/haproxy/haproxy.cfg
  elif [ "$hapcfg_modified" = "yes" ]; then
    warn "/etc/haproxy/haproxy.cfg still references Phlix backends but no backup was preserved."
    warn "Leaving it in place — edit or replace it manually."
  fi

  # 3. HAProxy TLS cert pem
  [ -n "$hapcert" ] && { log "Removing HAProxy TLS certificate"; rm -f "$hapcert"; }

  # 4. Reload haproxy if it's actually running and the config still validates.
  if command -v haproxy >/dev/null 2>&1 \
     && systemctl is-active --quiet haproxy 2>/dev/null; then
    if haproxy -c -f /etc/haproxy/haproxy.cfg >/dev/null 2>&1; then
      systemctl reload haproxy >/dev/null 2>&1 \
        || systemctl restart haproxy >/dev/null 2>&1 || true
    else
      warn "Updated HAProxy config did not validate — leaving haproxy in its current state."
    fi
  fi

  # 5. Certbot artefacts
  [ -n "$cron" ] && { log "Removing certbot cron entry"; rm -f "$cron"; }
  [ -n "$hook" ] && { log "Removing certbot deploy hook"; rm -f "$hook"; }
  if [ "$revoke_cert" = "yes" ] && command -v certbot >/dev/null 2>&1; then
    log "Deleting Let's Encrypt certificate for ${U_DOMAIN}"
    certbot delete --non-interactive --cert-name "${U_DOMAIN}" >/dev/null 2>&1 \
      || warn "certbot delete failed — remove /etc/letsencrypt/live/${U_DOMAIN}/ manually if needed."
  fi

  # 6. Database
  if [ "$drop_db" = "yes" ]; then
    log "Dropping MySQL database and user"
    if mysql <<SQL >/dev/null 2>&1
DROP DATABASE IF EXISTS \`${U_DB_NAME}\`;
DROP USER IF EXISTS '${U_DB_USER}'@'${U_DB_HOST}';
FLUSH PRIVILEGES;
SQL
    then
      info "Dropped database '${U_DB_NAME}' and user '${U_DB_USER}'@'${U_DB_HOST}'."
    else
      warn "MySQL cleanup failed — drop the database and user manually if needed."
    fi
  fi

  # 7. Install directory (sanity-check the path before rm -rf).
  if [ -n "$instdir" ]; then
    case "$instdir" in
      ""|/|/bin|/boot|/dev|/etc|/home|/lib*|/opt|/proc|/root|/run|/sbin|/srv|/sys|/tmp|/usr|/var)
        warn "Refusing to remove suspicious install path: $instdir"
        ;;
      *)
        log "Removing install directory $instdir"
        rm -rf "$instdir"
        ;;
    esac
  fi

  # 8. Env file last — we read DB creds out of it earlier.
  [ -n "$envf" ] && { log "Removing environment file $envf"; rm -f "$envf"; }

  echo
  log "Phlix Hub uninstallation complete."
  info "System packages (PHP, MySQL, HAProxy, certbot) were left installed."
  info "Remove them with 'sudo apt remove ...' if you no longer need them."
  [ "$has_db" = "yes" ] && [ "$drop_db" != "yes" ] \
    && info "MySQL database '${U_DB_NAME}' and user '${U_DB_USER}'@'${U_DB_HOST}' were preserved."
  [ -n "$le_dir" ] && [ "$revoke_cert" != "yes" ] \
    && info "Let's Encrypt certificate '${U_DOMAIN}' was preserved at $le_dir."
}

if [ "$ACTION" = "uninstall" ]; then
  do_uninstall
  exit 0
fi

# ---------------------------------------------------------------------------
# Update
# ---------------------------------------------------------------------------
do_update() {
  log "Phlix Hub updater"

  # Prefer the install path recorded in the systemd unit (unless the user
  # passed --install-path explicitly).
  if [ -f "$SERVICE_FILE" ] && [ "$INSTALL_PATH" = "/opt/phlix-hub" ]; then
    local svc_workdir=""
    svc_workdir="$(awk -F= '/^WorkingDirectory=/{print $2; exit}' "$SERVICE_FILE" 2>/dev/null || true)"
    [ -n "$svc_workdir" ] && INSTALL_PATH="$svc_workdir"
  fi

  # Sanity-check: we need a real install to update.
  [ -f "$ENV_FILE" ]          || die "No env file at $ENV_FILE — run a fresh install first."
  [ -d "$INSTALL_PATH" ]      || die "Install path '$INSTALL_PATH' not found — run a fresh install first."
  [ -d "$INSTALL_PATH/.git" ] || die "Install path '$INSTALL_PATH' is not a git checkout — cannot fast-forward updates."

  # Read existing env so we can run migrations with the right credentials.
  # Use cut -f2- so values containing '=' (unlikely but possible) survive.
  local env_db_name env_db_user env_db_host env_db_port env_db_pass env_hub_port
  env_db_name="$(grep -m1 -E '^HUB_DB_NAME='     "$ENV_FILE" | cut -d= -f2- || true)"
  env_db_user="$(grep -m1 -E '^HUB_DB_USER='     "$ENV_FILE" | cut -d= -f2- || true)"
  env_db_host="$(grep -m1 -E '^HUB_DB_HOST='     "$ENV_FILE" | cut -d= -f2- || true)"
  env_db_port="$(grep -m1 -E '^HUB_DB_PORT='     "$ENV_FILE" | cut -d= -f2- || true)"
  env_db_pass="$(grep -m1 -E '^HUB_DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2- || true)"
  env_hub_port="$(grep -m1 -E '^HUB_PORT='       "$ENV_FILE" | cut -d= -f2- || true)"
  [ -n "$env_db_name" ] || die "HUB_DB_NAME missing from $ENV_FILE — refusing to migrate."

  # The initial install chowns the tree to $SERVICE_USER. Running git as
  # root against a non-root-owned worktree trips CVE-2022-24765 ("dubious
  # ownership"), so detect the owner and run git/composer as that user.
  local repo_owner current_user
  repo_owner="$(stat -c '%U' "$INSTALL_PATH" 2>/dev/null || true)"
  [ -n "$repo_owner" ] || repo_owner="root"
  current_user="$(id -un)"
  local -a as_owner=()
  if [ "$repo_owner" != "$current_user" ]; then
    command -v sudo >/dev/null 2>&1 \
      || die "Install dir owned by '$repo_owner' but sudo is not available."
    as_owner=(sudo -H -u "$repo_owner" --)
  fi

  local prev_commit current_branch
  prev_commit="$("${as_owner[@]}" git -C "$INSTALL_PATH" rev-parse --short HEAD 2>/dev/null || echo unknown)"
  current_branch="$("${as_owner[@]}" git -C "$INSTALL_PATH" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"

  if [ -n "$("${as_owner[@]}" git -C "$INSTALL_PATH" status --porcelain 2>/dev/null)" ]; then
    warn "Uncommitted local changes in $INSTALL_PATH will be discarded by 'git reset --hard'."
  fi

  echo
  log "Update summary"
  info "Install path : $INSTALL_PATH"
  info "Owned by     : $repo_owner"
  info "Env file     : $ENV_FILE  (JWT secret + DB password preserved)"
  info "Database     : ${env_db_user:-?}@${env_db_host:-?}:${env_db_port:-?}/${env_db_name}"
  info "Branch       : $current_branch  ->  $BRANCH"
  info "Commit       : $prev_commit  ->  (fetching…)"
  info "Repo         : $REPO_URL"
  echo
  confirm "Proceed with update?" || die "Aborted by user."

  # 1. Pull updated code as the install dir owner.
  log "Fetching code"
  "${as_owner[@]}" git -C "$INSTALL_PATH" fetch --depth 1 origin "$BRANCH"
  "${as_owner[@]}" git -C "$INSTALL_PATH" checkout "$BRANCH" >/dev/null 2>&1 \
    || "${as_owner[@]}" git -C "$INSTALL_PATH" checkout -B "$BRANCH" "origin/$BRANCH"
  "${as_owner[@]}" git -C "$INSTALL_PATH" reset --hard "origin/$BRANCH"
  local new_commit
  new_commit="$("${as_owner[@]}" git -C "$INSTALL_PATH" rev-parse --short HEAD 2>/dev/null || echo unknown)"
  info "Code: $prev_commit -> $new_commit"

  # 2. Refresh PHP deps. Composer reads composer.lock, so changes ship with
  # the repo and we don't risk surprise upgrades. Run as root with
  # COMPOSER_ALLOW_SUPERUSER set (matches the initial install path); we
  # restore ownership afterwards so vendor/ ends up owned by $repo_owner.
  log "Updating PHP dependencies"
  ( cd "$INSTALL_PATH" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction )

  # 3. Clear Smarty compile cache so templates pick up changes immediately.
  # (compile_check is on by default, but stale entries from earlier renders
  #  occasionally linger after .tpl edits.)
  if [ -d "$INSTALL_PATH/var/smarty" ]; then
    log "Clearing Smarty compile + cache directories"
    rm -rf "$INSTALL_PATH/var/smarty/compile"/* "$INSTALL_PATH/var/smarty/cache"/* 2>/dev/null || true
  fi

  mkdir -p "$INSTALL_PATH/.logs"
  # Restore ownership the install was running with — anything composer or
  # the cache-clear created as root gets chowned back to $repo_owner.
  if [ "$repo_owner" != "root" ] && id -u "$repo_owner" >/dev/null 2>&1; then
    chown -R "$repo_owner:$repo_owner" "$INSTALL_PATH"
  fi

  # 4. Apply pending migrations (idempotent — the runner tracks applied
  # filenames in the `migrations` table).
  log "Running pending migrations"
  HUB_DB_HOST="${env_db_host:-127.0.0.1}" HUB_DB_PORT="${env_db_port:-3306}" \
  HUB_DB_NAME="$env_db_name" HUB_DB_USER="${env_db_user:-phlix_hub}" \
  HUB_DB_PASSWORD="$env_db_pass" \
    php "$INSTALL_PATH/scripts/run-migrations.php"

  # 5. Refresh the systemd unit in case ExecStart / WorkingDirectory drifted,
  # then restart. We don't touch the env file.
  if [ -f "$SERVICE_FILE" ]; then
    log "Restarting phlix-hub service"
    systemctl daemon-reload >/dev/null 2>&1 || true
    systemctl restart phlix-hub
    sleep 2
    if systemctl is-active --quiet phlix-hub; then
      info "phlix-hub service is running."
    else
      warn "phlix-hub did not start cleanly — check 'journalctl -u phlix-hub -e'."
    fi
  else
    warn "No systemd unit at $SERVICE_FILE — start the hub manually."
  fi

  # 6. Health check.
  local hub_port="${env_hub_port:-8800}"
  if curl -fsS --max-time 5 "http://localhost:${hub_port}/health" >/dev/null 2>&1; then
    info "Health check OK: http://localhost:${hub_port}/health"
  else
    warn "Health check did not return success — inspect 'journalctl -u phlix-hub -e'."
  fi

  echo
  log "Phlix Hub update complete."
  info "Branch : $BRANCH"
  info "Commit : $prev_commit -> $new_commit"
  [ "$prev_commit" = "$new_commit" ] && info "(already up to date)"
}

if [ "$ACTION" = "update" ]; then
  do_update
  exit 0
fi

# ---------------------------------------------------------------------------
# Gather configuration
# ---------------------------------------------------------------------------
log "Phlix Hub installer"
[ "$INTERACTIVE" = "yes" ] && info "Interactive mode — press Enter to accept each default." \
                           || info "Non-interactive mode — using defaults/flags."

prompt INSTALL_PATH "Install path" "$INSTALL_PATH"
prompt DB_NAME      "Database name" "$DB_NAME"
prompt DB_USER      "Database user" "$DB_USER"
if [ -z "$DB_PASS" ]; then
  if [ "$INTERACTIVE" = "yes" ]; then
    prompt DB_PASS "Database password (blank = generate random)" ""
  fi
  [ -z "$DB_PASS" ] && DB_PASS="$(rand_pass)" && info "Generated a random database password."
fi
prompt DOMAIN "Public hostname (blank = no TLS, serve plain HTTP)" "$DOMAIN"

if [ -n "$DOMAIN" ] && [ "$WANT_TLS" != "no" ]; then
  prompt ADMIN_EMAIL "Email for Let's Encrypt (blank = skip TLS)" "$ADMIN_EMAIL"
fi

# Resolve final TLS decision.
if [ "$WANT_TLS" = "yes" ]; then
  [ -n "$DOMAIN" ] && [ -n "$ADMIN_EMAIL" ] || die "--tls requires --domain and --admin-email."
  TLS_ENABLED="yes"
elif [ "$WANT_TLS" = "no" ]; then
  TLS_ENABLED="no"
else
  if [ -n "$DOMAIN" ] && [ -n "$ADMIN_EMAIL" ]; then TLS_ENABLED="yes"; else TLS_ENABLED="no"; fi
fi

# Public domain for the env file (used to build per-server subdomains).
PUBLIC_DOMAIN="${DOMAIN:-$(hostname -f 2>/dev/null || hostname)}"
[ -n "$JWT_SECRET" ] || JWT_SECRET="$(rand_hex 32)"

echo
log "Configuration summary"
info "Install path : $INSTALL_PATH"
info "Service user : $SERVICE_USER"
info "Database     : $DB_USER@$DB_HOST:$DB_PORT/$DB_NAME"
info "Public domain: $PUBLIC_DOMAIN"
info "HTTP port    : $HUB_PORT  (relay $RELAY_PORT, client-relay $CLIENT_RELAY_PORT)"
info "TLS / HAProxy: $TLS_ENABLED"
echo
confirm "Proceed with installation?" || die "Aborted by user."

# ---------------------------------------------------------------------------
# 1. System packages
# ---------------------------------------------------------------------------
log "Installing system packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y ca-certificates curl git unzip openssl >/dev/null
# Use the distro's PHP via version-agnostic php-* names. Ubuntu 24.04 ships
# PHP 8.3 by default, which meets the Hub's requirement. Older releases will
# get an older PHP — the version check below will warn if that's the case.
apt-get install -y \
  php-cli php-mysql php-mbstring php-curl php-xml php-bcmath php-gd php-zip \
  mysql-server >/dev/null
[ "$TLS_ENABLED" = "yes" ] && apt-get install -y haproxy certbot >/dev/null || apt-get install -y haproxy >/dev/null

PHP_VER="$(php -r 'echo PHP_VERSION;')"
case "$PHP_VER" in
  8.3*|8.4*|8.5*|9.*) info "PHP $PHP_VER OK";;
  *) warn "PHP $PHP_VER detected — Phlix Hub requires 8.3+. Install may not run correctly.";;
esac

# Composer
if ! command -v composer >/dev/null 2>&1; then
  log "Installing Composer"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer >/dev/null
  rm -f /tmp/composer-setup.php
fi

# ---------------------------------------------------------------------------
# 2. Application code
# ---------------------------------------------------------------------------
log "Fetching application code into $INSTALL_PATH"
if [ -d "$INSTALL_PATH/.git" ]; then
  git -C "$INSTALL_PATH" fetch --depth 1 origin "$BRANCH"
  git -C "$INSTALL_PATH" checkout "$BRANCH"
  git -C "$INSTALL_PATH" reset --hard "origin/$BRANCH"
else
  mkdir -p "$INSTALL_PATH"
  git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$INSTALL_PATH"
fi

log "Installing PHP dependencies"
( cd "$INSTALL_PATH" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction )
mkdir -p "$INSTALL_PATH/.logs"
id -u "$SERVICE_USER" >/dev/null 2>&1 || die "Service user '$SERVICE_USER' does not exist."
chown -R "$SERVICE_USER:$SERVICE_USER" "$INSTALL_PATH"

# ---------------------------------------------------------------------------
# 3. Database
# ---------------------------------------------------------------------------
log "Configuring MySQL database and user"
systemctl enable --now mysql >/dev/null 2>&1 || systemctl enable --now mysqld >/dev/null 2>&1 || true
# Runs as root via the local socket. Idempotent.
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'${DB_HOST}' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'${DB_HOST}' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${DB_HOST}';
FLUSH PRIVILEGES;
SQL

# ---------------------------------------------------------------------------
# 4. Environment file
# ---------------------------------------------------------------------------
log "Writing environment file $ENV_FILE"
cat > "$ENV_FILE" <<EOF
# Phlix Hub environment — generated by install.sh on $(date -u +%FT%TZ)
HUB_HOST=0.0.0.0
HUB_PORT=${HUB_PORT}
HUB_WORKERS=${HUB_WORKERS}
HUB_PUBLIC_DOMAIN=${PUBLIC_DOMAIN}

HUB_DB_HOST=${DB_HOST}
HUB_DB_PORT=${DB_PORT}
HUB_DB_NAME=${DB_NAME}
HUB_DB_USER=${DB_USER}
HUB_DB_PASSWORD=${DB_PASS}

HUB_JWT_SECRET=${JWT_SECRET}
EOF
chmod 600 "$ENV_FILE"
chown root:root "$ENV_FILE"

# ---------------------------------------------------------------------------
# 5. Database migrations
# ---------------------------------------------------------------------------
log "Running database migrations"
HUB_DB_HOST="$DB_HOST" HUB_DB_PORT="$DB_PORT" HUB_DB_NAME="$DB_NAME" \
HUB_DB_USER="$DB_USER" HUB_DB_PASSWORD="$DB_PASS" \
  php "$INSTALL_PATH/scripts/run-migrations.php"

# ---------------------------------------------------------------------------
# 6. systemd service
# ---------------------------------------------------------------------------
log "Installing systemd service"
cat > "$SERVICE_FILE" <<EOF
[Unit]
Description=Phlix Hub
After=network.target mysql.service

[Service]
Type=simple
User=${SERVICE_USER}
Group=${SERVICE_USER}
EnvironmentFile=${ENV_FILE}
WorkingDirectory=${INSTALL_PATH}
ExecStart=/usr/bin/php ${INSTALL_PATH}/public/index.php start
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now phlix-hub
sleep 2
systemctl is-active --quiet phlix-hub && info "phlix-hub service is running." \
                                       || warn "phlix-hub service did not start — check 'journalctl -u phlix-hub'."

# ---------------------------------------------------------------------------
# 7. Reverse proxy (HAProxy) + TLS (certbot)
# ---------------------------------------------------------------------------
write_haproxy_cfg() {
  # $1 = "tls" | "http"
  local mode="$1"
  [ -f /etc/haproxy/haproxy.cfg ] && cp -n /etc/haproxy/haproxy.cfg /etc/haproxy/haproxy.cfg.phlix.bak || true
  {
    cat <<'GLOBAL'
global
    log /dev/log local0
    maxconn 4096
    tune.ssl.default-dh-param 2048

defaults
    log     global
    mode    http
    option  httplog
    option  forwardfor
    timeout connect 5s
    timeout client  1h
    timeout server  1h
    timeout tunnel  1h
GLOBAL
    if [ "$mode" = "tls" ]; then
      cat <<TLSFE
frontend fe_http
    bind :80
    redirect scheme https code 301
frontend fe_https
    bind :443 ssl crt /etc/haproxy/certs/${DOMAIN}.pem
    http-request set-header X-Forwarded-Proto https
    use_backend be_client_relay if { path_beg /client/ }
    default_backend be_hub
TLSFE
    else
      cat <<HTTPFE
frontend fe_http
    bind :80
    use_backend be_client_relay if { path_beg /client/ }
    default_backend be_hub
HTTPFE
    fi
    cat <<BACKEND
backend be_hub
    server hub 127.0.0.1:${HUB_PORT}
backend be_client_relay
    server clientrelay 127.0.0.1:${CLIENT_RELAY_PORT}
BACKEND
  } > /etc/haproxy/haproxy.cfg
}

if [ "$TLS_ENABLED" = "yes" ]; then
  log "Obtaining TLS certificate for $DOMAIN via certbot"
  mkdir -p /etc/haproxy/certs
  systemctl stop haproxy >/dev/null 2>&1 || true
  if certbot certonly --standalone --non-interactive --agree-tos \
        -m "$ADMIN_EMAIL" -d "$DOMAIN" --keep-until-expiring; then
    cat "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" \
        "/etc/letsencrypt/live/${DOMAIN}/privkey.pem" > "/etc/haproxy/certs/${DOMAIN}.pem"
    chmod 600 "/etc/haproxy/certs/${DOMAIN}.pem"

    # Deploy hook: rebuild combined PEM + reload HAProxy after each renewal.
    mkdir -p /etc/letsencrypt/renewal-hooks/deploy
    cat > /etc/letsencrypt/renewal-hooks/deploy/phlix-haproxy.sh <<HOOK
#!/bin/sh
cat "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" \\
    "/etc/letsencrypt/live/${DOMAIN}/privkey.pem" > "/etc/haproxy/certs/${DOMAIN}.pem"
chmod 600 "/etc/haproxy/certs/${DOMAIN}.pem"
systemctl reload haproxy 2>/dev/null || systemctl restart haproxy
HOOK
    chmod +x /etc/letsencrypt/renewal-hooks/deploy/phlix-haproxy.sh

    # Monthly auto-renewal (1st of the month, 03:00). certbot only renews when
    # the certificate is near expiry; HAProxy is stopped briefly for the
    # standalone challenge, then the deploy hook rebuilds the PEM and reloads.
    cat > /etc/cron.d/phlix-hub-certbot <<CRON
# Phlix Hub: monthly Let's Encrypt renewal
0 3 1 * * root certbot renew --quiet --pre-hook "systemctl stop haproxy" --post-hook "systemctl start haproxy" --deploy-hook /etc/letsencrypt/renewal-hooks/deploy/phlix-haproxy.sh
CRON

    write_haproxy_cfg tls
  else
    warn "certbot failed (is DNS for $DOMAIN pointed here and port 80 reachable?). Falling back to plain HTTP."
    TLS_ENABLED="no"
    write_haproxy_cfg http
  fi
else
  log "Configuring HAProxy (plain HTTP)"
  write_haproxy_cfg http
fi

haproxy -c -f /etc/haproxy/haproxy.cfg >/dev/null 2>&1 || die "HAProxy config validation failed."
systemctl enable haproxy >/dev/null 2>&1 || true
systemctl restart haproxy

# Best-effort firewall openings.
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
  for p in 80 443 "$RELAY_PORT"; do ufw allow "$p"/tcp >/dev/null 2>&1 || true; done
  info "Opened ports 80, 443, and $RELAY_PORT in ufw."
fi

# ---------------------------------------------------------------------------
# 8. Done
# ---------------------------------------------------------------------------
echo
log "Phlix Hub installation complete"
if [ "$TLS_ENABLED" = "yes" ]; then
  info "URL          : https://${DOMAIN}/"
  info "Health check : curl https://${DOMAIN}/health"
else
  info "URL          : http://${PUBLIC_DOMAIN}/  (or http://<server-ip>/)"
  info "Health check : curl http://localhost:${HUB_PORT}/health"
  [ -n "$DOMAIN" ] || info "Re-run with --domain and --admin-email to enable HTTPS."
fi
info "Service      : systemctl status phlix-hub"
info "Env file     : ${ENV_FILE}  (HUB_JWT_SECRET + DB password stored here)"
info "Database pass : ${DB_PASS}"
echo
info "Next: open the URL and create the first account at /signup — it is"
info "automatically promoted to admin."
