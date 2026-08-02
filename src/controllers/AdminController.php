<?php

declare(strict_types=1);

final class AdminController
{
    private static function require(): array
    {
        return Auth::requireAdmin();
    }

    public static function overview(): void
    {
        $admin = self::require();
        $stats = [
            'Total users' => (int) Database::scalar('SELECT COUNT(*) FROM users'),
            'Online now' => (int) Database::scalar('SELECT COUNT(*) FROM users WHERE last_seen >= datetime("now", "-30 seconds") AND away IS NULL'),
            'Channels' => (int) Database::scalar('SELECT COUNT(*) FROM channels'),
            'Messages logged' => (int) Database::scalar('SELECT COUNT(*) FROM messages'),
            'Private messages' => (int) Database::scalar('SELECT COUNT(*) FROM private_messages'),
            'Active global bans' => (int) Database::scalar('SELECT COUNT(*) FROM bans WHERE channel_id IS NULL AND active = 1'),
            'Spam filters' => (int) Database::scalar('SELECT COUNT(*) FROM spamfilters WHERE enabled = 1'),
            'Audit events' => (int) Database::scalar('SELECT COUNT(*) FROM audit_log'),
        ];
        $recentAudit = Database::all(
            'SELECT a.*, u.username FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id
             ORDER BY a.id DESC LIMIT 15'
        );
        $banned = Database::all(
            "SELECT u.*, b.reason AS ban_reason, b.mask, b.expires_at, b.kind FROM users u
             LEFT JOIN bans b ON b.target_user_id = u.id AND b.active = 1 AND b.channel_id IS NULL
             WHERE u.banned = 1 OR b.kind IN ('kline','gline','zline','shun')
             GROUP BY u.id LIMIT 20"
        );
        render_view('admin/overview', [
            'admin' => $admin,
            'stats' => $stats,
            'recentAudit' => $recentAudit,
            'banned' => $banned,
        ]);
    }

    public static function users(): void
    {
        $admin = self::require();
        $term = trim((string) ($_GET['q'] ?? ''));
        $sql = 'SELECT u.*, (SELECT COUNT(*) FROM channel_members cm WHERE cm.user_id = u.id) AS channel_count
                FROM users u';
        $params = [];
        if ($term !== '') {
            $sql .= ' WHERE u.username LIKE ? COLLATE NOCASE OR u.email LIKE ? COLLATE NOCASE';
            $params = ["%$term%", "%$term%"];
        }
        $sql .= ' ORDER BY u.registered_at DESC LIMIT 200';
        $users = Database::all($sql, $params);
        $roles = Database::all('SELECT id, name, color FROM roles ORDER BY name COLLATE NOCASE');
        render_view('admin/users', ['admin' => $admin, 'users' => $users, 'term' => $term, 'roles' => $roles]);
    }

    public static function channels(): void
    {
        $admin = self::require();
        $term = trim((string) ($_GET['q'] ?? ''));
        $sql = 'SELECT c.*, u.username AS owner, (SELECT COUNT(*) FROM channel_members cm WHERE cm.channel_id = c.id) AS members
                FROM channels c LEFT JOIN users u ON u.id = c.owner_id';
        $params = [];
        if ($term !== '') {
            $sql .= ' WHERE c.name LIKE ? COLLATE NOCASE';
            $params = ["%$term%"];
        }
        $sql .= ' ORDER BY members DESC, c.name COLLATE NOCASE LIMIT 300';
        $channels = Database::all($sql, $params);
        render_view('admin/channels', ['admin' => $admin, 'channels' => $channels, 'term' => $term]);
    }

