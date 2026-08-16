<?php

/**
 * LVChat — Discord-style web chat (PHP + SQLite)
 *
 * Copyright (C) LVChat contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * SPDX-License-Identifier: AGPL-3.0-only
 */



declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    /** Bump whenever schema.sql or the migration block below changes. */
    private const SCHEMA_VERSION = '37';

    /** Drop the cached connection so the next access re-opens it (used after fork). */
    public static function close(): void
    {
        self::$pdo = null;
    }

    public static function init(): void
    {
        if (self::$pdo !== null) {
            return;
        }
        // The database lives at ROOT/data/chat.db — beside the public/ docroot.
        // Because the web root is public/, the data/ folder is never web-accessible,
        // and the path stays inside open_basedir on shared hosts. Override with CHAT_DB.
        $path = getenv('CHAT_DB') ?: ROOT . '/data/chat.db';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $fresh = !file_exists($path);
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        self::$pdo = $pdo;

        // Fast path: schema already applied at the current version — skip all DDL.
        // (Fresh DBs have no tables yet, so skip straight to the slow path.)
        $schemaVersion = $fresh ? null : self::scalar('SELECT value FROM server_config WHERE key = "schema_version"');
        if ($schemaVersion === self::SCHEMA_VERSION) {
            return;
        }

        // Slow path (fresh install or schema upgrade): apply schema + migrations.
        // Guests must leave `users` BEFORE schema.sql runs, so the guests table
        // exists for its FKs and the guest columns exist for its indexes.
        self::migrateGuestsOut($pdo);

        $pdo->exec(file_get_contents(ROOT . '/schema.sql'));

        // Migrations for databases created before newer columns/tables existed.
        $userCols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('last_ip', $userCols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN last_ip TEXT');
        }
        if (!in_array('role_id', $userCols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN role_id INTEGER');
        }
        if (!in_array('guest', $userCols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN guest INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('theme', $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN theme TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('theme_json', $userCols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN theme_json TEXT');
        }
        if (!in_array('notify', $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN notify TEXT NOT NULL DEFAULT 'all'");
        }
        if (!in_array('avatar', $userCols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN avatar TEXT');
        }
        if (!in_array('status', $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN status TEXT NOT NULL DEFAULT 'active'");
        }
        if (!in_array('status_reason', $userCols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN status_reason TEXT');
        }
        if (!in_array('age_verified_at', $userCols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN age_verified_at TEXT');
        }
        if (!in_array('totp_secret', $userCols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN totp_secret TEXT');
        }
        if (!in_array('totp_enabled_at', $userCols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN totp_enabled_at TEXT');
        }
        // Rich presence statuses: online/away/dnd/invisible/custom (schema v30).
        if (!in_array('status_mode', $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN status_mode TEXT NOT NULL DEFAULT 'online'");
        }
        if (!in_array('custom_status', $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN custom_status TEXT NOT NULL DEFAULT ''");
        }
        $guestCols = array_column($pdo->query('PRAGMA table_info(guests)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('age_verified_at', $guestCols, true)) {
            $pdo->exec('ALTER TABLE guests ADD COLUMN age_verified_at TEXT');
        }
        $chanCols = array_column($pdo->query('PRAGMA table_info(channels)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('censor', $chanCols, true)) {
            $pdo->exec('ALTER TABLE channels ADD COLUMN censor INTEGER NOT NULL DEFAULT 0');
        }
        $pmCols = array_column($pdo->query('PRAGMA table_info(private_messages)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('kind', $pmCols, true)) {
            $pdo->exec("ALTER TABLE private_messages ADD COLUMN kind TEXT NOT NULL DEFAULT 'message'");
        }
        $tables = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('reactions', $tables, true)) {
            $pdo->exec('CREATE TABLE reactions (id INTEGER PRIMARY KEY AUTOINCREMENT, message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE, actor_type TEXT NOT NULL DEFAULT "user", actor_id INTEGER NOT NULL, emoji TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (message_id, actor_type, actor_id, emoji))');
            $pdo->exec('CREATE INDEX idx_reactions_msg ON reactions(message_id, emoji)');
        }
        if (!in_array('channel_notify', $tables, true)) {
            $pdo->exec('CREATE TABLE channel_notify (id INTEGER PRIMARY KEY AUTOINCREMENT, channel_id INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, mode TEXT NOT NULL DEFAULT "all", UNIQUE (channel_id, user_id))');
            $pdo->exec('CREATE INDEX idx_channel_notify_user ON channel_notify(user_id)');
        }
        // Sound alerts + per-user sound preferences/overrides (schema v13).
        if (!in_array('sound_alerts', $tables, true)) {
            $pdo->exec('CREATE TABLE sound_alerts (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, file TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, created_by INTEGER REFERENCES users(id) ON DELETE SET NULL, created_at TEXT NOT NULL DEFAULT (datetime("now")))');
        }
        if (!in_array('user_sound_prefs', $tables, true)) {
            $pdo->exec('CREATE TABLE user_sound_prefs (user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE, dm_sound_id INTEGER REFERENCES sound_alerts(id) ON DELETE SET NULL, channel_sound_id INTEGER REFERENCES sound_alerts(id) ON DELETE SET NULL)');
        }
        if (!in_array('user_sound_overrides', $tables, true)) {
            $pdo->exec('CREATE TABLE user_sound_overrides (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, target_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, sound_id INTEGER REFERENCES sound_alerts(id) ON DELETE CASCADE, created_at TEXT NOT NULL DEFAULT (datetime("now")), UNIQUE (user_id, target_user_id))');
            $pdo->exec('CREATE INDEX idx_sound_overrides_user ON user_sound_overrides(user_id)');
        }
        if (!in_array('webhooks', $tables, true)) {
            $pdo->exec('CREATE TABLE webhooks (id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT NOT NULL UNIQUE, channel_id INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE, name TEXT NOT NULL, avatar TEXT NOT NULL DEFAULT "", enabled INTEGER NOT NULL DEFAULT 1, created_by INTEGER REFERENCES users(id) ON DELETE SET NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, last_used TEXT)');
            $pdo->exec('CREATE INDEX idx_webhooks_channel ON webhooks(channel_id)');
        }
        if (!in_array('badwords', $tables, true)) {
            $pdo->exec('CREATE TABLE badwords (id INTEGER PRIMARY KEY AUTOINCREMENT, word TEXT NOT NULL, action TEXT NOT NULL DEFAULT "censor", enabled INTEGER NOT NULL DEFAULT 1)');
        }
        if (!in_array('roles', $tables, true)) {
            $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, color TEXT NOT NULL DEFAULT "#5865f2", perms TEXT NOT NULL DEFAULT "[]")');
        }
        if (in_array('roles', $tables, true)) {
            $roleCols = array_column($pdo->query('PRAGMA table_info(roles)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('helper', $roleCols, true)) {
                $pdo->exec('ALTER TABLE roles ADD COLUMN helper INTEGER NOT NULL DEFAULT 0');
            }
        }
        if (!in_array('operclasses', $tables, true)) {
            $pdo->exec('CREATE TABLE operclasses (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, color TEXT NOT NULL DEFAULT "#ffd700", perms TEXT NOT NULL DEFAULT "[]", is_default INTEGER NOT NULL DEFAULT 0)');
        }
        if (!in_array('opers', $tables, true)) {
            $pdo->exec('CREATE TABLE opers (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE COLLATE NOCASE, password_hash TEXT NOT NULL, operclass_id INTEGER NOT NULL REFERENCES operclasses(id) ON DELETE CASCADE, enabled INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        }
        if (!in_array('chat_logs', $tables, true)) {
            $pdo->exec('CREATE TABLE chat_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, channel_name TEXT, user_id INTEGER, username TEXT, kind TEXT NOT NULL DEFAULT "message", content TEXT NOT NULL DEFAULT "", guest INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        } else {
            $logCols = array_column($pdo->query('PRAGMA table_info(chat_logs)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('guest', $logCols, true)) {
                $pdo->exec('ALTER TABLE chat_logs ADD COLUMN guest INTEGER NOT NULL DEFAULT 0');
            }
        }

        if (!in_array('login_attempts', $tables, true)) {
            $pdo->exec('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
            $pdo->exec('CREATE INDEX idx_login_attempts_ip ON login_attempts(ip, attempted_at)');
        }
        if (!in_array('registration_attempts', $tables, true)) {
            $pdo->exec('CREATE TABLE registration_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
            $pdo->exec('CREATE INDEX idx_registration_attempts_ip ON registration_attempts(ip, attempted_at)');
        }
        if (!in_array('registration_invites', $tables, true)) {
            $pdo->exec('CREATE TABLE registration_invites (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL COLLATE NOCASE, token TEXT NOT NULL UNIQUE, invited_by INTEGER REFERENCES users(id) ON DELETE SET NULL, message TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, expires_at TEXT NOT NULL, used_at TEXT, used_by_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL)');
            $pdo->exec('CREATE INDEX idx_registration_invites_email ON registration_invites(email)');
        }
        // One-time auth tokens: password resets + magic-link logins (schema v22).
        if (!in_array('auth_tokens', $tables, true)) {
            $pdo->exec('CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, token TEXT NOT NULL UNIQUE, type TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (datetime(\'now\')), expires_at TEXT NOT NULL, used_at TEXT)');
            $pdo->exec('CREATE INDEX idx_auth_tokens_token ON auth_tokens(token)');
        }

        // Realtime gateway support (schema v23): one-time WebSocket handshake
        // tickets. Clients present a ticket when they connect to the gateway so
        // the long-lived session token never has to reach JavaScript.
        if (!in_array('ws_tickets', $tables, true)) {
            $pdo->exec('CREATE TABLE ws_tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER REFERENCES users(id) ON DELETE CASCADE, guest_id INTEGER REFERENCES guests(id) ON DELETE CASCADE, token TEXT NOT NULL UNIQUE, created_at TEXT NOT NULL DEFAULT (datetime(\'now\')), expires_at TEXT NOT NULL)');
            $pdo->exec('CREATE INDEX idx_ws_tickets_token ON ws_tickets(token)');
            $pdo->exec('CREATE INDEX idx_ws_tickets_expires ON ws_tickets(expires_at)');
        }

        // Push notifications (schema v27): Web Push subscriptions, per-context
        // push toggles, and the all-surface per-user mute list.
        if (!in_array('push_subscriptions', $tables, true)) {
            $pdo->exec('CREATE TABLE push_subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, endpoint TEXT NOT NULL UNIQUE, p256dh TEXT NOT NULL, auth TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (datetime(\'now\')), last_seen TEXT)');
            $pdo->exec('CREATE INDEX idx_push_subs_user ON push_subscriptions(user_id)');
        }
        if (!in_array('user_push_prefs', $tables, true)) {
            $pdo->exec('CREATE TABLE user_push_prefs (user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE, channels INTEGER NOT NULL DEFAULT 1, dms INTEGER NOT NULL DEFAULT 1, invites INTEGER NOT NULL DEFAULT 1)');
        }
        if (!in_array('user_mutes', $tables, true)) {
            $pdo->exec('CREATE TABLE user_mutes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, muted_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, created_at TEXT NOT NULL DEFAULT (datetime(\'now\')), UNIQUE (user_id, muted_user_id))');
            $pdo->exec('CREATE INDEX idx_user_mutes_user ON user_mutes(user_id)');
        }

        // Realtime transport report: each browser records which realtime
        // transport it actually uses (ws/sse/poll) so the admin UI can surface
        // silent fallbacks instead of showing a phantom WebSocket count.
        if (!in_array('rt_transports', $tables, true)) {
            $pdo->exec('CREATE TABLE rt_transports (actor_id INTEGER NOT NULL, guest INTEGER NOT NULL DEFAULT 0, transport TEXT NOT NULL DEFAULT \'poll\', updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')), PRIMARY KEY (actor_id, guest))');
            $pdo->exec('CREATE INDEX idx_rt_transports_updated ON rt_transports(updated_at)');
        }

        // Moderation / reporting / support (added in schema v13).
        if (!in_array('moderation_events', $tables, true)) {
            $pdo->exec('CREATE TABLE moderation_events (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER REFERENCES users(id) ON DELETE SET NULL, guest_id INTEGER REFERENCES guests(id) ON DELETE SET NULL, kind TEXT NOT NULL, action TEXT NOT NULL DEFAULT "applied", match TEXT NOT NULL DEFAULT "", content TEXT NOT NULL DEFAULT "", target TEXT NOT NULL DEFAULT "", channel_id INTEGER REFERENCES channels(id) ON DELETE SET NULL, created_at TEXT NOT NULL DEFAULT (datetime("now")))');
            $pdo->exec('CREATE INDEX idx_moderation_events_user ON moderation_events(user_id, id)');
            $pdo->exec('CREATE INDEX idx_moderation_events_guest ON moderation_events(guest_id, id)');
        }
        if (!in_array('reports', $tables, true)) {
            $pdo->exec('CREATE TABLE reports (id INTEGER PRIMARY KEY AUTOINCREMENT, message_id INTEGER REFERENCES messages(id) ON DELETE SET NULL, pm INTEGER NOT NULL DEFAULT 0, channel_id INTEGER REFERENCES channels(id) ON DELETE SET NULL, reporter_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL, reporter_guest_id INTEGER REFERENCES guests(id) ON DELETE SET NULL, sender_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL, sender_guest_id INTEGER REFERENCES guests(id) ON DELETE SET NULL, sender_name TEXT NOT NULL DEFAULT "", content TEXT NOT NULL DEFAULT "", kind TEXT NOT NULL DEFAULT "message", reason TEXT NOT NULL DEFAULT "", reason_other TEXT NOT NULL DEFAULT "", status TEXT NOT NULL DEFAULT "open", handled_by INTEGER REFERENCES users(id) ON DELETE SET NULL, handled_at TEXT, resolution TEXT, created_at TEXT NOT NULL DEFAULT (datetime("now")))');
            $pdo->exec('CREATE INDEX idx_reports_status ON reports(status, id)');
        } else {
            $repCols = array_column($pdo->query('PRAGMA table_info(reports)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('kind', $repCols, true)) {
                $pdo->exec('ALTER TABLE reports ADD COLUMN kind TEXT NOT NULL DEFAULT "message"');
            }
        }
        if (!in_array('user_notes', $tables, true)) {
            $pdo->exec('CREATE TABLE user_notes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL, action TEXT NOT NULL DEFAULT "note", reason TEXT NOT NULL DEFAULT "", created_at TEXT NOT NULL DEFAULT (datetime("now")))');
            $pdo->exec('CREATE INDEX idx_user_notes_user ON user_notes(user_id, id)');
        }
        if (!in_array('support_tickets', $tables, true)) {
            $pdo->exec('CREATE TABLE support_tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, subject TEXT NOT NULL, status TEXT NOT NULL DEFAULT "open", assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL, created_at TEXT NOT NULL DEFAULT (datetime("now")), updated_at TEXT NOT NULL DEFAULT (datetime("now")), closed_at TEXT)');
            $pdo->exec('CREATE INDEX idx_support_tickets_status ON support_tickets(status, id)');
        } else {
            // v14: support email-only tickets (user_id nullable + email column +
            // opened_by). SQLite can't alter NOT NULL, so rebuild the table.
            $stCols = array_column($pdo->query('PRAGMA table_info(support_tickets)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('email', $stCols, true)) {
                $pdo->exec('PRAGMA foreign_keys = OFF');
                $pdo->exec('CREATE TABLE support_tickets_new (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER REFERENCES users(id) ON DELETE CASCADE, email TEXT, subject TEXT NOT NULL, status TEXT NOT NULL DEFAULT "open", assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL, opened_by INTEGER REFERENCES users(id) ON DELETE SET NULL, created_at TEXT NOT NULL DEFAULT (datetime(\'now\')), updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')), closed_at TEXT)');
                $pdo->exec('INSERT INTO support_tickets_new (id, user_id, subject, status, assigned_to, created_at, updated_at, closed_at) SELECT id, user_id, subject, status, assigned_to, created_at, updated_at, closed_at FROM support_tickets');
                $pdo->exec('DROP TABLE support_tickets');
                $pdo->exec('ALTER TABLE support_tickets_new RENAME TO support_tickets');
                $pdo->exec('CREATE INDEX idx_support_tickets_status ON support_tickets(status, id)');
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
        if (!in_array('support_ticket_replies', $tables, true)) {
            $pdo->exec('CREATE TABLE support_ticket_replies (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE, author_id INTEGER REFERENCES users(id) ON DELETE SET NULL, is_staff INTEGER NOT NULL DEFAULT 0, content TEXT NOT NULL, attachments TEXT DEFAULT NULL, created_at TEXT NOT NULL DEFAULT (datetime("now")))');
            $pdo->exec('CREATE INDEX idx_support_replies_ticket ON support_ticket_replies(ticket_id, id)');
        } else {
            $replyCols = array_column($pdo->query('PRAGMA table_info(support_ticket_replies)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('attachments', $replyCols, true)) {
                $pdo->exec('ALTER TABLE support_ticket_replies ADD COLUMN attachments TEXT DEFAULT NULL');
            }
        }

        // Per-channel logging toggle (schema v18).
        $chanCols = array_column($pdo->query('PRAGMA table_info(channels)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('no_logging', $chanCols, true)) {
            $pdo->exec('ALTER TABLE channels ADD COLUMN no_logging INTEGER NOT NULL DEFAULT 0');
        }
        // Per-channel chat background (owner-set image/colour, schema v24).
        if (!in_array('bg_image', $chanCols, true)) {
            $pdo->exec('ALTER TABLE channels ADD COLUMN bg_image TEXT');
        }
        if (!in_array('bg_color', $chanCols, true)) {
            $pdo->exec('ALTER TABLE channels ADD COLUMN bg_color TEXT');
        }
        // Per-channel background image fit (schema v25): defaults to "contain".
        if (!in_array('bg_fit', $chanCols, true)) {
            $pdo->exec("ALTER TABLE channels ADD COLUMN bg_fit TEXT NOT NULL DEFAULT 'contain'");
        }
        // Per-channel background overlay opacity (schema v26): 0–100.
        if (!in_array('bg_overlay', $chanCols, true)) {
            $pdo->exec('ALTER TABLE channels ADD COLUMN bg_overlay INTEGER NOT NULL DEFAULT 55');
        }
        // Channel URL + global banned URL/domain list (schema v33): a channel
        // can embed a web page above its chat, and admins maintain the list of
        // hosts that may never be used for it.
        $chanCols = array_column($pdo->query('PRAGMA table_info(channels)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('channel_url', $chanCols, true)) {
            $pdo->exec('ALTER TABLE channels ADD COLUMN channel_url TEXT');
        }
        if (!in_array('banned_urls', $tables, true)) {
            $pdo->exec('CREATE TABLE banned_urls (id INTEGER PRIMARY KEY AUTOINCREMENT, domain TEXT NOT NULL UNIQUE COLLATE NOCASE, reason TEXT NOT NULL DEFAULT "", created_by INTEGER REFERENCES users(id) ON DELETE SET NULL, created_at TEXT NOT NULL DEFAULT (datetime("now")))');
        }

        // OpenClaw AI bots (schema v20).
        if (!in_array('openclaw_bots', $tables, true)) {
            $pdo->exec('CREATE TABLE openclaw_bots (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, username TEXT NOT NULL UNIQUE COLLATE NOCASE, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, api_key_hash TEXT NOT NULL UNIQUE, avatar TEXT NOT NULL DEFAULT \'\', system_prompt TEXT NOT NULL DEFAULT \'\', enabled INTEGER NOT NULL DEFAULT 1, last_seen TEXT, created_by INTEGER REFERENCES users(id) ON DELETE SET NULL, created_at TEXT NOT NULL DEFAULT (datetime(\'now\')))');
            $pdo->exec('CREATE INDEX idx_openclaw_bots_user ON openclaw_bots(user_id)');
        } else {
            $ocCols = array_column($pdo->query('PRAGMA table_info(openclaw_bots)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (in_array('gateway_url', $ocCols, true)) {
                $pdo->exec('ALTER TABLE openclaw_bots RENAME COLUMN gateway_url TO _deprecated_gateway_url');
            }
            if (in_array('api_key', $ocCols, true) && !in_array('api_key_hash', $ocCols, true)) {
                $pdo->exec('ALTER TABLE openclaw_bots ADD COLUMN api_key_hash TEXT');
            }
            if (!in_array('last_seen', $ocCols, true)) {
                $pdo->exec('ALTER TABLE openclaw_bots ADD COLUMN last_seen TEXT');
            }
        }
        if (!in_array('openclaw_bot_channels', $tables, true)) {
            $pdo->exec('CREATE TABLE openclaw_bot_channels (id INTEGER PRIMARY KEY AUTOINCREMENT, bot_id INTEGER NOT NULL REFERENCES openclaw_bots(id) ON DELETE CASCADE, channel_id INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE, respond_mode TEXT NOT NULL DEFAULT \'mentions\', UNIQUE (bot_id, channel_id))');
            $pdo->exec('CREATE INDEX idx_openclaw_bc_bot ON openclaw_bot_channels(bot_id)');
            $pdo->exec('CREATE INDEX idx_openclaw_bc_channel ON openclaw_bot_channels(channel_id)');
        }
        if (!in_array('openclaw_bot_pm_access', $tables, true)) {
            $pdo->exec('CREATE TABLE openclaw_bot_pm_access (id INTEGER PRIMARY KEY AUTOINCREMENT, bot_id INTEGER NOT NULL REFERENCES openclaw_bots(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, UNIQUE (bot_id, user_id))');
            $pdo->exec('CREATE INDEX idx_openclaw_pm_bot ON openclaw_bot_pm_access(bot_id)');
            $pdo->exec('CREATE INDEX idx_openclaw_pm_user ON openclaw_bot_pm_access(user_id)');
        }

        // Friends system (schema v17).
        if (!in_array('friendships', $tables, true)) {
            $pdo->exec('CREATE TABLE friendships (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, friend_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, status TEXT NOT NULL DEFAULT \'pending\', created_at TEXT NOT NULL DEFAULT (datetime(\'now\')), updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')), UNIQUE (user_id, friend_id))');
            $pdo->exec('CREATE INDEX idx_friendships_user ON friendships(user_id, status)');
            $pdo->exec('CREATE INDEX idx_friendships_friend ON friendships(friend_id, status)');
        }

        // Modules system (schema v35): one row per discovered module directory.
        if (!in_array('modules', $tables, true)) {
            $pdo->exec('CREATE TABLE modules (id TEXT PRIMARY KEY, name TEXT NOT NULL DEFAULT "", version TEXT NOT NULL DEFAULT "", enabled INTEGER NOT NULL DEFAULT 1, license TEXT NOT NULL DEFAULT "", license_status TEXT NOT NULL DEFAULT "", license_checked_at TEXT, license_expires_at TEXT, config TEXT, installed_at TEXT NOT NULL DEFAULT (datetime("now")), updated_at TEXT)');
        }

        // TOTP replay prevention: track used counters per user to prevent code
        // reuse within the ±1 verification window (a global counter store could
        // be DoSed by one user blocking all other 30-second buckets).
        if (!in_array('totp_used_counters', $tables, true)) {
            $pdo->exec('CREATE TABLE totp_used_counters (user_id INTEGER NOT NULL DEFAULT 0, counter INTEGER NOT NULL, expires_at TEXT NOT NULL, PRIMARY KEY (user_id, counter))');
        } else {
            // Older installs created the table keyed on counter only — migrate.
            $cols = array_column($pdo->query('PRAGMA table_info(totp_used_counters)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('user_id', $cols, true)) {
                $pdo->exec('ALTER TABLE totp_used_counters RENAME TO totp_used_counters_old');
                $pdo->exec('CREATE TABLE totp_used_counters (user_id INTEGER NOT NULL DEFAULT 0, counter INTEGER NOT NULL, expires_at TEXT NOT NULL, PRIMARY KEY (user_id, counter))');
                $pdo->exec('INSERT INTO totp_used_counters (user_id, counter, expires_at) SELECT 0, counter, expires_at FROM totp_used_counters_old');
                $pdo->exec('DROP TABLE totp_used_counters_old');
            }
        }

        // Analytics indexes (schema v16): keep range-aggregation charts off full scans.
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_created ON messages(created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_logs_created ON chat_logs(created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pm_created ON private_messages(created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_moderation_events_created ON moderation_events(created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_created ON reports(created_at)');
        // TOTP replay-prevention cleanup needs expires_at for efficient DELETE.
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_totp_used_counters_expires ON totp_used_counters(expires_at)');

        // Backfill the FTS index from any pre-existing messages (new rows are
        // indexed by the triggers created above). Only rebuild when it lags.
        if (self::fts5($pdo)) {
            $fts = (int) $pdo->query('SELECT COUNT(*) FROM messages_fts')->fetchColumn();
            $msg = (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
            if ($fts < $msg) {
                $pdo->exec('INSERT INTO messages_fts(messages_fts) VALUES("rebuild")');
            }
        }

        // Rename the default site name on installs created before the rebrand.
        $pdo->exec("UPDATE server_config SET value = 'LVChat' WHERE key = 'site_name' AND value = 'Chat Relay'");

        // sound_alerts name-dedupe (schema v31): the old seed could produce
        // duplicate-named rows on some installs, which made the client sound
        // pickers list the same tone twice. Keep the lowest id, remap any
        // pref/override references to it, then enforce uniqueness by name.
        if (in_array('sound_alerts', $tables, true)) {
            $dupNames = $pdo->query('SELECT lower(trim(name)) AS n FROM sound_alerts GROUP BY n HAVING COUNT(*) > 1')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($dupNames as $n) {
                $qn = $pdo->quote($n);
                $keep = (int) $pdo->query('SELECT MIN(id) FROM sound_alerts WHERE lower(trim(name)) = ' . $qn)->fetchColumn();
                if ($keep < 1) {
                    continue;
                }
                $pdo->exec('UPDATE user_sound_prefs SET dm_sound_id = ' . $keep . ' WHERE dm_sound_id IN (SELECT id FROM sound_alerts WHERE lower(trim(name)) = ' . $qn . ' AND id != ' . $keep . ')');
                $pdo->exec('UPDATE user_sound_prefs SET channel_sound_id = ' . $keep . ' WHERE channel_sound_id IN (SELECT id FROM sound_alerts WHERE lower(trim(name)) = ' . $qn . ' AND id != ' . $keep . ')');
                $pdo->exec('UPDATE user_sound_overrides SET sound_id = ' . $keep . ' WHERE sound_id IN (SELECT id FROM sound_alerts WHERE lower(trim(name)) = ' . $qn . ' AND id != ' . $keep . ')');
                $pdo->exec('DELETE FROM sound_alerts WHERE lower(trim(name)) = ' . $qn . ' AND id != ' . $keep);
            }
            try {
                $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_sound_alerts_name ON sound_alerts(lower(trim(name)))');
            } catch (Exception $e) {
                // Duplicates were pruned above; treat a leftover index failure
                // as non-fatal (older SQLite without expression indexes).
            }
        }

        // Privacy: searchable flag on users (schema v36).
        if (in_array('users', $tables, true)) {
            $colRows = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
            $colNames = array_column($colRows, 'name');
            if (!in_array('searchable', $colNames, true)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN searchable INTEGER NOT NULL DEFAULT 1");
            }
        }

        self::seedOperclasses($pdo);
        self::seedSoundAlerts($pdo);

        // New defaults added after the app shipped need a row on upgrade (seed()
        // only runs on fresh installs). Use OR IGNORE so an admin's value survives.
        $pdo->exec("INSERT OR IGNORE INTO server_config (key, value) VALUES ('registration_rate_limit', '20')");
        // The admin-only operator action log channel (idempotent on upgrade).
        $pdo->exec("INSERT OR IGNORE INTO channels (name, slug, topic, description, visibility, topic_locked, registered_at)
            VALUES ('#oper-log', 'oper-log', 'Operator action log', 'Admin-only log of every action taken by admins and opers', 'private', 1, datetime('now'))");
        $pdo->exec("INSERT OR IGNORE INTO channel_members (channel_id, user_id, level)
            SELECT c.id, u.id, 'normal' FROM channels c JOIN users u ON u.role = 'admin'
            WHERE c.slug = 'oper-log'");
        foreach (['desktop', 'messenger'] as $dlApp) {
            foreach (['win', 'mac', 'linux_rpm', 'linux_deb', 'linux_appimage'] as $dlPlat) {
                $pdo->exec("INSERT OR IGNORE INTO server_config (key, value) VALUES ('download_{$dlApp}_{$dlPlat}_url', '')");
                $pdo->exec("INSERT OR IGNORE INTO server_config (key, value) VALUES ('download_{$dlApp}_{$dlPlat}_version', '')");
            }
        }
        $pdo->exec("INSERT OR IGNORE INTO server_config (key, value) VALUES ('download_update_url', '')");
        $pdo->exec("INSERT OR IGNORE INTO server_config (key, value) VALUES ('updater_enabled', '0')");
        $pdo->exec("INSERT OR IGNORE INTO server_config (key, value) VALUES ('updater_url', '')");

        $pdo->exec("INSERT OR REPLACE INTO server_config (key, value) VALUES ('schema_version', '" . self::SCHEMA_VERSION . "')");

        if ($fresh) {
            self::seed();
        }
    }

    public static function instance(): PDO
    {
        self::init();
        return self::$pdo;
    }

    /** Whether the underlying SQLite build ships FTS5 (drives full-text search). */
    public static function fts5(?PDO $pdo = null): bool
    {
        $pdo = $pdo ?: self::instance();
        $opts = $pdo->query('PRAGMA compile_options')->fetchAll(PDO::FETCH_COLUMN);
        return in_array('ENABLE_FTS5', $opts, true);
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::instance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        return self::query($sql, $params)->fetchColumn();
    }

    public static function lastId(): int
    {
        return (int) self::instance()->lastInsertId();
    }

    /**
     * Move anonymous guests out of `users` into the dedicated `guests` table.
     * Runs on the schema-upgrade path; no-ops on fresh installs. Legacy guest
     * rows (users.guest = 1) are copied to `guests` and every reference is
     * remapped to the new guest id, so DMs / memberships / notifications survive.
     */
    private static function migrateGuestsOut(PDO $pdo): void
    {
        $tables = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('guests', $tables, true)) {
            $pdo->exec('CREATE TABLE guests (id INTEGER PRIMARY KEY AUTOINCREMENT, nick TEXT NOT NULL UNIQUE COLLATE NOCASE, ip TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen TEXT)');
            $pdo->exec('CREATE TABLE guest_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, guest_id INTEGER NOT NULL REFERENCES guests(id) ON DELETE CASCADE, token TEXT NOT NULL UNIQUE, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, expires_at TEXT NOT NULL)');
        }

        $uCols = in_array('users', $tables, true) ? array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name') : [];
        $map = [];
        if (in_array('guest', $uCols, true)) {
            $rows = $pdo->query('SELECT id, username, last_ip, registered_at, last_seen FROM users WHERE guest = 1')->fetchAll(PDO::FETCH_ASSOC);
            $ins = $pdo->prepare('INSERT INTO guests (nick, ip, created_at, last_seen) VALUES (?, ?, ?, ?)');
            foreach ($rows as $r) {
                $ins->execute([$r['username'], $r['last_ip'], $r['registered_at'], $r['last_seen']]);
                $map[(int) $r['id']] = (int) $pdo->lastInsertId();
            }
        }

        $mapId = static function (mixed $id) use ($map): ?int {
            return $id === null ? null : ($map[(int) $id] ?? (int) $id);
        };

        // private_messages → sender_guest_id / recipient_guest_id
        if (in_array('private_messages', $tables, true)) {
            $cols = array_column($pdo->query('PRAGMA table_info(private_messages)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('sender_guest_id', $cols, true)) {
                $rows = $pdo->query('SELECT id, sender_id, recipient_id, content, created_at, read_at FROM private_messages')->fetchAll(PDO::FETCH_ASSOC);
                $pdo->exec('DROP TABLE private_messages');
                $pdo->exec('CREATE TABLE private_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, sender_id INTEGER REFERENCES users(id) ON DELETE CASCADE, recipient_id INTEGER REFERENCES users(id) ON DELETE CASCADE, sender_guest_id INTEGER REFERENCES guests(id) ON DELETE CASCADE, recipient_guest_id INTEGER REFERENCES guests(id) ON DELETE CASCADE, content TEXT NOT NULL DEFAULT "", created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, read_at TEXT)');
                $ins2 = $pdo->prepare('INSERT INTO private_messages (id, sender_id, recipient_id, sender_guest_id, recipient_guest_id, content, created_at, read_at) VALUES (?,?,?,?,?,?,?,?)');
                foreach ($rows as $r) {
                    $ins2->execute([
                        $r['id'],
                        isset($map[(int) $r['sender_id']]) ? null : $r['sender_id'],
                        isset($map[(int) $r['recipient_id']]) ? null : $r['recipient_id'],
                        $map[(int) $r['sender_id']] ?? null,
                        $map[(int) $r['recipient_id']] ?? null,
                        $r['content'], $r['created_at'], $r['read_at'],
                    ]);
                }
            }
        }

        // channel_members → guest_id
        if (in_array('channel_members', $tables, true)) {
            $cols = array_column($pdo->query('PRAGMA table_info(channel_members)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('guest_id', $cols, true)) {
                $rows = $pdo->query('SELECT channel_id, user_id, level, joined_at, last_read_id FROM channel_members')->fetchAll(PDO::FETCH_ASSOC);
                $pdo->exec('DROP TABLE channel_members');
                $pdo->exec('CREATE TABLE channel_members (id INTEGER PRIMARY KEY AUTOINCREMENT, channel_id INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE, user_id INTEGER REFERENCES users(id) ON DELETE CASCADE, guest_id INTEGER REFERENCES guests(id) ON DELETE CASCADE, level TEXT NOT NULL DEFAULT "normal", joined_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, last_read_id INTEGER NOT NULL DEFAULT 0)');
                $ins3 = $pdo->prepare('INSERT INTO channel_members (channel_id, user_id, guest_id, level, joined_at, last_read_id) VALUES (?,?,?,?,?,?)');
                foreach ($rows as $r) {
                    $uid = (int) $r['user_id'];
                    $ins3->execute([
                        $r['channel_id'],
                        isset($map[$uid]) ? null : $uid,
                        isset($map[$uid]) ? $map[$uid] : null,
                        $r['level'], $r['joined_at'], $r['last_read_id'],
                    ]);
                }
            }
        }

        // messages → sender_guest_id
        if (in_array('messages', $tables, true)) {
            $cols = array_column($pdo->query('PRAGMA table_info(messages)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('sender_guest_id', $cols, true)) {
                $pdo->exec('ALTER TABLE messages ADD COLUMN sender_guest_id INTEGER REFERENCES guests(id) ON DELETE SET NULL');
            }
            if ($map) {
                $rows = $pdo->query('SELECT id, sender_id FROM messages WHERE sender_id IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
                $upd = $pdo->prepare('UPDATE messages SET sender_id = NULL, sender_guest_id = ? WHERE id = ?');
                foreach ($rows as $r) {
                    if (isset($map[(int) $r['sender_id']])) {
                        $upd->execute([$map[(int) $r['sender_id']], $r['id']]);
                    }
                }
            }
        }

        // notifications → guest_user_id (recipient) / sender_guest_id (sender)
        if (in_array('notifications', $tables, true)) {
            $cols = array_column($pdo->query('PRAGMA table_info(notifications)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('guest_user_id', $cols, true)) {
                $rows = $pdo->query('SELECT id, user_id, kind, channel_id, sender_id, message_id, read, created_at FROM notifications')->fetchAll(PDO::FETCH_ASSOC);
                $pdo->exec('DROP TABLE notifications');
                $pdo->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER REFERENCES users(id) ON DELETE CASCADE, guest_user_id INTEGER REFERENCES guests(id) ON DELETE CASCADE, kind TEXT NOT NULL, channel_id INTEGER REFERENCES channels(id) ON DELETE CASCADE, sender_id INTEGER REFERENCES users(id) ON DELETE SET NULL, sender_guest_id INTEGER REFERENCES guests(id) ON DELETE SET NULL, message_id INTEGER, read INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
                $ins4 = $pdo->prepare('INSERT INTO notifications (id, user_id, guest_user_id, kind, channel_id, sender_id, sender_guest_id, message_id, read, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
                foreach ($rows as $r) {
                    $uid = (int) $r['user_id'];
                    $sid = $r['sender_id'] === null ? null : (int) $r['sender_id'];
                    $ins4->execute([
                        $r['id'],
                        isset($map[$uid]) ? null : $uid,
                        isset($map[$uid]) ? $map[$uid] : null,
                        $r['kind'], $r['channel_id'],
                        $sid !== null && isset($map[$sid]) ? null : $sid,
                        $sid !== null && isset($map[$sid]) ? $map[$sid] : null,
                        $r['message_id'], $r['read'], $r['created_at'],
                    ]);
                }
            }
        }

        // Guest sessions move from `sessions` to `guest_sessions`.
        if ($map) {
            $rows = $pdo->query('SELECT token, user_id, created_at, expires_at FROM sessions')->fetchAll(PDO::FETCH_ASSOC);
            $del = $pdo->prepare('DELETE FROM sessions WHERE token = ?');
            $ins5 = $pdo->prepare('INSERT INTO guest_sessions (guest_id, token, created_at, expires_at) VALUES (?,?,?,?)');
            foreach ($rows as $r) {
                if (isset($map[(int) $r['user_id']])) {
                    $ins5->execute([$map[(int) $r['user_id']], $r['token'], $r['created_at'], $r['expires_at']]);
                    $del->execute([$r['token']]);
                }
            }
            $pdo->exec('DELETE FROM users WHERE guest = 1');
        }
    }

    private static function seed(): void
    {
        $db = self::instance();
        $config = [
            'site_name' => 'LVChat',
            'registration_enabled' => '1',
            'registration_requires_approval' => '0',
            'registration_rate_limit' => '20',
            'motd' => "Welcome to LVChat!\n\nType /help for a list of slash commands.",
            'spamfilter_enabled' => '1',
            'uploads_enabled' => '1',
            'reactions_enabled' => '1',
            'gifs_enabled' => '1',
            'webhooks_enabled' => '1',
            'chat_logging_enabled' => '1',
            'max_channels_per_user' => '100',
            'presence_throttle' => '30',
            'poll_interval' => '2',
            'realtime' => 'poll',
            'realtime_force' => '0',
            'ws_ip' => '0.0.0.0',
            'ws_url' => '',
            'ws_port' => '8080',
            'ws_ssl_cert' => '',
            'ws_ssl_key' => '',
            'ws_push_url' => 'http://127.0.0.1:9001/push',
            'ws_push_secret' => bin2hex(random_bytes(16)),
            'peak_online' => '0',
            'mfa_require_admin' => '1',
            'mfa_require_staff' => '0',
            'mfa_require_user' => '0',
            'theme_user_customization' => '1',
        ];
        foreach (['desktop', 'messenger'] as $dlApp) {
            foreach (['win', 'mac', 'linux_rpm', 'linux_deb', 'linux_appimage'] as $dlPlat) {
                $config["download_{$dlApp}_{$dlPlat}_url"] = '';
                $config["download_{$dlApp}_{$dlPlat}_version"] = '';
            }
        }
        $config['download_update_url'] = '';
        $config['updater_enabled'] = '0';
        $config['updater_url'] = '';
        $ins = $db->prepare('INSERT OR REPLACE INTO server_config (key, value) VALUES (?, ?)');
        foreach ($config as $k => $v) {
            $ins->execute([$k, $v]);
        }

        $chan = $db->prepare(
            'INSERT OR IGNORE INTO channels (name, slug, topic, description, visibility, topic_locked, registered_at)
             VALUES (?, ?, ?, ?, ?, 1, datetime("now"))'
        );
        $chan->execute(['#general', 'general', 'General discussion', 'Public chat for everyone on the server', 'public']);
        $chan->execute(['#help', 'help', 'Need help? Ask here.', 'Get help from the community and staff', 'public']);
        $chan->execute(['#staff', 'staff', 'Staff coordination', 'Admins and staff only', 'staff']);
        $chan->execute(['#oper-log', 'oper-log', 'Operator action log', 'Admin-only log of every action taken by admins and opers', 'private']);
    }

    /** Ensure the built-in operator classes exist (idempotent). */
    private static function seedOperclasses(PDO $pdo): void
    {
        $defaults = [
            'netadmin' => ['oper', 'manage_users', 'manage_channels', 'manage_bans', 'manage_badwords', 'manage_roles', 'manage_opers', 'rehash'],
            'serveradmin' => ['oper', 'manage_channels', 'manage_bans', 'manage_badwords', 'manage_opers', 'rehash'],
            'globalop' => ['oper', 'manage_bans'],
            'localop' => ['oper'],
        ];
        $ins = $pdo->prepare('INSERT OR IGNORE INTO operclasses (name, perms, is_default) VALUES (?, ?, 1)');
        foreach ($defaults as $name => $perms) {
            $ins->execute([$name, json_encode($perms)]);
        }
    }

    /**
     * Ensure at least three built-in sound alerts exist. On a fresh install (or
     * any DB with no sounds yet) the default WAVs are generated on disk with a
     * dependency-free pure-PHP writer, so the server never needs ffmpeg.
     */
    private static function seedSoundAlerts(PDO $pdo): void
    {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM sound_alerts')->fetchColumn();
        if ($count > 0) {
            return;
        }
        $defaults = [
            ['Ding', 880.0, 0.40],
            ['Pop', 440.0, 0.18],
            ['Chime', 1046.5, 0.60],
        ];
        $dir = ROOT . '/public/assets/sounds';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $ins = $pdo->prepare('INSERT INTO sound_alerts (name, file) VALUES (?, ?)');
        foreach ($defaults as [$name, $freq, $dur]) {
            $slug = strtolower(str_replace(' ', '-', $name));
            $file = $dir . '/' . $slug . '.wav';
            if (!file_exists($file)) {
                SoundService::writeDefaultWav($file, $name, $freq, $dur);
            }
            // Only register a default whose file is actually on disk (a
            // read-only web root that lost the committed files just skips it).
            if (file_exists($file)) {
                $ins->execute([$name, '/assets/sounds/' . $slug . '.wav']);
            }
        }
    }
}
