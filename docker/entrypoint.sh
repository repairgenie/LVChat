#!/usr/bin/env bash
#
# LVChat container entrypoint.
# SPDX-License-Identifier: AGPL-3.0-only
#
# 1. Rewrites public/.htaccess and runs the deploy sanity checks
#    (bin/deploy.sh) — the DB is created/migrated on first request, but the
#    deploy checks are cheap and mirror a bare-metal install.
# 2. Makes sure the runtime-writable folders exist and belong to www-data
#    (they may live on volumes mounted with different ownership).
# 3. Execs supervisord, which keeps nginx + php-fpm + the WS gateway alive.

set -euo pipefail

APP_DIR="/var/www/html"

# ── Runtime-writable folders ───────────────────────────────────────────────
# A fresh named volume comes up empty; give it the layout the app expects.
mkdir -p \
    "$APP_DIR/data" \
    "$APP_DIR/public/uploads" \
    "$APP_DIR/public/assets/avatars"

# ── Ownership / permissions ────────────────────────────────────────────────
# nginx and php-fpm run as www-data; SQLite needs write access to data/ and
# the upload folders. The code itself stays readable by all.
chown -R www-data:www-data \
    "$APP_DIR/data" \
    "$APP_DIR/public/uploads" \
    "$APP_DIR/public/assets/avatars"
chmod -R u+rwX,g+rwX \
    "$APP_DIR/data" \
    "$APP_DIR/public/uploads" \
    "$APP_DIR/public/assets/avatars"

# ── Deploy sanity check ────────────────────────────────────────────────────
# Re-writes public/.htaccess, verifies assets, backs up an existing DB.
# It also does the HTTP sanity check on a throwaway server on 127.0.0.1:8095.
echo "[lvchat] running deploy checks ..."
cd "$APP_DIR"
if bash bin/deploy.sh; then
    echo "[lvchat] deploy checks passed."
else
    echo "[lvchat] WARNING: deploy checks reported problems — starting anyway." >&2
fi

# ── Supervisor ─────────────────────────────────────────────────────────────
echo "[lvchat] starting supervisord (nginx + php-fpm + ws-server) ..."
exec /usr/bin/supervisord -n "$@"
