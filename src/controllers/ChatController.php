<?php

declare(strict_types=1);

final class ChatController
{
    public static function version(): void
    {
        json_out([
            'version' => LVC_VERSION,
            'site' => config_get('site_name', 'LVChat'),
        ]);
    }

    public static function app(): void
    {
        $user = Auth::require();
        $dm = isset($_GET['dm']) ? Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$_GET['dm']]) : null;
        $channel = null;
        if (isset($_GET['channel']) && $_GET['channel'] !== '') {
            $channel = ChannelService::findBySlug((string) $_GET['channel']);
            if (!$channel) {
                redirect('/browse');
            }
            if (!AccessService::member($channel['id'], (int) $user['id'])) {
                redirect('/c/' . rawurlencode($channel['slug']));
            }
        }
        // Messaging yourself is allowed (an IRC hallmark) — no self-DM guard.

        // Password-protected join: /app?join=<slug> opens the chat with a modal
        // prompting for the channel key (used when a keyed channel is opened).
        $joinModal = null;
        if (isset($_GET['join']) && $_GET['join'] !== '') {
            $jc = ChannelService::findBySlug((string) $_GET['join']);
            if ($jc && !AccessService::member($jc['id'], (int) $user['id'])) {
                $jst = ChannelService::joinStatus($jc, $user);
                if (($jst['reason'] ?? '') === 'need_key') {
                    $joinModal = $jc;
                }
            }
        }

        $channels = ChannelService::joinedChannelNames((int) $user['id']);
        $dmPartners = MessageService::recentDmPartners((int) $user['id']);
        $unreadDms = MessageService::unreadDmCounts((int) $user['id']);
        $notifications = Database::all(
            'SELECT n.*, s.username AS sender, c.name AS channel_name FROM notifications n
             LEFT JOIN users s ON s.id = n.sender_id
             LEFT JOIN channels c ON c.id = n.channel_id
             WHERE n.user_id = ? AND n.read = 0 ORDER BY n.id DESC LIMIT 50',
            [$user['id']]
        );
        $onlineUsers = Database::all(
            'SELECT username, role FROM users WHERE last_seen >= datetime("now", "-30 seconds") AND away IS NULL ORDER BY username'
        );
        $motd = (string) config_get('motd', '');

        $messages = [];
        $members = [];
        if ($channel) {
            $messages = MessageService::history((int) $channel['id']);
            $members = ChannelService::members((string) $channel['id']);
            foreach ($members as &$m) {
                $m['is_online'] = Auth::isOnline($m) ? 1 : 0;
            }
            unset($m);
        } elseif ($dm) {
            $messages = MessageService::forDm((int) $user['id'], (int) $dm['id']);
            MessageService::markDmRead((int) $user['id'], (int) $dm['id']);
        }

        render_view('chat/app', [
            'user' => $user,
            'channel' => $channel,
            'dm' => $dm,
            'channels' => $channels,
            'dmPartners' => $dmPartners,
            'unreadDms' => $unreadDms,
            'notifications' => $notifications,
            'onlineUsers' => $onlineUsers,
            'motd' => $motd,
            'messages' => $messages,
            'members' => $members,
            'commands' => CommandRegistry::names(),
            'joinModal' => $joinModal,
        ], null);
    }

    private static function requireCsrf(): void
    {
        $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
        if (!is_string($sent) || !hash_equals(Csrf::token(), $sent)) {
            json_out(['error' => 'CSRF token mismatch.'], 419);
        }
    }

    private static function requireUser(): array
    {
        $u = Auth::user();
        if (!$u) {
            json_out(['error' => 'Not authenticated.'], 401);
        }
        return $u;
    }

    /**
     * End a request. AJAX requests (the JS client) get JSON; native form posts
     * (e.g. if JavaScript ever fails to load) get a redirect back to the channel
     * so a message is still delivered without navigating the user away.
     */
    private static function finish(array $data, string $back, int $status = 200): never
    {
        if (($_POST['ajax'] ?? '') === '1') {
            json_out($data, $status);
        }
        if (!empty($data['error'])) {
            flash((string) $data['error']);
        }
        redirect($back);
    }

    private static function rateLimited(int $userId): bool
    {
        $count = (int) Database::scalar(
            'SELECT COUNT(*) FROM messages WHERE sender_id = ? AND created_at >= datetime("now", "-5 seconds")',
            [$userId]
        );
        $pmCount = (int) Database::scalar(
            'SELECT COUNT(*) FROM private_messages WHERE sender_id = ? AND created_at >= datetime("now", "-5 seconds")',
            [$userId]
        );
        return ($count + $pmCount) > 12;
    }

    public static function send(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $content = trim((string) ($_POST['content'] ?? ''));
        if ($content === '') {
            self::finish(['error' => 'Message is empty.'], '/app', 400);
        }
        $content = mb_substr($content, 0, 2000);

        // Native (no-JS) submissions of slash commands are routed here too.
        if (($_POST['ajax'] ?? '') !== '1' && $content[0] === '/') {
            $channel = null;
            if (!empty($_POST['channel'])) {
                $channel = ChannelService::findBySlug((string) $_POST['channel']);
            }
            $result = CommandParser::run($content, $user, $channel);
            CommandParser::applyEvents($result);
            redirect($result['redirect'] ?? ($channel ? '/app?channel=' . rawurlencode($channel['slug']) : '/app'));
        }

        if (self::rateLimited((int) $user['id'])) {
            self::finish(['error' => 'You are sending messages too quickly. Slow down.'], '/app', 429);
        }

        if (isset($_POST['recipient'])) {
            $t = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$_POST['recipient']]);
            if (!$t) {
                self::finish(['error' => 'No such user.'], '/app', 404);
            }
            $ignoring = Database::row('SELECT 1 FROM ignores WHERE user_id = ? AND ignored_user_id = ?', [$user['id'], $t['id']]);
            if ($ignoring) {
                self::finish(['error' => 'You are ignoring that user.'], '/app?dm=' . rawurlencode($t['username']), 400);
            }
            $blocked = BanService::sendBlocked($user, $content, 'p');
            if ($blocked) {
                self::finish(['error' => $blocked], '/app?dm=' . rawurlencode($t['username']), 403);
            }
            $backPm = '/app?dm=' . rawurlencode($t['username']);
            // Global word filter applies to PMs (no channel mode exists for them).
            $censor = CensorService::check($content, true);
            if ($censor) {
                if ($censor['action'] === 'censor') {
                    $content = $censor['censored'];
                } else {
                    $notice = 'Chanserv removed message from ' . $user['username'] . ' due to prohibited words';
                    self::finish(['ok' => true, 'blocked' => true, 'notice' => $notice], $backPm);
                }
            }
            Database::query(
                'INSERT INTO private_messages (sender_id, recipient_id, content) VALUES (?, ?, ?)',
                [$user['id'], $t['id'], $content]
            );
            $pmId = (int) Database::lastId();
            if ((int) $t['id'] !== (int) $user['id']) {
                Database::query(
                    'INSERT INTO notifications (user_id, kind, sender_id, message_id) VALUES (?, "dm", ?, ?)',
                    [$t['id'], $user['id'], $pmId]
                );
            }
            MessageService::logPm((int) $user['id'], $user['username'], $t['username'], $content);
            $row = Database::row('SELECT * FROM private_messages WHERE id = ?', [$pmId]);
            self::finish([
                'ok' => true,
                'message' => [
                    'id' => (int) $row['id'],
                    'kind' => 'message',
                    'content' => $row['content'],
                    'created_at' => $row['created_at'],
                    'username' => $user['username'],
                    'sender_id' => (int) $user['id'],
                    'is_pm' => true,
                ],
            ], $backPm);
        }

        $channel = ChannelService::findBySlug((string) ($_POST['channel'] ?? ''));
        if (!$channel) {
            self::finish(['error' => 'Channel not found.'], '/app', 404);
        }
        $back = '/app?channel=' . rawurlencode($channel['slug']);
        $member = AccessService::member($channel['id'], (int) $user['id']);
        if (!$member) {
            self::finish(['error' => 'You are not a member of this channel.'], $back, 403);
        }
        $blocked = BanService::canPost($channel, $user, $member);
        if ($blocked) {
            self::finish(['error' => $blocked], $back, 403);
        }
        $blocked = BanService::sendBlocked($user, $content, 'c');
        if ($blocked) {
            self::finish(['error' => $blocked], $back, 403);
        }
        // Global word filter applies only when the channel has +C set.
        $censor = CensorService::check($content, CensorService::isChannelFiltered($channel));
        if ($censor) {
            if ($censor['action'] === 'censor') {
                $content = $censor['censored'];
            } else {
                $msg = MessageService::send((int) $channel['id'], (int) $user['id'], $content, 'message');
                Database::query('UPDATE messages SET deleted = 1 WHERE id = ?', [$msg['id']]);
                $notice = 'Chanserv removed message from ' . $user['username'] . ' due to prohibited words';
                MessageService::system((int) $channel['id'], 'system', $notice);
                $msg['channel'] = $channel['slug'];
                self::finish(['ok' => true, 'message' => $msg, 'blocked' => true, 'notice' => $notice], $back);
            }
        }
        $msg = MessageService::send((int) $channel['id'], (int) $user['id'], $content, 'message');
        $msg['channel'] = $channel['slug'];
        self::finish(['ok' => true, 'message' => $msg], $back);
    }

    public static function command(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $text = trim((string) ($_POST['text'] ?? ''));
        if ($text === '' || $text[0] !== '/') {
            json_out(['error' => 'Not a command.'], 400);
        }
        $channel = null;
        if (isset($_POST['channel']) && $_POST['channel'] !== '') {
            $channel = ChannelService::findBySlug((string) $_POST['channel']);
        }
        $result = CommandParser::run($text, $user, $channel);
        CommandParser::applyEvents($result);
        if (($_POST['ajax'] ?? '') === '1') {
            json_out(array_merge(['ok' => true], $result));
        }
        redirect($result['redirect'] ?? ($channel ? '/app?channel=' . rawurlencode($channel['slug']) : '/app'));
    }

    public static function poll(): void
    {
        $user = self::requireUser();
        $since = max(0, (int) ($_GET['since'] ?? 0));
        $out = ['ok' => true, 'messages' => [], 'presence' => [], 'notify_count' => 0];

        $notifyCount = (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read = 0', [$user['id']]);
        $out['notify_count'] = $notifyCount;

        if (isset($_GET['dm'])) {
            $t = Database::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [$_GET['dm']]);
            if ($t) {
                $out['messages'] = MessageService::forDm((int) $user['id'], (int) $t['id'], $since);
                MessageService::markDmRead((int) $user['id'], (int) $t['id']);
                $out['dm'] = $t['username'];
                $out['presence'][] = [
                    'username' => $t['username'],
                    'is_online' => Auth::isOnline($t) ? 1 : 0,
                    'away' => $t['away'] ?: null,
                    'level' => 'normal',
                    'role' => $t['role'],
                ];
            }
            json_out($out);
        }

        if (isset($_GET['channel']) && $_GET['channel'] !== '') {
            $channel = ChannelService::findBySlug((string) $_GET['channel']);
            if (!$channel) {
                json_out(['error' => 'Channel not found.'], 404);
            }
            $member = AccessService::member($channel['id'], (int) $user['id']);
            if (!$member) {
                json_out(['ok' => true, 'redirect' => '/app', 'reason' => 'You are no longer in this channel.']);
            }
            $out['messages'] = MessageService::forChannel((int) $channel['id'], $since);
            $out['channel'] = $channel['slug'];
            $out['topic'] = $channel['topic'];
            foreach (ChannelService::members((string) $channel['id']) as $m) {
                $out['presence'][] = [
                    'username' => $m['username'],
                    'is_online' => Auth::isOnline($m) ? 1 : 0,
                    'away' => $m['away'] ?: null,
                    'level' => $m['level'],
                    'role' => $m['role'],
                    'bot' => (int) $m['bot'],
                    'role_color' => $m['role_color'] ?? null,
                ];
            }
            // Recent notifications for this channel to surface mentions/invites in a toast.
            $out['mentions'] = Database::all(
                'SELECT n.kind, s.username AS sender, n.message_id FROM notifications n
                 LEFT JOIN users s ON s.id = n.sender_id
                 WHERE n.user_id = ? AND n.channel_id = ? AND n.read = 0',
                [$user['id'], $channel['id']]
            );
            json_out($out);
        }

        json_out($out);
    }

    public static function notifications(): void
    {
        $user = self::requireUser();
        $rows = Database::all(
            'SELECT n.*, s.username AS sender, c.name AS channel_name FROM notifications n
             LEFT JOIN users s ON s.id = n.sender_id
             LEFT JOIN channels c ON c.id = n.channel_id
             WHERE n.user_id = ? AND n.read = 0 ORDER BY n.id DESC LIMIT 50',
            [$user['id']]
        );
        foreach ($rows as &$r) {
            $r['created_at'] = relative_time($r['created_at']);
        }
        json_out(['ok' => true, 'notifications' => $rows]);
    }

    public static function readNotifications(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        Database::query('UPDATE notifications SET read = 1 WHERE user_id = ?', [$user['id']]);
        json_out(['ok' => true]);
    }

    public static function deleteMessage(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $id = (int) ($_POST['id'] ?? 0);
        $r = MessageService::delete($id, $user);
        if ($r !== true) {
            json_out(['error' => $r], 403);
        }
        log_audit('message_delete', 'msg#' . $id);
        json_out(['ok' => true]);
    }

    public static function editMessage(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $id = (int) ($_POST['id'] ?? 0);
        $content = mb_substr(trim((string) ($_POST['content'] ?? '')), 0, 2000);
        $r = MessageService::edit($id, $content, $user);
        if ($r !== true) {
            json_out(['error' => $r], 403);
        }
        json_out(['ok' => true, 'content' => $content]);
    }
}
