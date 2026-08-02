# LVChat — Installation & Administration Guide

LVChat is a Discord-style IRC web chat written in **PHP + SQLite**. It needs no
Node.js on the server and no internet access to a CDN: the compiled
`public/assets/css/app.css` and `public/assets/js/app.js` ship with the app
folder. A front-controller rewrite gives clean URLs (`/login`, `/app`,
`/c/gaming`, `/admin/...`).

This guide covers:

- Requirements
- Local development
- Production deployment (Apache, Nginx, and shared hosts)
- The SQLite database and configuration
- Upgrading and backing up
- Verification and troubleshooting

---

## 1. Requirements

| Component | Requirement |
|---|---|
| PHP | 8.1 or newer |
| PHP extensions | `pdo_sqlite` (the SQLite driver) |
| Node.js + npm | **Only on your own machine** if you edit views — the shipped CSS never needs rebuilding on the server |
| Disk space | A few MB for the app; the SQLite database grows with chat history |

Check your server before going further:

```bash
php -v                # must report PHP 8.1+
php -m | grep pdo_sqlite   # must list pdo_sqlite
```

> **Note:** `bin/deploy.sh` runs both checks automatically and aborts if
> `pdo_sqlite` is missing.

---

## 2. Local development

If you changed any view, rebuild the committed stylesheet first (this only needs
Node on *your* machine):

```bash
npm install && npm run build
```

Then run the built-in PHP server:

```bash
php -S 127.0.0.1:8000 -t public
```

Open <http://127.0.0.1:8000> in a browser.

**What happens on first request:**

- `src/Database.php` applies `schema.sql` (all tables + indexes) and creates the
  database at `data/chat.db` (or wherever `CHAT_DB` points).
- On a *fresh* database it seeds `server_config` defaults and three channels:
  `#general` (public), `#help` (public), `#staff` (staff only).
- **The first account you register automatically becomes the server admin.**
  Every later account is a regular user. (See the Admin Guide for promoting
  others, or the `/oper` operator-password path.)

---

## 3. Production deployment

The deploy story is intentionally simple: **upload the app folder, then run one
script on the server.**

### 3.1 Upload

Upload the entire app folder to your server, **except anything under `data/`**
(that is runtime state and lives on the server). Keep the layout intact — the
folder must contain `public/`, `src/`, `views/`, `bin/`, `schema.sql`, and the
compiled assets under `public/assets/`.

Examples:

```bash
# rsync (preserves every dotfile, mirrors deletions)
rsync -av --exclude 'data/' --exclude 'node_modules/' ./ user@host:/srv/lvchat/

# scp whole directory (uploaders that strip dotfiles are handled by deploy.sh)
scp -r ./ user@host:/srv/lvchat/
```

> **Tip:** Even if your upload tool drops dotfiles (which would remove
> `public/.htaccess`), `bin/deploy.sh` re-writes `.htaccess` for you.

### 3.2 Point your web server at `public/`

The document root must be the **`public/` folder**. Only `public/` is web-accessible;
`data/`, `src/`, `views/`, and `bin/` stay outside the document root and can
never be fetched over HTTP.

#### Apache

`.htaccess` ships with the app, and `bin/deploy.sh` writes (or repairs) it on
every run. It enables `mod_rewrite`, serves existing files (assets) directly,
and forwards everything else to `index.php`:

```apache
DirectoryIndex index.php

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</IfModule>
```

If you prefer, put the equivalent in the `<VirtualHost>` or a `<Directory>`
block and delete `public/.htaccess` entirely.

#### Nginx

Nginx has no `.htaccess`, so you add the `try_files` rule yourself. Use
`index.php` as the single entry point and fall back to it for anything that is
not a real file or directory:

```nginx
server {
    listen 80;
    server_name chat.example.com;
    root /srv/lvchat/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;   # adjust to your PHP-FPM socket
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff2?)$ {
        expires 7d;
        try_files $uri =404;
    }
}
```

The app also works without any rewrite at all by using `index.php/<path>`
(e.g. `index.php/login`), because the router strips an `/index.php` prefix. This
is what happens on servers where `.htaccess` is missing or `mod_rewrite` is
off — clean URLs come back as soon as you run `bin/deploy.sh`.

### 3.3 Run the deploy script

On the server, from the project root:

```bash
bash bin/deploy.sh
```

`bin/deploy.sh` does four things:

1. **Rewrites `public/.htaccess`** — restores clean URLs even if your uploader
   stripped dotfiles.
2. **Handles the database** — uses `$CHAT_DB` if set (default
   `<project>/data/chat.db`), migrates an older `../data/chat.db` into place,
   and creates a timestamped safety backup of an existing database before
   anything else runs.
3. **Runtime checks** — verifies the PHP version, that `pdo_sqlite` is loaded,
   and that `public/assets/js/app.js` and `public/assets/css/app.css` are
   present.
4. **HTTP sanity check** — boots a throwaway server on `127.0.0.1:8095`
   (`DEPLOY_PORT` overrides) and asserts that `/`, `/login`, `/register`,
   `/api/version`, and a 404 page behave, and that the JS and CSS assets are
   actually served as their real content-types (not as rewritten HTML). It prints
   the app version from `/api/version`.

A successful run ends with:

```
Deploy check passed. The app is ready.
```

### 3.4 Database location and configuration

- SQLite database: `<project>/data/chat.db` — **beside** `public/`, never
  web-served, and inside `open_basedir` by default so it works on shared hosts
  that enforce it.
