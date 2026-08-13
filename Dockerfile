# LVChat — Discord-style web chat (PHP + SQLite)
# SPDX-License-Identifier: AGPL-3.0-only
#
# Single all-in-one container: nginx + PHP-FPM + the Workerman realtime
# gateway, supervised by supervisord. SQLite lives on a shared data volume so
# every process sees the same database file.
#
# Build:   docker build -t lvchat .
# Run:     docker compose up -d   (see docker-compose.yml)
#
# The container serves plain HTTP on :80 and WebSocket on :8080. Terminate TLS
# with a reverse proxy in front of it (Caddy, nginx, Cloudflare, ...).

FROM php:8.3-fpm-bookworm

ENV DEBIAN_FRONTEND=noninteractive
ENV COMPOSER_ALLOW_SUPERUSER=1

# --- System packages + nginx + supervisord -------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        curl \
        ca-certificates \
        unzip \
        git \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
        libcurl4-openssl-dev \
        libssl-dev \
        sqlite3 \
        libsqlite3-dev \
        pkg-config \
    && rm -rf /var/lib/apt/lists/*

# --- PHP extensions --------------------------------------------------------
# Required: pdo_sqlite. Optional-but-used: pcntl/posix (WS gateway fork),
# gd (image re-encode/downscale), curl (Giphy/embed proxy), zip/mbstring
# (composer), intl/exif (composer helpers).
RUN docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_sqlite \
        pcntl \
        posix \
        gd \
        curl \
        zip \
        mbstring \
        intl \
        exif \
    && docker-php-ext-enable opcache

# --- Composer (for Workerman, the WS gateway) ------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# --- App -------------------------------------------------------------------
WORKDIR /var/www/html

# Copy dependencies first for better layer caching.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist

COPY . .

# The committed stylesheet/JS ship with the app; nothing needs Node or npm.

# --- PHP tuning -------------------------------------------------------------
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-lvchat.ini

# --- nginx ------------------------------------------------------------------
# The app is served from public/, via fastcgi to php-fpm on 127.0.0.1:9000.
COPY docker/nginx.conf /etc/nginx/sites-available/lvchat
RUN ln -sf /etc/nginx/sites-available/lvchat /etc/nginx/sites-enabled/lvchat \
    && rm -f /etc/nginx/sites-enabled/default

# --- supervisord -------------------------------------------------------------
# The Debian package's /etc/supervisor/supervisord.conf includes conf.d/*.conf,
# but it also ships an unneeded default logging block — replace it outright so
# our [supervisord] settings (nodaemon, logfile) are the ones that win.
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/lvchat-entrypoint
RUN chmod +x /usr/local/bin/lvchat-entrypoint

# Runtime writable dirs (created by the entrypoint on a fresh volume).
RUN mkdir -p /var/www/html/data /var/www/html/public/uploads /var/www/html/public/assets/avatars

VOLUME ["/var/www/html/data", "/var/www/html/public/uploads", "/var/www/html/public/assets/avatars"]

EXPOSE 80 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD curl -fsS -o /dev/null http://127.0.0.1/healthz || exit 1

ENTRYPOINT ["/usr/local/bin/lvchat-entrypoint"]
