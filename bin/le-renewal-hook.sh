#!/usr/bin/env bash
#
# le-renewal-hook.sh — certbot deploy hook that keeps the LVChat WSS gateway's
# staged TLS cert in sync with Let's Encrypt renewals.
#
# Why this exists: the gateway reads its TLS cert/key from data/tls/ (not from
# the panel's ssl/ dir), and on HestiaCP those source files are root-only, so a
# deploy run as the site user can't re-stage them. Certbot runs deploy hooks as
# ROOT after every successful renewal, so this script can read the source,
# re-stage data/tls/, and restart the gateway as the site user — making cert
# rotation automatic instead of waiting for a manual root deploy.
#
# Install (as root, once per server):
#   sudo cp bin/le-renewal-hook.sh /etc/letsencrypt/renewal-hooks/deploy/20-lvchat-wss.sh
#   sudo chmod +x /etc/letsencrypt/renewal-hooks/deploy/20-lvchat-wss.sh
#
# Verify:  sudo certbot renew --dry-run   then check data/logs/le-hook.log.
#
# Safety: this hook NEVER fails a renewal — anything unexpected just logs to
# data/logs/le-hook.log and exits 0.
#
set -uo pipefail

PHP=$(command -v php || echo php)
LOG="/tmp/lvchat-le-renewal.log"

log() { printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >>"$LOG"; }

# ── Locate the app directory (HestiaCP layout: /home/<user>/web/<domain>/public_html) ──
# LVCHAT_HOOK_ROOT overrides the search for non-standard layouts (and tests).
ROOT="${LVCHAT_HOOK_ROOT:-}"
if [ -n "$ROOT" ] && [ ! -f "$ROOT/bin/ws-server.php" ]; then
    ROOT=""
fi
if [ -z "$ROOT" ]; then
    for d in /home/*/web/*/public_html; do
        [ -d "$d" ] || continue
        if [ -f "$d/bin/ws-server.php" ] && [ -f "$d/src/bootstrap.php" ]; then
            ROOT="$(cd "$d" && pwd)"
            break
        fi
    done
fi
if [ -z "$ROOT" ] && [ -f "$(cd "$(dirname "$0")/.." && pwd)/bin/ws-server.php" ]; then
    ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fi
if [ -z "$ROOT" ]; then
    exit 0 # the app is not deployed on this box — nothing to keep fresh
fi
LOG="$ROOT/data/logs/le-hook.log"
mkdir -p "$(dirname "$LOG")" 2>/dev/null || true
log "le-renewal-hook: begin"

cd "$ROOT" || exit 0

# Only WebSocket realtime needs the staged WSS cert.
RT=$("$PHP" -r 'require "src/bootstrap.php"; echo config_get("realtime", "poll");' 2>/dev/null || echo poll)
if [ "$RT" != "ws" ]; then
    log "realtime=$RT — no WSS staging needed"
    exit 0
fi

# ── Resolve the site user/domain from the app path ───────────────────────────
SITE_USER=""
SITE_DOMAIN=""
if [[ "$ROOT" =~ ^/home/([^/]+)/web/([^/]+)/public_html ]]; then
    SITE_USER="${BASH_REMATCH[1]}"
    SITE_DOMAIN="${BASH_REMATCH[2]}"
fi
[ -z "$SITE_USER" ] && SITE_USER=$(stat -c '%U' "$ROOT" 2>/dev/null || true)
[ -z "$SITE_USER" ] && SITE_USER=$(id -un)

# ── Resolve the source cert/key (panel ssl/ dir first, certbot live/ as fallback) ──
# LVCHAT_HOOK_SRC_DIR overrides the source directory for non-standard layouts.
SRC_CERT=""
SRC_KEY=""
if [ -n "${LVCHAT_HOOK_SRC_DIR:-}" ]; then
    SRC_DIR="${LVCHAT_HOOK_SRC_DIR%/}/"
    if [ -f "${SRC_DIR}fullchain.pem" ] && [ -f "${SRC_DIR}privkey.pem" ]; then
        SRC_CERT="${SRC_DIR}fullchain.pem"
        SRC_KEY="${SRC_DIR}privkey.pem"
    fi
