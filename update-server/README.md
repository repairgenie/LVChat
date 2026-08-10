# LVChat Update Server

A small, zero-dependency PHP web app that publishes the current versions of the
LVChat apps and where each one can be downloaded. It is the **central upstream
repo** for the updater system:

- **LVChat Web** (the PHP chat app) — version + tarball link + sha256
- **LVChat Desktop** (Electron) — version + per-platform installers
- **LVChat Messenger** (Electron) — version + per-platform installers

Community server admins point their LVChat install's `updater_url` here (or at
their own mirror) so their chat's download modal and admin Updates page resolve
versions and links automatically. They can still override everything with their
own white-labelled URLs.

`data/releases.json` is the single source of truth. Everything this server
serves — the JSON manifest, the `/downloads/*` redirects, and the
electron-updater `latest*.yml` feeds — is generated from it.

## Requirements

- PHP 8.1+ (no extensions beyond core, `pdo_sqlite` not needed)
- No composer, no npm, no database

## Deploy

1. Copy the `update-server/` folder anywhere your web server can serve it and
   point the document root at `update-server/public/` (same as the main app).
   Or run the built-in server:

   ```bash
   php -S 0.0.0.0:8090 update-server/public/index.php
   ```

   Apache gets clean URLs from `public/.htaccess` automatically; on nginx,
   route unknown paths to `index.php`.

2. Configure:

   ```bash
   cp config.sample.php config.php
   php -r 'echo password_hash("your-password", PASSWORD_BCRYPT), PHP_EOL;'
   ```

   Paste the hash into `admin_pass_hash` in `config.php` (or set `admin_pass`
   directly). Optionally set `base_url` to the public URL of this server.

3. Create the manifest:

   ```bash
   cp data/releases.sample.json data/releases.json
   ```

4. Sign in at `/admin` and publish each app: set the version, per-platform
   artifact URLs, and click **Fetch & hash** to fill in the sha256/sha512/size
   fields automatically (the server downloads each file and hashes it). Save.

## Endpoints

| URL | Purpose |
|---|---|
| `GET /manifest.json` | Full manifest (all apps, all platforms) |
| `GET /api/latest/<app>` | Latest `web` / `desktop` / `messenger` entry |
| `GET /api/latest/<app>/<platform>` | One platform entry (`win`, `mac`, `linux_deb`, `linux_rpm`, `linux_appimage`) |
| `GET /downloads/<app>/<platform>` | 302 to the artifact — a stable URL that survives link changes |
| `GET /desktop/latest.yml` | electron-updater feed — Windows (also `latest-mac.yml`, `latest-linux.yml`) |
| `GET /messenger/latest.yml` | electron-updater feed — Windows (also `latest-mac.yml`, `latest-linux.yml`) |
| `GET /health` | `{"ok": true}` health check |

The electron-updater feeds are generated from the manifest on the fly, so point
the Electron apps' generic-provider `publish.url` at `<this server>/desktop` and
`<this server>/messenger`. Artifacts can live anywhere (GitHub Releases, FTP,
your web server) — the feeds carry the full absolute URL, which electron-updater
downloads directly.

## CLI

```bash
php bin/check-manifest.php   # validate releases.json; exit 1 on issues
```

## Manifest format

```json
{
  "updated_at": "2026-08-09T10:00:00Z",
  "apps": {
    "web": {
      "version": "1.7.1",
      "url": "https://github.com/repairgenie/LVChat/archive/refs/tags/v1.7.1.tar.gz",
      "sha256": "…64 hex…",
      "notes": "https://github.com/repairgenie/LVChat/releases/tag/v1.7.1",
      "released_at": "2026-08-01T00:00:00Z"
    },
    "desktop": {
      "version": "0.2.0",
      "notes": "",
      "released_at": "2026-08-09T00:00:00Z",
      "platforms": {
        "win":           { "url": "…Setup-0.2.0.exe",  "sha256": "…", "sha512": "…base64…", "size": 123456 },
        "mac":           { "url": "…0.2.0.dmg",        "sha256": "…", "sha512": "…", "size": 123456 },
        "linux_deb":     { "url": "…0.2.0_amd64.deb",  "sha256": "…", "sha512": "…", "size": 123456 },
        "linux_rpm":     { "url": "…0.2.0.x86_64.rpm", "sha256": "…", "sha512": "…", "size": 123456 },
        "linux_appimage":{ "url": "…0.2.0.AppImage",   "sha256": "…", "sha512": "…", "size": 123456 }
      }
    },
    "messenger": { "… same shape as desktop …" }
  }
}
```

`sha256` (hex) is what the LVChat web app verifies before applying an update;
`sha512` (base64) + `size` feed electron-updater.
