<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    /** Bump whenever schema.sql or the migration block below changes. */
    private const SCHEMA_VERSION = '9';

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
        if (!in_array('notify', $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN notify TEXT NOT NULL DEFAULT 'all'");
        }
        if (!in_array('avatar', $userCols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN avatar TEXT');
        }
        $chanCols = array_column($pdo->query('PRAGMA table_info(channels)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('censor', $chanCols, true)) {
            $pdo->exec('ALTER TABLE channels ADD COLUMN censor INTEGER NOT NULL DEFAULT 0');
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

        self::seedOperclasses($pdo);

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
            'motd' => "Welcome to LVChat!\n\nType /help for a list of slash commands.",
            'spamfilter_enabled' => '1',
            'uploads_enabled' => '1',
            'reactions_enabled' => '1',
            'webhooks_enabled' => '1',
            'max_channels_per_user' => '100',
            'presence_throttle' => '30',
            'poll_interval' => '2',
            'realtime' => 'poll',
            'peak_online' => '0',
        ];
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
}