- Override the location with the `CHAT_DB` environment variable:

  ```bash
  CHAT_DB=/var/lib/lvchat/chat.db bash bin/deploy.sh
  # or in PHP-FPM/Apache env
  ```

- The database uses WAL mode (`PRAGMA journal_mode = WAL`) for better
  concurrent reads. This creates `chat.db-wal` and `chat.db-shm` alongside it;
  treat all three as a unit when copying the database.
- `bin/deploy.sh` writes a backup named `chat.db.bak.<timestamp>` on every run.

### 3.5 Permissions (shared-hosts and everyone)

- `public/` must be readable by the web user.
- `data/` must be **writable** by the PHP process (SQLite needs to create and
  update the database file plus its `-wal`/`-shm` companions).
- Never make `data/`, `src/`, `views/`, or `bin/` reachable over HTTP.

---

## 4. First-run bootstrap

On a fresh database the following channels are created automatically:

| Channel | Visibility | Who can join |
|---|---|---|
| `#general` | public | everyone |
| `#help` | public | everyone |
| `#general` | public | everyone |
| `#help` | public | everyone |
| `#staff` | staff | admins and `staff`-role users only |

The first registered account becomes the server admin — the second and later
accounts are regular users. After that, admins can promote other users from
**Admin → Users**, or create an **o:line** in **Admin → O-lines** (username +
password + operator class) so a user can run `/oper <their nick> <password>` to
operate with that class's permissions. There is **no shared operator password.**

Anyone — including people who don't want an account — can also **Join as
guest** from the login page with just a nickname (see the User Guide).
Guests are labeled `(guest)`, can chat in existing channels but cannot create
channels, and are purged after a day of inactivity.

There are **no hardcoded default credentials.**

---

## 5. Upgrading / reinstalling

Upgrades are the same procedure as a fresh install:

1. Back up `data/` (`cp data/chat.db backup.db` — see also the automatic backup
   below).
2. Upload the app folder over the old one, **leaving `data/`** (and ideally
   `rewriting` nothing in it) untouched.
3. Run `bash bin/deploy.sh`.

`bin/deploy.sh` takes care of the rest:

- re-creates `.htaccess`.
- backs the database up automatically on every run.
- migrations are applied on app boot (`Database::init()` adds missing columns
  like `last_ip`, `role_id`, `guest`, and `censor` and creates missing tables
  such as `badwords`, `roles`, `operclasses`, `opers`, and `chat_logs`
  automatically), so old databases gain new features without manual SQL. The
  four built-in operator classes are seeded idempotently on every boot.
- older installs that stored the DB one level above the project are migrated
  back into `data/` automatically.

**Cache-busting is automatic** — CSS and JS are linked with
`?v=<file-mtime>`, so browsers never keep stale copies of `app.js`/`app.css`
after an upload.

---

## 6. Verification

- **`/api/version`** returns the running build, e.g.

  ```json
  {"version":"1.5.1","site":"LVChat"}
  ```

- **`bin/deploy.sh`** runs the full HTTP sanity check described in section 3.3.
- The chat page shows a **warning bar** if its scripts fail to load (stale or
  partial upload). It names the fix: "Re-upload the app folder and run
  `bash bin/deploy.sh`."

---

## 7. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Chat page shows "Chat scripts did not RUN" | `/assets/js/app.js` is being served as HTML — broken rewrite or missing file | Re-upload the app folder; run `bash bin/deploy.sh`; verify `curl -sI https://host/assets/js/app.js` returns `Content-Type: application/javascript` |
| "Chat scripts crashed" warning | A JavaScript error at runtime | Open the browser console; the message lists the first 3 errors. Usually a stale `app.js` — re-run deploy |
| Clean URLs return 404 (`/login`) | `.htaccess` missing or `mod_rewrite` off (Apache) | Run `bash bin/deploy.sh` (rewrites `.htaccess`); or use nginx config from section 3.2 |
| Still works but URL has `index.php/` | Rewrite not applied yet | Same as above |
| 500 on first load / "pdo_sqlite missing" | PHP lacks the SQLite driver | Install the `pdo_sqlite` extension and restart PHP-FPM |
| Database permission error | `data/` not writable by the web user | `chown -R <php-user> data/` or `chmod` to make it writable |
| `/api/version` wrong build | Old upload | Re-upload everything except `data/`, rerun `deploy.sh` |

---

## 8. Security notes

- Passwords: `argon2id` via `password_hash`/`password_verify`; rehashed
  automatically on login if the algorithm parameters change.
- Channel keys are also stored hashed with `argon2id`.
- CSRF tokens are required on every POST (forms and the AJAX API).
- All output is HTML-escaped; a small safe-markup renderer enables `**bold**`,
  `` `code` ``, `@mention`, and link-highlighting.
- Sending is rate-limited (per user, 12 messages/DMs per 5 seconds); global spam
  filters, shuns, and `*line` bans are enforced server-side, not just in the UI.
- Operators authenticate with per-user **o:lines** (**Admin → O-lines** — username
  + password + operator class). There is no shared operator password: `/oper`
  only works for the account matching the o:line's nickname. Guest accounts are
  ephemeral and auto-purged after a day of inactivity.
- There is an append-only `chat_logs` archive of every channel message and PM;
  nothing is ever removed from it. Admins have full visibility into this log.

Backups: `deploy.sh` snapshots `data/chat.db` automatically on each run; for a
full backup, `sqlite3 data/chat.db ".backup '/backup/chat.db'"` while the server
is running (safe in WAL mode) or copy the files at quiet times.