# LVChat — Realtime Gateway & Internal Push Protocol

The optional Level-3 realtime mode moves delivery from polling/SSE to a
Workerman daemon (`bin/ws-server.php`). This page documents the daemon itself
and — more importantly — the **internal push protocol** between php-fpm (the
request layer) and the daemon, which is what actually fans a persisted message
out to subscribers.

```
                    persists row               HTTP POST /push
   browser ──────▶ php-fpm (request layer) ─────────────▶  gateway daemon
   (client WS)                                   │          (Workerman)
        ▲                                        │   X-Push-Secret + event
        └──────────── WS frame fan-out ──────────┘
```

---

## 1. Listeners

One Workerman process runs **two listeners in a single event loop**:

| Listener | Address (default) | Purpose |
|---|---|---|
| WebSocket server | `0.0.0.0:8080` (`ws_port`) | Chat clients (browsers). |
| Internal HTTP endpoint | `127.0.0.1:9001` (`ws_push_url`, path `/push`) | php-fpm POSTs events here. |

Config/environment precedence for the socket addresses:

- `ws_ip` / `WS_IP` — bind address (default `0.0.0.0`).
- `ws_port` / `WS_PORT` — client port (default 8080).
- `ws_push_url` / `WS_PUSH_URL` — internal push URL (default
  `http://127.0.0.1:9001/push`; host/port/path are parsed from it).
- `ws_ssl_cert` / `ws_ssl_key` (or `WS_SSL_CERT`/`WS_SSL_KEY`) — when both are
  set the gateway serves **WSS** on the same port and `Realtime::clientUrl()`
  returns `wss://`.

---

## 2. Connection state

The daemon keeps one entry per authenticated connection:

```php
$state[$conn->id] = [
  'user'        => $user,        // actor array (user or guest shape)
  'sub'         => ['type' => null, 'target' => null],
  'presence_ts' => 0,            // last presence write time
  'touched'     => time(),       // last received frame (liveness sweep)
];
```

`sub` is the connection's **single subscription**:

- `['type' => 'channel', 'target' => '<slug>']` — receives that channel's messages + updates.
- `['type' => 'dm', 'target' => '<nick>']` — receives that conversation's PMs.
- `null` until the client sends `subscribe`.

Connections are established in `onWebSocketConnect` (origin check → ticket
redemption → state entry → immediate `last_seen` write) and torn down in
`onClose`. Each state entry carries a `client` key (the browser's per-tab id
from `?client=`, or a connection-scoped fallback for older clients); on close,
that client's `rt_clients` row is dropped and `last_seen` is written past the
presence window only when **no other live client remains** for the actor — so
closing one tab or device does not hide an account that is active elsewhere.

### Liveness (PING/PONG)

A timer (every 15 s, or the ping interval when that is shorter) sends a
WebSocket PING frame (opcode `0x9`, written raw through Workerman so it is not
wrapped as a text frame) to every authenticated connection. Any connection with
no inbound frame at all — pong or app message — for `WS_PONG_TIMEOUT` (default
**75 s**, about two missed pings) is closed.

- PONG frames are handled in `onWebSocketPong` and refresh `touched`; they never
  reach `onMessage`. They are also presence proof: at most once per
  `presence_throttle` the same handler refreshes `last_seen` and the ws
  `rt_clients` row, so a backgrounded or frozen tab whose JS timers stopped
  stays online as long as its network stack keeps answering PINGs.
- Browsers answer PING frames in the network stack, so a throttled or frozen
  tab is still judged on reachability instead of on JS timers, and a blackholed
  path (NAT drop, dead route, suspended device) is closed even though TCP still
  looks open.
- Closing runs `onClose` → presence offline; the client sees `onclose` and its
  backoff path reconnects with a fresh ticket.
- `WS_PING_INTERVAL` (default 30) and `WS_PONG_TIMEOUT` (default 75) are env
  overrides, used by the liveness test.

