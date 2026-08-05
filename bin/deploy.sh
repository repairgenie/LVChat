#!/usr/bin/env bash
#
# deploy.sh — run this ON THE SERVER after every upload / fresh install.
#
# It restores everything that uploaders commonly strip or that environments
# need bootstrapped:
#   1. public/.htaccess      (dotfiles are often dropped by upload tools)
#   2. SQLite database       (migrated outside the webroot, never overwritten)
#   3. Runtime check         (PHP extensions + shipped assets)
#   4. HTTP sanity check     (front controller + assets respond)
#   5. Realtime gateway      (optional: composer/vendor + daemon health)
#
# No Node, no npm, no build step is required. Usage:  bash bin/deploy.sh
#
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"

echo "== 1/4 Front controller (.htaccess) =="
cat > public/.htaccess <<'APACHE'
# LVChat — front-controller rewrite.
# Routes clean URLs (/login, /register, /app, /c/..., /admin/...) to index.php.
# Existing files and directories (assets, js, etc.) are served directly.
DirectoryIndex index.php

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</IfModule>
APACHE
echo "public/.htaccess written."

echo "== 2/4 Database =="
NEW_DB="${CHAT_DB:-$ROOT/data/chat.db}"
# A previous release stored the DB one level above the project; migrate it back in.
LEGACY_DB="$(dirname "$ROOT")/data/chat.db"
mkdir -p "$(dirname "$NEW_DB")"
if [ -f "$NEW_DB" ]; then
    echo "Database present: $NEW_DB"
elif [ -f "$LEGACY_DB" ]; then
    mv "$LEGACY_DB" "$NEW_DB"
    rm -f "$LEGACY_DB-wal" "$LEGACY_DB-shm"
    echo "Migrated existing database -> $NEW_DB"
else
    echo "No database yet — created on first request at $NEW_DB"
fi
# Safety copy if we are about to run anything that could touch it.
if [ -f "$NEW_DB" ]; then
    cp "$NEW_DB" "$NEW_DB.bak.$(date +%Y%m%d-%H%M%S)" 2>/dev/null && echo "Backed up database."
fi

echo "== 3/4 Runtime check =="
echo "PHP version: $(php -v | head -1)"
php -m | grep -qi 'pdo_sqlite' && echo "pdo_sqlite: OK" || { echo "ERROR: pdo_sqlite extension missing."; exit 1; }
[ -f public/assets/js/app.js ] && echo "app.js: present" || { echo "ERROR: public/assets/js/app.js is missing."; exit 1; }
[ -f public/assets/css/app.css ] && echo "app.css: present" || { echo "ERROR: public/assets/css/app.css is missing (run npm run build locally)."; exit 1; }
[ -f public/sw.js ] && echo "sw.js: present" || { echo "ERROR: public/sw.js is missing (PWA service worker)."; exit 1; }
[ -f public/assets/pwa/icon-192.png ] && [ -f public/assets/pwa/icon-512.png ] && echo "pwa icons: present" || { echo "ERROR: PWA icons are missing (public/assets/pwa/*)."; exit 1; }

echo "== 4/4 HTTP sanity check =="
PORT="${DEPLOY_PORT:-8095}"
php -S "127.0.0.1:$PORT" -t public >/dev/null 2>&1 &
SERVER_PID=$!
cleanup() { kill "$SERVER_PID" 2>/dev/null || true; }
trap cleanup EXIT
sleep 1
FAIL=0
check() {
    local url="$1" want="$2"
    local code
    code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT$url" || echo 000)
    if [ "$code" = "$want" ]; then
        echo "  ok   $url -> $code"
    else
        echo "  BAD  $url -> $code (wanted $want)"
        FAIL=1
    fi
}
check / 302
check /login 200
check /register 200
check /api/version 200
check /does-not-exist 404
check /manifest 200
check /sw.js 200

# Verify the JS asset is actually JavaScript (a broken rewrite would serve HTML here).
JSBODY=$(curl -s "http://127.0.0.1:$PORT/assets/js/app.js" || true)
case "$JSBODY" in
    *'(() =>'*) echo "  ok   app.js served as JavaScript" ;;
    *)  echo "  BAD  app.js served non-JS content (is the file present and the rewrite sane?)"; FAIL=1 ;;
esac

# Verify the service worker is served as JavaScript too (needed for PWA install).
SWBODY=$(curl -s "http://127.0.0.1:$PORT/sw.js" || true)
case "$SWBODY" in
    *"'use strict'"*|*CACHE_STATIC*) echo "  ok   sw.js served as JavaScript" ;;
    *)  echo "  BAD  sw.js served non-JS content (service worker will not register)"; FAIL=1 ;;
esac

# Verify the PWA manifest is real JSON with a valid MIME type.
MANIFEST=$(curl -s -D- "http://127.0.0.1:$PORT/manifest" || true)
MANIFEST_CT=$(printf '%s' "$MANIFEST" | tr -d '\r' | grep -i '^content-type:' | head -1)
case "$MANIFEST_CT" in
    *manifest+json*) echo "  ok   /manifest -> $MANIFEST_CT" ;;
    *)  echo "  BAD  /manifest served as $MANIFEST_CT (wanted application/manifest+json)"; FAIL=1 ;;
esac

# Verify the CSS asset is actual CSS, not a rewritten HTML page.
CSSBODY=$(curl -s "http://127.0.0.1:$PORT/assets/css/app.css" || true)
case "$CSSBODY" in
    *'html'*|*'--tw-'*) echo "  ok   app.css served as CSS" ;;
    *) echo "  BAD  app.css served non-CSS content"; FAIL=1 ;;
esac

VERSION=$(curl -s "http://127.0.0.1:$PORT/api/version" || echo '{}')
echo "  version: $VERSION"

if [ "$FAIL" = "0" ]; then
    echo ""
    echo "Deploy check passed. The app is ready."
else
    echo ""
    echo "Deploy check FAILED — review the output above." >&2
    exit 1
fi

echo ""
echo "== 5/5 Realtime gateway (optional) =="
RT=$(php -r 'require "src/bootstrap.php"; echo config_get("realtime", "poll");' 2>/dev/null || echo poll)
if [ "$RT" = "ws" ]; then
    if [ ! -f vendor/autoload.php ]; then
        if command -v composer >/dev/null 2>&1; then
            echo "vendor/ missing — running composer install --no-dev (needed for the WebSocket gateway)."
            composer install --no-dev --no-interaction
        else
            echo "WARN: realtime=ws is set but vendor/autoload.php is missing and composer is not installed."
            echo "      The gateway won't run; clients fall back to polling. Install composer and re-run."
        fi
    else
        echo "vendor/: present"
    fi
    HEALTH=$(php -r 'require "src/bootstrap.php"; $u = parse_url(Realtime::pushUrl()); echo $u["scheme"] . "://" . $u["host"] . (isset($u["port"]) ? ":" . $u["port"] : "") . "/health";' 2>/dev/null || echo 'http://127.0.0.1:9001/health')
    if curl -s --max-time 2 "$HEALTH" >/dev/null 2>&1; then
        echo "gateway: running ($HEALTH)"
    else
        echo "WARN: realtime=ws is set but the gateway isn't responding at $HEALTH."
        echo "      Start it with:  php bin/ws-server.php start -d   (or the systemd unit in the README)"
    fi
else
    echo "realtime mode: $RT (gateway not required)"
fi
