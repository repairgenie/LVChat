<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    /** Bump whenever schema.sql or the migration block below changes. */
    private const SCHEMA_VERSION = '1';

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
        $chanCols = array_column($pdo->query('PRAGMA table_info(channels)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('censor', $chanCols, true)) {
            $pdo->exec('ALTER TABLE channels ADD COLUMN censor INTEGER NOT NULL DEFAULT 0');
        }
        $tables = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC), 'name');
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
            $pdo->exec('CREATE TABLE opers (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE COLLATE NOCASE, password_hash TEXT NOT NULL, operclass_id INTEGER NOT NULL REFERENCES operclasses(id) ON DELETE CASCADE, enabled INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT (datetime("now")))');
        }
        if (!in_array('chat_logs', $tables, true)) {
            $pdo->exec('CREATE TABLE chat_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, channel_name TEXT, user_id INTEGER, username TEXT, kind TEXT NOT NULL DEFAULT "message", content TEXT NOT NULL DEFAULT "", guest INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT (datetime("now")))');
        } else {
            $logCols = array_column($pdo->query('PRAGMA table_info(chat_logs)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('guest', $logCols, true)) {
                $pdo->exec('ALTER TABLE chat_logs ADD COLUMN guest INTEGER NOT NULL DEFAULT 0');
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

    private static function seed(): void
    {
        $db = self::instance();
        $config = [
            'site_name' => 'LVChat',
            'registration_enabled' => '1',
            'motd' => "Welcome to LVChat!\n\nType /help for a list of slash commands.",
            'spamfilter_enabled' => '1',
            'max_channels_per_user' => '100',
            'presence_throttle' => '30',
            'poll_interval' => '2',
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
