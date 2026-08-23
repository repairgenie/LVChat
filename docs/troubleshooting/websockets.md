# WebSocket Troubleshooting Guide

Common issues that prevent the LVChat WebSocket gateway from connecting clients, diagnosed and resolved from real production incidents.

## Quick Diagnosis

Run these first to narrow down the problem:

```bash
# 1. Is the gateway daemon running?
php bin/ws-server.php status

# 2. Is it listening on the expected port?
ss -tlnp | grep 8082

# 3. Does the health endpoint respond?
curl -s http://127.0.0.1:9001/health

# 4. What URL is the client told to connect to?
php -r "require 'src/bootstrap.php'; echo Realtime::clientUrl() . PHP_EOL;"

# 5. Check the gateway logs for rejected connections
tail -50 data/ws-server.log

# 6. What transport does the browser report?
#    In browser console (F12):
document.body.dataset.wsUrl
document.body.dataset.rtTicket
```

---

## Issue: "WebSocket offline" in admin panel, 0 connections

The admin panel shows `Running — 0 connections — clients: 1 offline (websocket forced but unreachable)`.

### Cause 1: Content Security Policy blocking WebSocket

The browser enforces the `connect-src` CSP directive. If it only says `'self'`, WebSocket connections to a different port (e.g., port 8082 from an HTTPS page on port 443) are blocked.

**Diagnose:** Open browser console (F12) and paste:

```js
new WebSocket('wss://yourdomain:8082/?ticket=test')
```

If you see: `violates the following Content Security Policy directive: "connect-src 'self'"` — this is the problem.

**Fix:** The CSP header in `src/bootstrap.php` must include the WebSocket origin:

```php
// The CSP connect-src directive must include the WebSocket URL origin
// when the gateway runs on a different port than the web server.
$__wsOrigin = '';
if (class_exists('Realtime') && Realtime::enabled()) {
    $__wsUrl = Realtime::clientUrl();
    $__wsParsed = parse_url($__wsUrl);
    if (!empty($__wsParsed['scheme']) && !empty($__wsParsed['host'])) {
        $__wsOrigin = $__wsParsed['scheme'] . '://' . $__wsParsed['host']
            . (!empty($__wsParsed['port']) ? ':' . $__wsParsed['port'] : '');
    }
}
$__connectSrc = "'self'" . ($__wsOrigin !== '' ? " {$__wsOrigin}" : '');
header("Content-Security-Policy: ... connect-src {$__connectSrc}; ...");
```

This generates something like:
```
connect-src 'self' wss://chat.example.com:8082
```

### Cause 2: `clientUrl()` returns `localhost` or wrong host

On panels like HestiaCP, `$_SERVER['SERVER_NAME']` is `localhost`. If neither `APP_URL` nor `TRUSTED_HOSTS` is set, `trusted_host()` falls back to `localhost`, producing:

```
wss://localhost:8082/
```

The browser tries to connect to the user's own machine — which has no gateway.

**Diagnose:**

```bash
php -r "require 'src/bootstrap.php'; echo Realtime::clientUrl() . PHP_EOL;"
```

If it says `wss://localhost:...` — this is the problem.

**Fix (Option A):** Set `ws_url` config to the full URL:

```bash
php -r "
require 'src/bootstrap.php';
config_set('ws_url', 'wss://chat.example.com:8082/');
"
```

**Fix (Option B):** Set `APP_URL` in `.env`:

```
APP_URL=https://chat.example.com
```

**Fix (Option C):** Set `TRUSTED_HOSTS` in `.env`:

```
TRUSTED_HOSTS=chat.example.com
```

### Cause 3: Mixed content (`ws://` on an HTTPS page)

When the site is served over HTTPS but the WebSocket URL is generated as `ws://` (not `wss://`), browsers block the connection as mixed content. Zero TCP connections reach the daemon — the gateway logs show nothing.

This happens when `TRUSTED_PROXY` is not set and the server sits behind a TLS-terminating reverse proxy (nginx, Cloudflare, etc.). `requestSecure()` cannot detect HTTPS because `$_SERVER['HTTPS']` is not set and the `X-Forwarded-Proto` header is ignored without `TRUSTED_PROXY=1`.

**Diagnose:**

```bash
php -r "require 'src/bootstrap.php'; echo Realtime::clientUrl() . PHP_EOL;"
```

If it says `ws://` but your site is `https://` — this is the problem.

**Fix (Option A):** Set `TRUSTED_PROXY=1` in `.env`:

```
TRUSTED_PROXY=1
```

**Fix (Option B):** Set `ws_url` config (bypasses all detection logic):

```bash
php -r "
require 'src/bootstrap.php';
config_set('ws_url', 'wss://chat.example.com:8082/');
"
```

### Cause 4: Stale ticket from HTML data attribute

The web/desktop app bakes a one-time ticket into `data-rt-ticket` at page render time (60-second TTL). If the page is cached by the browser or a CDN, the ticket expires before the WebSocket handshake completes.

**Diagnose:** Hard-refresh the page (`Ctrl+Shift+R`). If it works temporarily then breaks again after a few minutes, stale tickets are the problem.

**Fix:** Ensure `app.js` fetches a fresh ticket dynamically on every connection attempt via `refreshTicket(() => openWs())` instead of using the static HTML attribute. This matches how the messenger apps work.

### Cause 5: SSL cert not configured on the gateway

The client connects `wss://` (TLS) but the Workerman gateway has no SSL certificate configured. The TLS handshake fails immediately.

**Diagnose:**