    public static function bans(): void
    {
        $admin = self::require();
        $global = Database::all(
            'SELECT b.*, s.username AS set_by_name FROM bans b LEFT JOIN users s ON s.id = b.set_by
             WHERE b.channel_id IS NULL ORDER BY b.set_at DESC LIMIT 200'
        );
        $channelBans = Database::all(
            'SELECT b.*, s.username AS set_by_name, c.name AS channel_name FROM bans b
             LEFT JOIN users s ON s.id = b.set_by LEFT JOIN channels c ON c.id = b.channel_id
             WHERE b.channel_id IS NOT NULL ORDER BY b.set_at DESC LIMIT 200'
        );
        render_view('admin/bans', ['admin' => $admin, 'global' => $global, 'channelBans' => $channelBans]);
    }

    public static function spamfilters(): void
    {
        $admin = self::require();
        $filters = Database::all('SELECT * FROM spamfilters ORDER BY id DESC');
        render_view('admin/spamfilters', ['admin' => $admin, 'filters' => $filters]);
    }

    public static function badwords(): void
    {
        $admin = self::require();
        $words = Database::all('SELECT * FROM badwords ORDER BY id DESC');
        render_view('admin/badwords', ['admin' => $admin, 'words' => $words]);
    }

    public static function roles(): void
    {
        $admin = self::require();
        $roles = Database::all('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS members FROM roles r ORDER BY r.name COLLATE NOCASE');
        render_view('admin/roles', ['admin' => $admin, 'roles' => $roles]);
    }

    public static function opers(): void
    {
        $admin = self::require();
        $opers = Database::all(
            'SELECT o.*, c.name AS operclass FROM opers o LEFT JOIN operclasses c ON c.id = o.operclass_id ORDER BY o.username COLLATE NOCASE'
        );
        $classes = Database::all('SELECT id, name FROM operclasses ORDER BY name COLLATE NOCASE');
        render_view('admin/opers', ['admin' => $admin, 'opers' => $opers, 'classes' => $classes]);
    }

    public static function operclasses(): void
    {
        $admin = self::require();
        $classes = Database::all('SELECT * FROM operclasses ORDER BY is_default DESC, name COLLATE NOCASE');
        render_view('admin/operclasses', ['admin' => $admin, 'classes' => $classes]);
    }

    public static function motd(): void
    {
        $admin = self::require();
        render_view('admin/motd', ['admin' => $admin, 'motd' => (string) config_get('motd', '')]);
    }

    public static function logs(): void
    {
        $admin = self::require();
        $channel = trim((string) ($_GET['channel'] ?? ''));
        $sql = "SELECT channel_name, substr(created_at,1,10) AS day, COUNT(*) AS entries
                FROM chat_logs WHERE channel_name IS NOT NULL";
        $params = [];
        if ($channel !== '') {
            $sql .= ' AND channel_name = ?';
            $params[] = $channel;
        }
        $sql .= ' GROUP BY channel_name, day ORDER BY day DESC, channel_name COLLATE NOCASE LIMIT 1000';
        $rows = Database::all($sql, $params);
        $channels = MessageService::loggedChannels();
        render_view('admin/logs', ['admin' => $admin, 'rows' => $rows, 'channel' => $channel, 'channels' => $channels]);
    }