fi
if [ -n "$SITE_DOMAIN" ]; then
    for s in "/home/$SITE_USER/conf/web/$SITE_DOMAIN/ssl/" /home/*/conf/web/"$SITE_DOMAIN"/ssl/; do
        [ -d "$s" ] || continue
        if [ -z "$SRC_CERT" ] && [ -f "${s}fullchain.pem" ]; then
            SRC_CERT="${s}fullchain.pem"
        fi
        if [ -z "$SRC_CERT" ] && [ -f "${s}${SITE_DOMAIN}.pem" ]; then
            SRC_CERT="${s}${SITE_DOMAIN}.pem"
        fi
        if [ -z "$SRC_KEY" ] && [ -f "${s}privkey.pem" ]; then
            SRC_KEY="${s}privkey.pem"
        fi
        if [ -z "$SRC_KEY" ] && [ -f "${s}${SITE_DOMAIN}.key" ]; then
            SRC_KEY="${s}${SITE_DOMAIN}.key"
        fi
        [ -n "$SRC_CERT" ] && [ -n "$SRC_KEY" ] && break
    done
fi
if [ -z "$SRC_CERT" ] && [ -n "$SITE_DOMAIN" ] && [ -f "/etc/letsencrypt/live/$SITE_DOMAIN/fullchain.pem" ]; then
    SRC_CERT="/etc/letsencrypt/live/$SITE_DOMAIN/fullchain.pem"
    SRC_KEY="/etc/letsencrypt/live/$SITE_DOMAIN/privkey.pem"
fi
if [ -z "$SRC_CERT" ] || [ -z "$SRC_KEY" ] || [ ! -f "$SRC_CERT" ] || [ ! -f "$SRC_KEY" ]; then
    log "source cert/key not found (cert=$SRC_CERT key=$SRC_KEY) — nothing to do"
    exit 0
fi

# ── Stage the new cert if the fingerprint actually changed ───────────────────
NEWCERT="$ROOT/data/tls/fullchain.pem"
NEWKEY="$ROOT/data/tls/privkey.pem"
mkdir -p "$ROOT/data/tls" 2>/dev/null || true

SRC_CERT_HASH=$(sha256sum "$SRC_CERT" 2>/dev/null | awk '{print $1}') || true
SRC_KEY_HASH=$(sha256sum "$SRC_KEY" 2>/dev/null | awk '{print $1}') || true
CUR_CERT_HASH=$(sha256sum "$NEWCERT" 2>/dev/null | awk '{print $1}') || true
CUR_KEY_HASH=$(sha256sum "$NEWKEY" 2>/dev/null | awk '{print $1}') || true

if [ "$SRC_CERT_HASH" = "$CUR_CERT_HASH" ] && [ "$SRC_KEY_HASH" = "$CUR_KEY_HASH" ]; then
    log "staged files already current — no change"
    exit 0
fi

if ! cp "$SRC_CERT" "$NEWCERT" 2>/dev/null; then
    log "ERROR: could not copy $SRC_CERT -> $NEWCERT"
    exit 0
fi
if ! cp "$SRC_KEY" "$NEWKEY" 2>/dev/null; then
    log "ERROR: could not copy $SRC_KEY -> $NEWKEY"
    exit 0
fi

SITE_UG=$(stat -c '%U:%G' "$ROOT" 2>/dev/null || printf '%s:%s' "$SITE_USER" "$(id -gn)")
chown "$SITE_UG" "$NEWCERT" "$NEWKEY" 2>/dev/null || true
chmod 644 "$NEWCERT" 2>/dev/null || true
chmod 640 "$NEWKEY" 2>/dev/null || true
log "staged renewed cert: cert=$SRC_CERT key=$SRC_KEY (sha256 $SRC_CERT_HASH/$SRC_KEY_HASH)"

# ── Restart the gateway as the site user (systemd preferred, else su) ───────
RESTARTED=0
UNIT=$(systemctl list-unit-files --no-legend --type=service 2>/dev/null | awk '{print $1}' | grep -iE '^(chat|lvc|ws-server|lvchat|realtime)' | head -1 || true)
if [ -n "$UNIT" ] && systemctl restart "$UNIT" >/dev/null 2>&1; then
    RESTARTED=1
    log "gateway restarted via systemd ($UNIT)"
elif [ "$(id -u)" = "0" ] && [ -n "$SITE_USER" ]; then
    if su -s /bin/sh "$SITE_USER" -c "cd '$ROOT' && '$PHP' bin/ws-server.php restart -d" >/dev/null 2>&1; then
        RESTARTED=1
        log "gateway restarted as $SITE_USER (manual start)"
    fi
fi
if [ "$RESTARTED" = "0" ]; then
    log "WARN: could not restart the gateway automatically — restart it under Admin → Settings → WebSocket gateway"
fi

log "le-renewal-hook: done"
exit 0