Clients run the mirror-image check: after a socket opens they track the last
inbound frame (including `{pong:true}` replies to their own application pings)
and force-close the socket after 2.5 ping cycles with no frame, so a one-way
blackhole the server cannot observe also triggers a reconnect.

### Presence throttle changes

`presence_throttle` is admin-tunable at runtime. The gateway reads it fresh in
the application-`ping` handler — a single indexed `server_config` lookup, so
cheap that caching it would only add staleness — therefore a change in
Admin → Settings applies to already-connected sockets on their next ping, with
no restart. There is no tuning knob for this. HTTP clients pick it up
immediately too (`presence_window()` per request, and `ping_ms` on the next
`GET /api/ws/ticket`), and close/beacon offline writes read the window per call.
Listener settings (`ws_ip`, `ws_port`, TLS certs, `ws_allowed_origin`) still
need the usual `start|restart`. The live refresh is covered by
`tests/ws_test.php`, which changes the throttle while a socket is connected.

---

## 3. Internal push endpoint (`POST /push`)

php-fpm calls `Realtime::publish()` after every user-facing write. It is
**fire-and-forget**: a raw socket write with a 0.2 s connect timeout, no wait
for the response, and silent failure — when the daemon is down the app simply
keeps polling.

### Request

```
POST /push HTTP/1.1
Host: 127.0.0.1:9001
Content-Type: application/json
X-Push-Secret: <shared secret>
Content-Length: <n>
Connection: close

{ "type": "message", "channel": "general", "message": { … } }
```

- **`X-Push-Secret`** — a shared secret auto-provisioned on first use
  (`Realtime::pushSecret()` → `server_config['ws_push_secret']`,
  16 random bytes hex). The gateway rejects events with a wrong secret:
  `{"ok":false,"error":"unauthorized"}`.
- Wrong path/method → `{"ok":false,"error":"not found"}`; unparseable body or
  missing `type` → `{"ok":false,"error":"bad event"}`.
- Every valid event answers `{"ok":true}`.

### Event types & fan-out routing

| `type` | Payload fields | Fan-out rule |
|---|---|---|
| `message` | `channel` (slug), `message` (full channel message) | To every connection whose `sub.type === 'channel'` **and** `sub.target === channel`. Frame: `{messages:[message]}`. |
| `dm` | `from` (nick), `to` (nick), `message` (full PM) | To every connection subscribed to either side of the conversation (`sub.target` ∈ {`from`,`to`}, case-insensitive). Frame: `{messages:[message]}`. |
| `bell` | `user_id`, `notify_count` | To every connection whose actor id matches. Frame: `{notify_count:N}`. |
| `reconnect` | — | To **every** connection. Frame: `{reconnect:true}`. |
| `msg_update` | `channel` (slug), `action` (`edit`/`delete`/`reaction`), `message_id`, optional `content`, optional `reactions` | To channel subscribers. Frame: `{msg_update:{…}}`. |

Frames are JSON-encoded `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`,
matching the poll payload conventions.

### Who publishes what

| Server-side trigger | Publish call | Event |
|---|---|---|
| Channel message written | `Realtime::message(slug, $msg)` | `message` |
| PM written | `Realtime::dm($from, $to, $msg)` | `dm` |
| DM notification created / bell changed | `Realtime::bell($user)` | `bell` |
| Message edited/deleted/reacted | `Realtime::msgUpdate(slug, action, id, extra)` | `msg_update` |
| Admin "reconnect all clients" | `Realtime::reconnectClients()` | `reconnect` |

All publishes are no-ops when `realtime !== 'ws'` (the `Realtime::enabled()`
guard), so the daemon is never contacted in polling/SSE modes.

---

## 4. Health & status

`GET /health` on the push port (any host) returns:

```json
{ "ok": true, "connections": 12, "ws_port": 8080 }
```

- `connections` — current live client count (used by the admin UI and the
  deploy health check).
- `ws_port` — the port the daemon **actually bound**. The admin UI compares it
  to the `ws_port` config to flag a stale daemon (config changed but not
  restarted) or a `WS_PORT` env override.