    public static function logDay(): void
    {
        $admin = self::require();
        $channel = trim((string) ($_GET['channel'] ?? ''));
        $date = trim((string) ($_GET['date'] ?? ''));
        if ($channel === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            exit('Invalid parameters');
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo self::logDayText($channel, $date);
        exit;
    }

    public static function logDayExport(): void
    {
        $admin = self::require();
        $channel = trim((string) ($_GET['channel'] ?? ''));
        $date = trim((string) ($_GET['date'] ?? ''));
        if ($channel === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400);
            exit('Invalid parameters');
        }
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', str_replace('#', '', $channel)));
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $slug . '-' . $date . '.log"');
        echo self::logDayText($channel, $date);
        exit;
    }

    /** Format a full day of a channel's archive into the IRC-style log text. */
    private static function logDayText(string $channelName, string $date): string
    {
        $rows = Database::all(
            'SELECT * FROM chat_logs WHERE channel_name = ? AND substr(created_at,1,10) = ? ORDER BY id ASC',
            [$channelName, $date]
        );

        // Day topic = last topic change that day, else the channel's current topic.
        $topic = '(no topic)';
        foreach ($rows as $r) {
            if ($r['kind'] === 'topic' && preg_match('/set the topic to: (.+)$/i', (string) $r['content'], $m)) {
                $topic = trim($m[1]);
            }
        }
        if ($topic === '(no topic)') {
            $ch = ChannelService::find($channelName);
            if ($ch && $ch['topic'] !== '') {
                $topic = $ch['topic'];
            }
        }

        $start = date('g:i A', strtotime($date . ' 00:00:00'));
        $end = date('g:i A', strtotime($date . ' 23:59:00'));
        $lines = ['#' . $channelName . ' - ' . $date . ' ' . $start . ' - ' . $end . ' - ' . $topic];

        foreach ($rows as $r) {
            $time = date('g:i:s A', strtotime($r['created_at'] . ' UTC'));
            $user = (string) $r['username'] . ((int) ($r['guest'] ?? 0) === 1 ? ' (guest)' : '');
            $content = (string) $r['content'];
            switch ($r['kind']) {
                case 'message':
                    $lines[] = $time . ' - ' . $user . ' - ' . $content;
                    break;
                case 'action':
                    $lines[] = $time . ' - * ' . $user . ' ' . $content;
                    break;
                case 'topic':
                    $topicText = $content;
                    if (preg_match('/set the topic to: (.+)$/i', $content, $m)) {
                        $topicText = '"' . trim($m[1]) . '"';
                    }
                    $lines[] = $time . ' - -Topic Changed to ' . $topicText . ' by ' . $user;
                    break;
                case 'ban':
                    if (preg_match('/^(\S+) banned (.+)$/', $content, $m)) {
                        $lines[] = $time . ' - -' . $m[2] . ' banned by ' . $m[1];
                    } else {
                        $lines[] = $time . ' - -' . $content;
                    }
                    break;
                case 'pm':
                    $to = preg_replace('/^PM: /i', '', $channelName);
                    $lines[] = $time . ' - ' . $user . ' -> ' . $to . ' - ' . $content;
                    break;
                default: // join, part, quit, kick, mode, nick, system, notice
                    $lines[] = $time . ' - -' . $content;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    public static function settings(): void
    {
        $admin = self::require();
        $keys = ['site_name', 'registration_enabled', 'spamfilter_enabled', 'max_channels_per_user', 'presence_throttle', 'poll_interval', 'motd'];
        $settings = [];
        foreach ($keys as $k) {
            $settings[$k] = (string) config_get($k, '');
        }
        render_view('admin/settings', ['admin' => $admin, 'settings' => $settings]);
    }

    public static function action(): void
    {
        $admin = self::require();
        Csrf::verify();
        $action = (string) ($_POST['action'] ?? '');
        $ok = true;
        $message = 'Done.';
        switch ($action) {
            case 'user_ban':
                $id = (int) ($_POST['id'] ?? 0);
                Database::query('UPDATE users SET banned = 1, ban_reason = ? WHERE id = ?', [mb_substr((string) ($_POST['reason'] ?? ''), 0, 300), $id]);
                Database::query('DELETE FROM sessions WHERE user_id = ?', [$id]);
                log_audit('user_ban', 'user#' . $id);
                $message = 'User banned.';
                break;
            case 'user_unban':
                $id = (int) ($_POST['id'] ?? 0);
                Database::query('UPDATE users SET banned = 0, ban_reason = NULL WHERE id = ?', [$id]);
                log_audit('user_unban', 'user#' . $id);
                $message = 'User unbanned.';
                break;
            case 'user_admin':
                $id = (int) ($_POST['id'] ?? 0);
                Database::query('UPDATE users SET role = "admin" WHERE id = ?', [$id]);
                log_audit('user_admin', 'user#' . $id);
                $message = 'User promoted to admin.';
                break;
            case 'user_deadmin':
                $id = (int) ($_POST['id'] ?? 0);
                Database::query('UPDATE users SET role = "user" WHERE id = ? AND id != ?', [$id, $admin['id']]);
                log_audit('user_deadmin', 'user#' . $id);
                $message = 'Admin rights removed.';
                break;
            case 'user_staff':
                $id = (int) ($_POST['id'] ?? 0);
                Database::query('UPDATE users SET role = "staff" WHERE id = ?', [$id]);
                log_audit('user_staff', 'user#' . $id);
                $message = 'User promoted to staff.';
                break;
            case 'user_destaff':
                $id = (int) ($_POST['id'] ?? 0);
                Database::query('UPDATE users SET role = "user" WHERE id = ?', [$id]);
                log_audit('user_destaff', 'user#' . $id);
                $message = 'Staff role removed.';
                break;
            case 'user_reset':
                $id = (int) ($_POST['id'] ?? 0);
                $pw = bin2hex(random_bytes(6));
                Database::query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_ARGON2ID), $id]);
                Database::query('DELETE FROM sessions WHERE user_id = ?', [$id]);
                log_audit('user_reset', 'user#' . $id);
                $message = "Password reset to: $pw (user must use it to log in)";
                break;
            case 'channel_drop':
                $id = (int) ($_POST['id'] ?? 0);
                $name = Database::scalar('SELECT name FROM channels WHERE id = ?', [$id]);
                ChannelService::drop((string) $id);
                log_audit('channel_drop_admin', $name ?: '#' . $id);
                $message = 'Channel dropped.';
                break;
            case 'channel_topic':
                $id = (int) ($_POST['id'] ?? 0);
                ChannelService::update((string) $id, ['topic' => mb_substr((string) ($_POST['topic'] ?? ''), 0, 500)]);
                log_audit('channel_topic_admin', 'channel#' . $id);
                $message = 'Topic updated.';
                break;
            case 'channel_visibility':
                $id = (int) ($_POST['id'] ?? 0);
                $vis = (string) ($_POST['visibility'] ?? 'public');
                ChannelService::update((string) $id, ['visibility' => in_array($vis, ['public', 'private', 'secret', 'staff'], true) ? $vis : 'public']);
                log_audit('channel_visibility', 'channel#' . $id, $vis);
                $message = 'Visibility updated.';
                break;
            case 'channel_forbid':
                $id = (int) ($_POST['id'] ?? 0);
                ChannelService::update((string) $id, ['forbidden' => (int) ($_POST['forbid'] ?? 0)]);
                log_audit('channel_forbid', 'channel#' . $id);
                $message = 'Channel status updated.';
                break;
            case 'ban_add':
                $kind = (string) ($_POST['kind'] ?? 'kline');
                $mask = trim((string) ($_POST['mask'] ?? ''));
                $reason = trim((string) ($_POST['reason'] ?? ''));
                $dur = parse_duration((string) ($_POST['duration'] ?? ''));
                $err = BanService::addBan($kind, null, $mask, $reason, $dur, (int) $admin['id']);
                if ($err) {
                    $ok = false;
                    $message = $err;
                } else {
                    $message = "$kind added.";
                }
                break;
            case 'ban_del':
                $id = (int) ($_POST['id'] ?? 0);
                BanService::remove($id);
                log_audit('ban_remove_admin', 'ban#' . $id);
                $message = 'Ban removed.';
                break;
            case 'spamfilter_add':
                Database::query(
                    'INSERT INTO spamfilters (match_type, targets, action, reason, match) VALUES ("simple", "cpntu", "block", ?, ?)',
                    [trim((string) ($_POST['reason'] ?? '')), trim((string) ($_POST['match'] ?? ''))]
                );
                log_audit('spamfilter_add');
                $message = 'Spam filter added.';
                break;
            case 'spamfilter_del':
                Database::query('DELETE FROM spamfilters WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
                log_audit('spamfilter_del');
                $message = 'Spam filter removed.';
                break;
            case 'spamfilter_toggle':
                Database::query('UPDATE spamfilters SET enabled = 1 - enabled WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
                $message = 'Spam filter toggled.';
                break;
            case 'user_zline_ip':
                $id = (int) ($_POST['id'] ?? 0);
                $u = Database::row('SELECT * FROM users WHERE id = ?', [$id]);
                if ($u && !empty($u['last_ip'])) {
                    BanService::addBan('zline', null, (string) $u['last_ip'], trim((string) ($_POST['reason'] ?? 'Banned by admin (IP)')), null, (int) $admin['id']);
                    log_audit('zline_ip', $u['username'], $u['last_ip']);
                    $message = "IP {$u['last_ip']} banned (zline).";
                } else {
                    $ok = false;
                    $message = 'No IP recorded for that user.';
                }
                break;
            case 'badword_add':
                $word = strtolower(trim((string) ($_POST['word'] ?? '')));
                $action = ($_POST['action'] ?? 'censor') === 'block' ? 'block' : 'censor';
                if ($word === '') {
                    $ok = false;
                    $message = 'A bad word is required.';
                } else {
                    Database::query('INSERT INTO badwords (word, action) VALUES (?, ?)', [$word, $action]);
                    log_audit('badword_add', $word, $action);
                    $message = "Bad word '$word' added ($action).";
                }
                break;
            case 'badword_del':
                Database::query('DELETE FROM badwords WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
                log_audit('badword_del');
                $message = 'Bad word removed.';
                break;
            case 'badword_toggle':
                Database::query('UPDATE badwords SET enabled = 1 - enabled WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
                $message = 'Bad word toggled.';
                break;
            case 'role_save':
                $id = (int) ($_POST['id'] ?? 0);
                $name = trim((string) ($_POST['name'] ?? ''));
                $color = trim((string) ($_POST['color'] ?? '#5865f2'));
                $perms = array_map('strval', (array) ($_POST['perms'] ?? []));
                $allowedPerms = ['oper', 'manage_users', 'manage_channels', 'manage_bans', 'manage_badwords', 'manage_roles'];
                $perms = array_values(array_intersect($allowedPerms, $perms));
                if ($name === '') {
                    $ok = false;
                    $message = 'A role name is required.';
                } elseif ($id > 0) {
                    Database::query('UPDATE roles SET name = ?, color = ?, perms = ? WHERE id = ?', [$name, $color, json_encode($perms), $id]);
                    log_audit('role_update', $name);
                    $message = 'Role updated.';
                } else {
                    Database::query('INSERT INTO roles (name, color, perms) VALUES (?, ?, ?)', [$name, $color, json_encode($perms)]);
                    log_audit('role_add', $name);
                    $message = "Role '$name' created.";
                }
                break;
            case 'role_del':
                $id = (int) ($_POST['id'] ?? 0);
                Database::query('UPDATE users SET role_id = NULL WHERE role_id = ?', [$id]);
                Database::query('DELETE FROM roles WHERE id = ?', [$id]);
                log_audit('role_del', 'role#' . $id);
                $message = 'Role deleted.';
                break;
            case 'user_set_role':
                $id = (int) ($_POST['id'] ?? 0);
                $roleId = (int) ($_POST['role_id'] ?? 0);
                Database::query('UPDATE users SET role_id = ? WHERE id = ?', [$roleId > 0 ? $roleId : null, $id]);
                log_audit('user_set_role', 'user#' . $id, 'role#' . $roleId);
                $message = 'Role assigned.';
                break;
            case 'oper_add':
                $username = trim((string) ($_POST['username'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');
                $classId = (int) ($_POST['operclass_id'] ?? 0);
                if ($username === '' || strlen($password) < 8) {
                    $ok = false;
                    $message = 'A username and an 8+ char password are required.';
                } elseif (Database::scalar('SELECT id FROM opers WHERE username = ? COLLATE NOCASE', [$username])) {
                    $ok = false;
                    $message = 'That o:line already exists.';
                } elseif (!Database::scalar('SELECT id FROM operclasses WHERE id = ?', [$classId])) {
                    $ok = false;
                    $message = 'Invalid operator class.';
                } else {
                    Database::query('INSERT INTO opers (username, password_hash, operclass_id) VALUES (?, ?, ?)', [$username, password_hash($password, PASSWORD_ARGON2ID), $classId]);
                    log_audit('oper_add', $username);
                    $message = "O:line added for $username.";
                }
                break;
            case 'oper_del':
                $id = (int) ($_POST['id'] ?? 0);
                Database::query('DELETE FROM opers WHERE id = ?', [$id]);
                log_audit('oper_del', 'oper#' . $id);
                $message = 'O:line removed.';
                break;
            case 'oper_toggle':
                $id = (int) ($_POST['id'] ?? 0);
                Database::query('UPDATE opers SET enabled = 1 - enabled WHERE id = ?', [$id]);
                $message = 'O:line toggled.';
                break;
            case 'operclass_save':
                $id = (int) ($_POST['id'] ?? 0);
                $name = trim((string) ($_POST['name'] ?? ''));
                $color = trim((string) ($_POST['color'] ?? '#ffd700'));
                $perms = array_values(array_intersect(
                    ['oper', 'manage_users', 'manage_channels', 'manage_bans', 'manage_badwords', 'manage_roles', 'manage_opers', 'rehash'],
                    array_map('strval', (array) ($_POST['perms'] ?? []))
                ));
                if ($name === '') {
                    $ok = false;
                    $message = 'A class name is required.';
                } elseif ($id > 0) {
                    Database::query('UPDATE operclasses SET name = ?, color = ?, perms = ? WHERE id = ?', [$name, $color, json_encode($perms), $id]);
                    log_audit('operclass_update', $name);
                    $message = 'Operator class updated.';
                } else {
                    Database::query('INSERT INTO operclasses (name, color, perms) VALUES (?, ?, ?)', [$name, $color, json_encode($perms)]);
                    log_audit('operclass_add', $name);
                    $message = "Operator class '$name' created.";
                }
                break;
            case 'operclass_del':
                $id = (int) ($_POST['id'] ?? 0);
                $class = Database::row('SELECT * FROM operclasses WHERE id = ?', [$id]);
                if (!$class) {
                    $ok = false;
                    $message = 'Class not found.';
                } elseif ((int) $class['is_default'] === 1) {
                    $ok = false;
                    $message = 'Default operator classes cannot be deleted.';
                } else {
                    Database::query('DELETE FROM opers WHERE operclass_id = ?', [$id]);
                    Database::query('DELETE FROM operclasses WHERE id = ?', [$id]);
                    log_audit('operclass_del', $class['name']);
                    $message = 'Operator class deleted.';
                }
                break;
            case 'motd_save':
                config_set('motd', (string) ($_POST['motd'] ?? ''));
                log_audit('motd_save');
                $message = 'MOTD saved.';
                break;
            case 'settings_save':
                config_set('site_name', trim((string) ($_POST['site_name'] ?? 'LVChat')));
                config_set('registration_enabled', ($_POST['registration_enabled'] ?? '0') === '1' ? '1' : '0');
                config_set('spamfilter_enabled', ($_POST['spamfilter_enabled'] ?? '0') === '1' ? '1' : '0');
                config_set('max_channels_per_user', max(1, (int) ($_POST['max_channels_per_user'] ?? 100)));
                config_set('presence_throttle', max(5, (int) ($_POST['presence_throttle'] ?? 30)));
                config_set('poll_interval', max(1, (int) ($_POST['poll_interval'] ?? 2)));
                log_audit('settings_save');
                $message = 'Settings saved.';
                break;
            default:
                $ok = false;
                $message = 'Unknown action.';
        }
        if (!$ok) {
            flash($message);
        } else {
            flash($message);
        }
        redirect((string) ($_POST['back'] ?? '/admin'));
    }
}
