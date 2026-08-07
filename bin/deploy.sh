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
#   6. WSS / TLS             (optional: stage the site cert for the gateway)
#
# ── WSS / TLS ─────────────────────────────────────────────────────────────
# When Realtime mode = WebSocket, deploy.sh configures the gateway to serve
# wss:// using the same TLS cert the site already serves over https.
#
# Which cert: the site's OWN pair is preferred — on HestiaCP that's
#   /home/<user>/conf/web/<domain>/nginx.ssl.conf  (ssl_certificate / _key),
#   certs under /home/<user>/conf/web/<domain>/ssl/. Falls back to a general
#   scan (certbot, Plesk, cPanel, DirectAdmin) only if the site pair can't be
#   resolved; on multi-domain boxes it prefers the site pair and warns only if
#   that can't be resolved.
#
# Ownership: the gateway runs as the OWNER of the app directory (e.g. george),
# NOT www-data. The staged key (data/tls/privkey.pem) is chowned to that user,
# mode 640, and deploy.sh aborts loudly if the gateway user can't read it.
# Run deploy.sh as root (sudo bash bin/deploy.sh) or as that user.
#
# Renewal: the staged files are re-derived from the source cert on every deploy
# and refreshed whenever the fingerprint changes (Let's Encrypt rotation), then
# the gateway is restarted AS THE WEB USER (systemd unit preferred) — never as
# root, so the admin panel keeps managing the daemon.
#
# Verify:
#   su - <siteuser> -c "head -c 10 .../data/tls/privkey.pem"     # readable
#   echo | openssl s_client -connect <host>:<ws_port> -servername <domain> \
#     2>/dev/null | grep -E 'subject=|issuer=|Cipher is|Verify return'
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

# Make composer available for the gateway: a system install if we're root,
# otherwise a local composer.phar inside data/ (no root needed). Returns 0
# when a working composer is available afterwards.
ensure_composer() {
    if command -v composer >/dev/null 2>&1; then
        return 0
    fi
    if [ "$(id -u)" = "0" ] && command -v apt-get >/dev/null 2>&1; then
        apt-get install -y composer >/dev/null 2>&1 && command -v composer >/dev/null 2>&1 && return 0
    fi
    if [ ! -f "$ROOT/data/composer.phar" ]; then
        if command -v curl >/dev/null 2>&1; then
            curl -sS https://getcomposer.org/installer -o "$ROOT/data/composer-setup.php" 2>/dev/null \
                && php "$ROOT/data/composer-setup.php" --install-dir="$ROOT/data" --filename=composer.phar >/dev/null 2>&1
            rm -f "$ROOT/data/composer-setup.php"
        else
            php -r "copy('https://getcomposer.org/installer', '$ROOT/data/composer-setup.php');" 2>/dev/null \
                && php "$ROOT/data/composer-setup.php" --install-dir="$ROOT/data" --filename=composer.phar >/dev/null 2>&1
            rm -f "$ROOT/data/composer-setup.php"
        fi
    fi
    [ -f "$ROOT/data/composer.phar" ]
}

# composer install against the system binary or the local phar.
composer_install() {
    if command -v composer >/dev/null 2>&1; then
        composer install --no-dev --no-interaction
    elif [ -f "$ROOT/data/composer.phar" ]; then
        php "$ROOT/data/composer.phar" install --no-dev --no-interaction
    else
        return 1
    fi
}

# A port is "free" when we can bind a listen socket to it on all interfaces.
port_is_free() {
    php -r '$s = @stream_socket_server("tcp://0.0.0.0:'"$1"'", $e, $str, STREAM_SERVER_BIND); if ($s) { fclose($s); exit(0); } exit(1);' 2>/dev/null
}
first_free_port() {
    for p in $(seq 8080 8089); do
        if port_is_free "$p"; then echo "$p"; return 0; fi
    done
    return 1
}