`Realtime::daemonStatus()` reads this over a local socket (with a 1 s bound),
reads the Workerman pid file (`data/ws-server.pid`), and aggregates the
`rt_transports` table (browsers' self-reported transport, last 2 minutes).

---

## 5. Client-side WebSocket protocol

The gateway-side half of the client protocol is in [realtime.md](realtime.md) §3.
For completeness, the gateway's frame handling:

- **`hello`** → `{"ok":true,"id","nick","guest"}`.
- **`ping`** → writes `last_seen` when `presence_throttle` elapsed, then
  `{"pong":true}`.
- **`subscribe`** with `channel` or `dm` → sets `$st['sub']` and acks
  `{"ok":true,"sub":{…}}`; otherwise clears the subscription to `null`.
- anything else → `{"ok":false,"error":"unknown action"}`.

`touched` is refreshed on every frame received, including protocol pongs (keeps the liveness sweep honest).

---

## 6. Operational notes

- **Process model**: Workerman forks a master + worker(s) on Linux, so the
  `pcntl` and `posix` PHP extensions are required. Without them the daemon
  refuses to start with a clear message; the chat keeps working on polling/SSE.
- **SQLite**: the forked worker re-opens its own PDO connection
  (`Database::close()` in `onWorkerStart`) so each process owns its SQLite
  handle.
- **Logs/pid**: `data/ws-server.log`, `data/ws-server.pid`.
- **Commands**: `php bin/ws-server.php start` / `start -d` / `stop` /
  `restart` / `reload` / `status`. A systemd unit is provided in the file
  header.
- **Port auto-selection**: `bin/deploy.sh` picks the first free port in
  8080–8089; Admin → Settings shows which are free and can restart the daemon.
- **Origin allowlist**: `ws_allowed_origin` config — when set, connections
  whose `Origin` doesn't contain it are closed at handshake.
- **Degradation**: every push is fire-and-forget and silently swallowed when
  the daemon is down. Clients independently fall back to polling/SSE (see
  [realtime.md](realtime.md) §4), and the transport-reporting table lets admins
  see that a fallback actually happened.

### WSS / TLS renewal

When `ws_ssl_cert` / `ws_ssl_key` are set (or `WS_SSL_CERT`/`WS_SSL_KEY`), the
gateway serves `wss://` using those files. `bin/deploy.sh` auto-stages the
site's Let's Encrypt cert into `data/tls/fullchain.pem` + `privkey.pem` and
points the config there.

Two caveats keep WSS certificates from going stale after a renewal:

- **Root-only sources (HestiaCP et al.)** — the panel's certs under
  `/home/<user>/conf/web/<domain>/ssl/` are owned by `root` (mode 600), so a
  deploy run as the *site user* cannot re-stage them. `bin/deploy.sh` now
  detects this: if the source cert was renewed but the copy failed it exits
  loudly (no more misleading "TLS files already current"); run
  `sudo bash bin/deploy.sh`, or
- **Install the renewal hook** so rotation is automatic:

  ```bash
  sudo cp bin/le-renewal-hook.sh /etc/letsencrypt/renewal-hooks/deploy/20-lvchat-wss.sh
  sudo chmod +x /etc/letsencrypt/renewal-hooks/deploy/20-lvchat-wss.sh
  ```

  Certbot runs deploy hooks as root after each successful renewal, so the hook
  can read the root-only source, re-stage `data/tls/`, `chown` to the site
  user, and restart the gateway (systemd unit if present, otherwise
  `su <siteuser> -c 'php bin/ws-server.php restart -d'`). It is
  fingerprint-guarded and never fails a renewal — activity is logged to
  `data/logs/le-hook.log`. Verify with:

  ```bash
  sudo certbot renew --dry-run
  cat data/logs/le-hook.log
  echo | openssl s_client -connect 127.0.0.1:<ws_port> -servername <domain> 2>/dev/null | grep -E 'subject=|issuer=|Verify return'
  ```