```bash
# Check if Workerman shows ssl or tcp proto
php bin/ws-server.php status

# Check cert config
php -r "
require 'src/bootstrap.php';
echo 'ws_ssl_cert: ' . (config_get('ws_ssl_cert','') ?: '(empty)') . PHP_EOL;
echo 'ws_ssl_key: ' . (config_get('ws_ssl_key','') ?: '(empty)') . PHP_EOL;
"
```

If Workerman shows `ssl` but the cert path is empty, or vice versa — this is the problem.

**Fix:** Point the gateway to your existing TLS cert:

```bash
php -r "
require 'src/bootstrap.php';
config_set('ws_ssl_cert', '/path/to/fullchain.pem');
config_set('ws_ssl_key', '/path/to/privkey.pem');
"
php bin/ws-server.php restart
```

For HestiaCP, certs are typically at:
```
/home/USER/web/DOMAIN/conf/web/ssl.DOMAIN.crt
/home/USER/web/DOMAIN/conf/web/ssl.DOMAIN.key
```

### Cause 6: Gateway port mismatch (stale daemon)

The admin changed the `ws_port` config but the gateway was not restarted. The daemon still listens on the old port while clients connect to the new port.

**Diagnose:**

```bash
# What port is the daemon actually on?
ss -tlnp | grep php

# What port does the config say?
php -r "require 'src/bootstrap.php'; echo config_get('ws_port','8080') . PHP_EOL;"
```

If they differ — this is the problem.

**Fix:**

```bash
php bin/ws-server.php stop
sleep 2
php bin/ws-server.php start -d
```

### Cause 7: `ws_allowed_origin` mismatch

If `ws_allowed_origin` is configured but doesn't match the browser's `Origin` header, every connection is rejected silently.

**Diagnose:**

```bash
php -r "require 'src/bootstrap.php'; echo 'ws_allowed_origin: ' . (config_get('ws_allowed_origin','') ?: '(empty)') . PHP_EOL;"
```

Check the gateway log for `reject ws connect: bad origin`.

**Fix:** Either set it to match your domain exactly, or clear it:

```bash
php -r "
require 'src/bootstrap.php';
config_set('ws_allowed_origin', 'https://chat.example.com');
"
```

### Cause 8: Cron watchdog restarting a broken daemon

The `RealtimeGatewayCheckJob` runs every minute and auto-restarts the gateway. If the gateway keeps failing, it keeps restarting — creating a start/stop loop visible in the logs.

**Diagnose:**

```bash
tail -30 data/ws-server.log
# Look for repeated start/stop cycles
```

**Fix:** This is usually a symptom of another issue on this list. Fix the root cause. The watchdog working correctly.

---

## Messenger apps connect but web/desktop don't

The messenger apps (LVChat Messenger, Messenger Web PWA) use a different connection approach than the web/desktop chat app:

| Aspect | Messenger | Web/Desktop |
|--------|-----------|-------------|
| Ticket source | Always fetched dynamically via API | Originally static HTML data attribute |
| Auth mechanism | Bearer token (`X-LVC-Session`) | Session cookie |
| On failure | Never gives up, exponential backoff | Used to give up after 3 tries |
| Reconnect delay | `min(30s, 2s * 2^fails)` | Was linear backoff |

If the messenger works but web/desktop doesn't, the most likely causes are:
1. **CSP blocking** (Cause 1) — Electron doesn't enforce CSP the same way
2. **Mixed content** (Cause 3) — Electron doesn't block `ws://` on HTTPS pages
3. **Stale tickets** (Cause 4) — Messenger always fetches fresh

---

## Browser shows "Connecting..." then "Websocket offline"

This means the client is attempting a connection but it fails. In order of likelihood:

1. CSP blocking (check browser console for CSP violation)
2. Mixed content (check `clientUrl()` output)
3. Wrong host in URL (check `clientUrl()` output)
4. SSL cert mismatch (check gateway logs)
5. Origin mismatch (check gateway logs for "bad origin")
6. Ticket expired (check gateway logs for "invalid/expired ticket")

---

## Reconnect clients button doesn't work

The "Reconnect Clients" button in Admin broadcasts a `reconnect` event to all **currently connected** WebSocket clients. If there are 0 connected clients (everyone is offline), the broadcast goes nowhere.

**This is expected behavior.** Fix the underlying connection issue first. Once clients reconnect, the button will work.

---

## Environment Reference

| Variable | Default | Purpose |
|----------|---------|---------|
| `WS_PORT` | `8080` | WebSocket client port |
| `WS_URL` | (auto) | Override the client-facing WS URL |
| `WS_SSL_CERT` | (empty) | TLS cert for WSS |
| `WS_SSL_KEY` | (empty) | TLS key for WSS |
| `WS_PUSH_URL` | `http://127.0.0.1:9001/push` | Internal push endpoint |
| `TRUSTED_PROXY` | `0` | Trust `X-Forwarded-Proto` headers |
| `APP_URL` | (empty) | Canonical URL for WS construction |
| `TRUSTED_HOSTS` | (empty) | Allowed Host headers |

| DB Config Key | Default | Purpose |
|---------------|---------|---------|
| `realtime` | `poll` | Transport mode: `poll`, `sse`, or `ws` |
| `ws_port` | `8080` | Gateway client port |
| `ws_url` | (empty) | Override client WS URL |
| `ws_ssl_cert` | (empty) | TLS cert for WSS |
| `ws_ssl_key` | (empty) | TLS key for WSS |
| `ws_allowed_origin` | (empty) | Origin allowlist for WS connections |