if [ "$RT" = "ws" ]; then
    if [ ! -f vendor/autoload.php ]; then
        echo "vendor/ missing — ensuring composer is available."
        if ensure_composer; then
            if composer_install; then
                echo "vendor/: Workerman installed"
            else
                echo "WARN: composer install failed (no internet or no write permission in this directory)."
                echo "      The gateway needs vendor/ to run. Clients fall back to polling until it succeeds."
            fi
        else
            echo "WARN: composer is not installed and could not be fetched (this needs internet access)."
            echo "      Install it manually — e.g.  sudo apt install composer  — then re-run deploy.sh."
        fi
    else
        echo "vendor/: present"
    fi
    if ! php -r 'exit(function_exists("pcntl_fork") && function_exists("posix_kill") ? 0 : 1);' 2>/dev/null; then
        echo "WARN: the pcntl/posix PHP extensions are missing — the gateway can't fork its workers."
        echo "      Install them:  sudo apt install php-cli   (verify:  php -m | grep -iE 'pcntl|posix')"
    fi
    HEALTH=$(php -r 'require "src/bootstrap.php"; $u = parse_url(Realtime::pushUrl()); echo $u["scheme"] . "://" . $u["host"] . (isset($u["port"]) ? ":" . $u["port"] : "") . "/health";' 2>/dev/null || echo 'http://127.0.0.1:9001/health')
    if curl -s --max-time 2 "$HEALTH" >/dev/null 2>&1; then
        echo "gateway: running ($HEALTH)"
    else
        # Gateway is down: make sure ws_port still points at a usable port.
        CUR_PORT=$(php -r 'require "src/bootstrap.php"; echo (int) (config_get("ws_port", "8080") ?? 8080);' 2>/dev/null || echo 8080)
        if port_is_free "$CUR_PORT"; then
            echo "ws_port $CUR_PORT: free (gateway not running — start it with  php bin/ws-server.php start -d)"
        else
            NEW_PORT=$(first_free_port)
            if [ -n "$NEW_PORT" ]; then
                php -r 'require "src/bootstrap.php"; config_set("ws_port", "'"$NEW_PORT"'");'
                echo "ws_port $CUR_PORT is in use — auto-selected first free port 8080-8089: $NEW_PORT"
                echo "      Restart the gateway to apply:  php bin/ws-server.php restart   (or the systemd unit)"
            else
                echo "WARN: no free port in 8080-8089. Free one, or set ws_ip/ws_port manually under Admin → Settings → Realtime mode → WebSocket."
            fi
        fi
        echo "      Start it with:  php bin/ws-server.php start -d   (or the systemd unit in the README)"
    fi

    # ── WSS / TLS ─────────────────────────────────────────────────────────────
    # Auto-configures wss:// from the site's TLS cert.
    #  - Ownership: the gateway runs as the OWNER of the app directory (george on
    #    HestiaCP), NOT www-data. The staged private key is chowned to that user
    #    (mode 640) and a runtime read-check fails loudly if the gateway user
    #    can't read it.
    #  - Renewal: the staged files are re-derived from the source cert every
    #    deploy and refreshed when the fingerprint changes (Let's Encrypt
    #    rotation), then the gateway is restarted AS THE WEB USER (systemd unit
    #    preferred), never as root.
    SITE_USER=""
    SITE_DOMAIN=""
    if [[ "$ROOT" =~ ^/home/([^/]+)/web/([^/]+)/public_html ]]; then
        SITE_USER="${BASH_REMATCH[1]}"
        SITE_DOMAIN="${BASH_REMATCH[2]}"
    fi
    [ -z "$SITE_USER" ] && SITE_USER=$(stat -c '%U' "$ROOT" 2>/dev/null || true)
    [ -z "$SITE_USER" ] && SITE_USER=$(id -un)
    [ -n "$SITE_DOMAIN" ] && echo "WSS: site = $SITE_USER / $SITE_DOMAIN"

    # Append CERT|KEY|CHAIN (CHAIN empty unless a separate chain file exists) to
    # the global PAIRS by parsing nginx/apache ssl directives from a config file.
    parse_ssl_config() {
        local conf="$1" cert="" key=""
        while IFS= read -r line; do
            case "$line" in
                *ssl_certificate_key*) key=$(printf '%s' "$line" | sed -nE 's/.*ssl_certificate_key[[:space:]]+([^;]+);.*/\1/p' | sed -E 's/^[[:space:]"\x27]+|[[:space:]"\x27]+$//g');;
                *ssl_certificate*) cert=$(printf '%s' "$line" | sed -nE 's/.*ssl_certificate[[:space:]]+([^;]+);.*/\1/p' | sed -E 's/^[[:space:]"\x27]+|[[:space:]"\x27]+$//g');;
                *SSLCertificateKeyFile*) key=$(printf '%s' "$line" | sed -nE 's/.*SSLCertificateKeyFile[[:space:]]+([^[:space:]]+).*/\1/p' | sed -E 's/^[[:space:]"\x27]+|[[:space:]"\x27]+$//g');;
                *SSLCertificateFile*) cert=$(printf '%s' "$line" | sed -nE 's/.*SSLCertificateFile[[:space:]]+([^[:space:]]+).*/\1/p' | sed -E 's/^[[:space:]"\x27]+|[[:space:]"\x27]+$//g');;
            esac
            # Hestia control-panel cert — never a web domain cert.
            case "$cert" in /usr/local/hestia/ssl/*) cert="";; esac
            if [ -n "$cert" ] && [ -n "$key" ]; then
                [ -f "$cert" ] && [ -f "$key" ] && PAIRS="$PAIRS$cert|$key|"$'\n'
                cert=""
                key=""
            fi
        done < "$conf"
    }

    # Append a cert+key pair found in a HestiaCP ssl/ dir (prefer fullchain /
    # <domain>.pem, avoid combined.pem which bundles the private key).
    ssl_dir_pair() {
        local s="$1" C="" K=""
        [ -f "${s}fullchain.pem" ] && C="${s}fullchain.pem"
        if [ -z "$C" ] && [ -n "$SITE_DOMAIN" ] && [ -f "${s}${SITE_DOMAIN}.pem" ]; then
            C="${s}${SITE_DOMAIN}.pem"
        fi
        if [ -z "$C" ]; then
            for c in "${s}"*.pem; do
                [ -f "$c" ] || continue
                case "$c" in *combined.pem) continue;; esac
                C="$c" && break
            done
        fi
        if [ -z "$C" ]; then
            for c in "${s}"*.crt; do [ -f "$c" ] && C="$c" && break; done
        fi
        for k in "${s}privkey.pem" "${s}"*.key; do
            [ -f "$k" ] && K="$k" && break
        done
        [ -n "$C" ] && [ -n "$K" ] && PAIRS="$PAIRS$C|$K|"$'\n'
    }

    PAIRS=""
    # (A) THIS site's own pair first — its nginx.ssl.conf is the source of truth
    #     for the cert the site already serves over https.
    if [ -n "$SITE_DOMAIN" ]; then
        for conf in "/home/$SITE_USER/conf/web/$SITE_DOMAIN/nginx.ssl.conf" \
                    "/home/$SITE_USER/conf/web/$SITE_DOMAIN/nginx.conf" \
                    /home/*/conf/web/"$SITE_DOMAIN"/nginx.ssl.conf; do
            [ -f "$conf" ] && parse_ssl_config "$conf"
        done
        for s in /home/*/conf/web/"$SITE_DOMAIN"/ssl/; do
            [ -d "$s" ] && ssl_dir_pair "$s"
        done
    fi
    # (B) General detection across every panel layout if the site's own pair
    #     could not be resolved (avoids the "multiple TLS certs" dead end on
    #     multi-domain boxes).
    if [ -z "$PAIRS" ]; then
        for conf in /etc/nginx/sites-enabled/* /etc/nginx/conf.d/* /etc/nginx/sites-available/* \
                    /home/*/conf/web/*/nginx*.conf \
                    /etc/apache2/sites-enabled/* /etc/apache2/sites-available/* /etc/apache2/conf.d/* \
                    /etc/httpd/conf.d/* /etc/httpd/conf/*; do
            [ -f "$conf" ] && parse_ssl_config "$conf"
        done
        for d in /etc/letsencrypt/live/*/ /opt/psa/var/modules/letsencrypt/etc/live/*/; do
            [ -d "$d" ] || continue
            if [ -f "${d}fullchain.pem" ] && [ -f "${d}privkey.pem" ]; then
                PAIRS="$PAIRS${d}fullchain.pem|${d}privkey.pem|"$'\n'
            fi
        done
        for s in /home/*/conf/web/*/ssl/; do
            [ -d "$s" ] && ssl_dir_pair "$s"
        done
        for cdir in /home/*/ssl/certs/; do
            [ -d "$cdir" ] || continue
            for c in "${cdir}"*.crt; do
                [ -f "$c" ] || continue
                base=$(basename "$c")
                k="${cdir%certs/}keys/${base%.crt}.key"
                [ -f "$k" ] && PAIRS="$PAIRS$c|$k|"$'\n'
            done
        done
        for c in /usr/local/psa/var/certificates/*-cert.pem; do
            [ -f "$c" ] || continue
            k="${c%-cert.pem}-key.pem"
            [ -f "$k" ] || continue
            ch="${c%-cert.pem}-chain.pem"
            if [ -f "$ch" ]; then PAIRS="$PAIRS$c|$k|$ch"$'\n'; else PAIRS="$PAIRS$c|$k|"$'\n'; fi
        done
        for c in /usr/local/directadmin/data/users/*/domains/*.cert; do
            [ -f "$c" ] || continue
            k="${c%.cert}.key"
            [ -f "$k" ] || continue
            ch="${c%.cert}.cacert"
            if [ -f "$ch" ]; then PAIRS="$PAIRS$c|$k|$ch"$'\n'; else PAIRS="$PAIRS$c|$k|"$'\n'; fi
        done
        if [ -z "$PAIRS" ]; then
            FOUND=$(find /etc/letsencrypt /opt/psa /usr/local/psa /usr/local/directadmin /usr/local/lsws /home/*/conf /home/*/ssl /etc/ssl -maxdepth 6 -type f \( -name 'fullchain.pem' -o -name '*.crt' \) 2>/dev/null || true)
            for c in $FOUND; do
                [ -f "$c" ] || continue
                case "$c" in /usr/local/hestia/*) continue;; esac
                d=$(dirname "$c")
                k=""
                for kc in "$d/privkey.pem" "${c%.crt}.key" "$d"/*.key; do
                    [ -f "$kc" ] && k="$kc" && break
                done
                [ -n "$k" ] && PAIRS="$PAIRS$c|$k|"$'\n'
            done
        fi
    fi

    # Resolve the SOURCE pair. Detection output wins over the config so the
    # staged files always track the panel's live cert (renewal-safe).
    WSCERT=$(php -r 'require "src/bootstrap.php"; echo (string) (config_get("ws_ssl_cert", "") ?? "");' 2>/dev/null)
    WSKEY=$(php -r 'require "src/bootstrap.php"; echo (string) (config_get("ws_ssl_key", "") ?? "");' 2>/dev/null)
    WSCHAIN=""
    PAIRS=$(printf '%s' "$PAIRS" | awk 'NF' | sort -u)
    PAIR_COUNT=$(printf '%s\n' "$PAIRS" | grep -c '|' || true)
    if [ "$PAIR_COUNT" = "1" ]; then
        PAIR=$(printf '%s\n' "$PAIRS" | head -1)
        WSCERT="${PAIR%%|*}"
        WSREST=$(printf '%s' "${PAIR#*|}")
        WSKEY="${WSREST%%|*}"
        WSCHAIN=$(printf '%s' "${WSREST#*|}")
        echo "WSS: using TLS cert ($WSCERT)"
    elif [ "$PAIR_COUNT" -gt "1" ]; then
        echo "WARN: multiple TLS certs found — set ws_ssl_cert/ws_ssl_key in Admin → Settings → WebSocket gateway."
    fi

    SSL_CHANGED=0
    if [ -n "$WSCERT" ] && [ -n "$WSKEY" ] && [ -f "$WSCERT" ] && [ -f "$WSKEY" ]; then
        mkdir -p "$ROOT/data/tls"
        NEWCERT="$ROOT/data/tls/fullchain.pem"
        NEWKEY="$ROOT/data/tls/privkey.pem"
        OLD_CERT_HASH=$(sha256sum "$NEWCERT" 2>/dev/null | awk '{print $1}') || true
        OLD_KEY_HASH=$(sha256sum "$NEWKEY" 2>/dev/null | awk '{print $1}') || true
        # Re-stage from the source every deploy so Let's Encrypt rotation lands.
        if [ "$WSCERT" != "$NEWCERT" ]; then
            cp "$WSCERT" "$NEWCERT" 2>/dev/null || echo "WARN: could not copy $WSCERT into data/tls/"
            if [ -n "$WSCHAIN" ] && [ -f "$WSCHAIN" ]; then
                cat "$WSCHAIN" >> "$NEWCERT" 2>/dev/null || true
            fi
        fi
        if [ "$WSKEY" != "$NEWKEY" ]; then
            cp "$WSKEY" "$NEWKEY" 2>/dev/null || echo "WARN: could not copy $WSKEY into data/tls/"
        fi
        # Own the staged files by the gateway user (the app dir owner), NOT
        # www-data. Key stays 640, owner-readable.
        SITE_UG=$(stat -c '%U:%G' "$ROOT" 2>/dev/null || printf '%s:%s' "$(id -un)" "$(id -gn)")
        chown "$SITE_UG" "$NEWCERT" "$NEWKEY" 2>/dev/null || true
        chmod 644 "$NEWCERT" 2>/dev/null || true
        chmod 640 "$NEWKEY" 2>/dev/null || true
        # Fail loudly if the gateway user can't read the staged key/cert.
        if [ "$(id -u)" = "0" ]; then
            if ! su -s /bin/sh "$SITE_USER" -c "test -r '$NEWCERT' && test -r '$NEWKEY'"; then
                echo "ERROR: staged TLS files are not readable by the gateway user ($SITE_USER). Fix ownership, or run deploy as $SITE_USER." >&2
                exit 1
            fi
        elif [ "$(id -un)" != "$SITE_USER" ] || [ ! -r "$NEWCERT" ] || [ ! -r "$NEWKEY" ]; then
            echo "ERROR: staged TLS files are not readable by the gateway user ($SITE_USER). Run deploy.sh as root or as $SITE_USER." >&2
            exit 1
        fi
        NEW_CERT_HASH=$(sha256sum "$NEWCERT" | awk '{print $1}') || true
        NEW_KEY_HASH=$(sha256sum "$NEWKEY" | awk '{print $1}') || true
        if [ "$NEW_CERT_HASH" != "$OLD_CERT_HASH" ] || [ "$NEW_KEY_HASH" != "$OLD_KEY_HASH" ]; then
            SSL_CHANGED=1
            echo "WSS: TLS files staged/updated at $NEWCERT"
        else
            echo "WSS: TLS files already current (cert $NEWCERT)"
        fi
        php -r 'require "src/bootstrap.php"; config_set("ws_ssl_cert", "'"$NEWCERT"'"); config_set("ws_ssl_key", "'"$NEWKEY"'");'
        echo "WSS: config points at $NEWCERT"
    else
        if [ -n "$WSCERT" ] && [ -n "$WSKEY" ] && [ -f "$WSCERT" ] && [ -f "$WSKEY" ]; then
            echo "WARN: could not resolve this site's cert, but ws_ssl_cert/ws_ssl_key already point at existing files ($WSCERT) — leaving them unchanged."
        else
            echo "WSS: no TLS cert found — run deploy.sh as root (sudo bash bin/deploy.sh) so the panel certs are readable, or set ws_ssl_cert/ws_ssl_key in Admin → Settings → WebSocket gateway."
        fi
    fi

    # Restart the gateway AS THE WEB USER (systemd preferred) when TLS changed —
    # never as root, so the admin panel keeps managing the daemon.
    if [ "$SSL_CHANGED" = "1" ]; then
        RESTARTED=0
        UNIT=$(systemctl list-unit-files --no-legend --type=service 2>/dev/null | awk '{print $1}' | grep -iE '^(chat|lvc|ws-server|lvchat|realtime)' | head -1 || true)
        if [ -n "$UNIT" ] && systemctl restart "$UNIT" >/dev/null 2>&1; then
            RESTARTED=1
            echo "WSS: gateway restarted via systemd ($UNIT)."
        elif [ "$(id -u)" = "0" ]; then
            if su -s /bin/sh "$SITE_USER" -c "cd '$ROOT' && php bin/ws-server.php restart -d" >/dev/null 2>&1; then
                RESTARTED=1
                echo "WSS: gateway restarted as $SITE_USER."
            fi
        elif [ "$(id -un)" = "$SITE_USER" ]; then
            if php "$ROOT/bin/ws-server.php" restart -d >/dev/null 2>&1; then
                RESTARTED=1
                echo "WSS: gateway restarted."
            fi
        fi
        if [ "$RESTARTED" = "0" ]; then
            echo "WSS: could not restart the gateway automatically — click Restart in Admin → Settings → WebSocket gateway (running as $SITE_USER)."
        fi
    fi
else
    echo "realtime mode: $RT (gateway not required)"
fi
