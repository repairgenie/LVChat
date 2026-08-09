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
        $dm = isset($_GET['dm']) ? Auth::findActor((string) $_GET['dm']) : null;
        $channel = null;
        if (isset($_GET['channel']) && $_GET['channel'] !== '') {
            $channel = ChannelService::findBySlug((string) $_GET['channel']);
            if (!$channel) {
                redirect('/browse');
            }
            if (!AccessService::member($channel['id'], $user)) {
                redirect('/c/' . rawurlencode($channel['slug']));
            }
        }
        if (!$channel && !$dm && !isset($_GET['channel'])) {
            $channel = ChannelService::findBySlug('general');
            if ($channel && !AccessService::member($channel['id'], $user)) {
                ChannelService::join($channel, $user);
            }
        }
        // Messaging yourself is allowed (an IRC hallmark) — no self-DM guard.

        // Password-protected join: /app?join=<slug> opens the chat with a modal
        // prompting for the channel key (used when a keyed channel is opened).
        $joinModal = null;
        if (isset($_GET['join']) && $_GET['join'] !== '') {
            $jc = ChannelService::findBySlug((string) $_GET['join']);
            if ($jc && !AccessService::member($jc['id'], $user)) {
                $jst = ChannelService::joinStatus($jc, $user);
                if (($jst['reason'] ?? '') === 'need_key') {
                    $joinModal = $jc;
                }
            }
        }

        $channels = ChannelService::joinedChannelNames($user);
        $ownedIds = array_column(ChannelService::ownedChannels($user), 'id');
        $myChannels = array_values(array_filter($channels, fn ($c) => in_array($c['id'], $ownedIds, true)));
        $otherChannels = array_values(array_filter($channels, fn ($c) => !in_array($c['id'], $ownedIds, true)));
        $dmPartners = MessageService::recentDmPartners($user);
        $unreadDms = MessageService::unreadDmCounts($user);
        $notifications = Database::all(
            'SELECT n.*, COALESCE(s.username, gs.nick) AS sender, c.name AS channel_name FROM notifications n
             LEFT JOIN users s ON s.id = n.sender_id
             LEFT JOIN guests gs ON gs.id = n.sender_guest_id
             LEFT JOIN channels c ON c.id = n.channel_id
             WHERE (n.user_id = ? OR n.guest_user_id = ?) AND n.read = 0 ORDER BY n.id DESC LIMIT 50',
            [$user['id'], $user['id']]
        );
        $onlineUsers = Database::all(
            "SELECT id, username, role, guest, away, status_mode FROM users WHERE last_seen >= datetime('now', '-30 seconds') AND status_mode != 'invisible'
             UNION ALL
             SELECT id, nick, 'user', 1, NULL, 'online' FROM guests WHERE last_seen >= datetime('now', '-30 seconds')
             ORDER BY username"
        );
        // Name -> slug for every channel, so #channel references in messages can
        // be rendered as clickable links by the client-side renderer.
        $channelLinks = [];
        foreach (Database::all('SELECT name, slug FROM channels') as $c) {
            $channelLinks[(string) $c['name']] = (string) $c['slug'];
        }
        // Registered (non-guest) users for the @mention autocomplete pool,
        // ordered so the most recently active online users come first.
        $mentionUsers = Database::all(
            "SELECT id, username,
                    CASE WHEN last_seen >= datetime('now', '-30 seconds') AND away IS NULL THEN 1 ELSE 0 END AS online
             FROM users WHERE guest = 0 AND status = 'active' AND id != ?
             ORDER BY online DESC, last_seen DESC, username COLLATE NOCASE LIMIT 2000",
            [$user['id']]
        );
        $motd = (string) config_get('motd', '');

        $messages = [];
        $members = [];
        $notifyMode = 'all';
        if ($channel) {
            $messages = MessageService::hydrateReactions(MessageService::history((int) $channel['id']), $user);
            $members = ChannelService::members((string) $channel['id']);
            foreach ($members as &$m) {
                $m = array_merge($m, Auth::statusInfo($m));
            }
            unset($m);
            // Viewing a channel marks its unread badge read.
            ChannelService::markRead((int) $channel['id'], $user);
            $notifyMode = ChannelService::notifyMode((int) $channel['id'], $user);
        } elseif ($dm) {
            $messages = MessageService::forDm($user, $dm);
            MessageService::markDmRead($user, $dm);
        }
        // Background-audio watermark: highest channel message id rendered on this
        // page, so the client only plays sounds for messages newer than it.
        $bgLast = 0;
        foreach ($messages as $m) {
            if ((int) ($m['channel_id'] ?? 0) > 0 && (int) $m['id'] > $bgLast) {
                $bgLast = (int) $m['id'];
            }
        }

        render_view('chat/app', [
            'user' => $user,
            'channel' => $channel,
            'dm' => $dm,
            'channels' => $channels,
            'myChannels' => $myChannels,
            'otherChannels' => $otherChannels,
            'dmPartners' => $dmPartners,
            'unreadDms' => $unreadDms,
            'notifications' => $notifications,
            'onlineUsers' => $onlineUsers,
            'channelLinks' => $channelLinks,
            'mentionUsers' => $mentionUsers,
            'motd' => $motd,
            'messages' => $messages,
            'members' => $members,
            'commands' => CommandRegistry::names(),
            'joinModal' => $joinModal,
            'notifyMode' => $notifyMode,
            'sounds' => SoundService::soundsForClient($user),
            'bgLast' => $bgLast,
            'pushPrefs' => PushService::prefs($user),
            'friends' => (int) ($user['guest'] ?? 0) !== 1 ? FriendService::getFriendsWithStatus((int) $user['id']) : [],
            'friendRequests' => (int) ($user['guest'] ?? 0) !== 1 ? FriendService::getPendingIncoming((int) $user['id']) : [],
            'channelInvites' => (int) ($user['guest'] ?? 0) !== 1 ? ChannelService::pendingInvites((int) $user['id']) : [],
            // WebSocket realtime: a one-time handshake ticket + the gateway URL
            // are emitted so the client can open its socket on load.
            'wsTicket' => Realtime::enabled() ? Realtime::mintTicket($user) : '',
            'wsUrl' => Realtime::enabled() ? Realtime::clientUrl() : '',
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

    private static function rateLimited(array $user): bool
    {
        $col = MessageService::isGuest($user) ? 'sender_guest_id' : 'sender_id';
        $count = (int) Database::scalar(
            "SELECT COUNT(*) FROM messages WHERE $col = ? AND created_at >= datetime('now', '-5 seconds')",
            [$user['id']]
        );
        $pmCount = (int) Database::scalar(
            "SELECT COUNT(*) FROM private_messages WHERE $col = ? AND created_at >= datetime('now', '-5 seconds')",
            [$user['id']]
        );
        return ($count + $pmCount) > 12;
    }

    public static function send(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $restriction = ModerationService::restriction($user);
        if ($restriction) {
            self::finish(['error' => $restriction], '/app', 403);
        }
        $content = trim((string) ($_POST['content'] ?? ''));
        // GIF messages: the picker posts gif_url (+ optional gif_title) instead
        // of free text. The media URL is validated against known Giphy CDN hosts,
        // and the title is stored beneath it so chat search finds it by caption.
        $gifKind = false;
        $gifUrl = trim((string) ($_POST['gif_url'] ?? ''));
        if ($gifUrl !== '') {
            if (!GifService::validMediaUrl($gifUrl)) {
                self::finish(['error' => 'Invalid GIF URL.'], '/app', 400);
            }
            $gifTitle = mb_substr(trim((string) ($_POST['gif_title'] ?? '')), 0, 300);
            $content = $gifUrl . ($gifTitle !== '' ? "\n" . $gifTitle : '');
            $gifKind = true;
        }
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

        if (self::rateLimited($user)) {
            self::finish(['error' => 'You are sending messages too quickly. Slow down.'], '/app', 429);
        }

        if (isset($_POST['recipient'])) {
            $t = Auth::findActor((string) $_POST['recipient']);
            if (!$t) {
                self::finish(['error' => 'No such user.'], '/app', 404);
            }
            if ((int) ($user['guest'] ?? 0) !== 1 && (int) ($t['guest'] ?? 0) !== 1 && FriendService::isBlockedEither((int) $user['id'], (int) $t['id'])) {
                self::finish(['error' => 'You cannot message this user.'], '/app?dm=' . rawurlencode($t['username']), 400);
            }
            $blocked = BanService::sendBlocked($user, $content, 'p');
            if ($blocked) {
                self::finish(['error' => $blocked], '/app?dm=' . rawurlencode($t['username']), 403);
            }
            $backPm = '/app?dm=' . rawurlencode($t['username']);
            // Global word filter applies to PMs (no channel mode exists for them).
            $censor = CensorService::check($content, true);
            if ($censor) {
                ModerationService::record($user, 'badword', $censor['action'], $censor['word'], $content, 'p');
                if ($censor['action'] === 'censor') {
                    $content = $censor['censored'];
                } else {
                    $notice = 'Chanserv removed message from ' . $user['username'] . ' due to prohibited words';
                    self::finish(['ok' => true, 'blocked' => true, 'notice' => $notice], $backPm);
                }
            }
            $pmId = MessageService::insertPm($user, $t, $content, $gifKind ? 'gif' : 'message');
            MessageService::notifyDm($t, $user, $pmId);
            MessageService::logPm((int) $user['id'], $user['username'], $t['username'], $content, MessageService::isGuest($user) ? 1 : 0);
            $row = Database::row('SELECT * FROM private_messages WHERE id = ?', [$pmId]);
            $pmMessage = [
                'id' => (int) $row['id'],
                'kind' => $row['kind'] ?? 'message',
                'content' => $row['content'],
                'created_at' => $row['created_at'],
                'username' => $user['username'],
                'sender_id' => (int) $user['id'],
                'role' => $user['role'],
                'guest' => MessageService::isGuest($user) ? 1 : 0,
                'level' => 'normal',
                'is_pm' => true,
            ];
            // Push the PM to both participants' open tabs + refresh the bell.
            Realtime::dm($user, $t, $pmMessage);
            Realtime::bell($t);
            self::finish([
                'ok' => true,
                'message' => $pmMessage,
            ], $backPm);
        }

        $channel = ChannelService::findBySlug((string) ($_POST['channel'] ?? ''));
        if (!$channel) {
            self::finish(['error' => 'Channel not found.'], '/app', 404);
        }
        $back = '/app?channel=' . rawurlencode($channel['slug']);
        $member = AccessService::member($channel['id'], $user);
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
        // Reply-to: validate the parent message exists in the same channel.
        $replyTo = null;
        if (isset($_POST['reply_to']) && $_POST['reply_to'] !== '') {
            $rtId = (int) $_POST['reply_to'];
            $parent = Database::row('SELECT id FROM messages WHERE id = ? AND channel_id = ? AND deleted = 0', [$rtId, (int) $channel['id']]);
            if ($parent) {
                $replyTo = $rtId;
            }
        }
        // Global word filter applies only when the channel has +C set.
            $censor = CensorService::check($content, CensorService::isChannelFiltered($channel));
            if ($censor) {
                ModerationService::record($user, 'badword', $censor['action'], $censor['word'], $content, 'c', (int) $channel['id']);
                if ($censor['action'] === 'censor') {
                    $content = $censor['censored'];
                } else {
                    $msg = MessageService::send((int) $channel['id'], $user, $content, $gifKind ? 'gif' : 'message', $replyTo, true);
                    Database::query('UPDATE messages SET deleted = 1 WHERE id = ?', [$msg['id']]);
                    $notice = 'Chanserv removed message from ' . $user['username'] . ' due to prohibited words';
                    $sysId = MessageService::system((int) $channel['id'], 'system', $notice);
                    Realtime::message($channel['slug'], ['id' => $sysId, 'kind' => 'system', 'content' => $notice, 'channel' => $channel['slug'], 'sender_id' => null, 'username' => null, 'guest' => 0]);
                    $msg['channel'] = $channel['slug'];
                    self::finish(['ok' => true, 'message' => $msg, 'blocked' => true, 'notice' => $notice], $back);
                }
            }
        $msg = MessageService::send((int) $channel['id'], $user, $content, $gifKind ? 'gif' : 'message', $replyTo);
        $msg['channel'] = $channel['slug'];
        // Fan the message out to everyone viewing this channel in realtime.
        Realtime::message($channel['slug'], $msg);
        self::finish(['ok' => true, 'message' => $msg], $back);
    }

    /**
     * GET /api/gifs — proxy for Giphy search/trending so the API key never
     * reaches the browser. `q` empty = trending. Returns normalized items plus
     * the next pagination offset.
     */
    public static function gifSearch(): void
    {
        $user = self::requireUser();
        $restriction = ModerationService::restriction($user);
        if ($restriction) {
            json_out(['error' => $restriction], 403);
        }
        if (!GifService::enabled()) {
            json_out(['error' => 'GIF search is disabled on this server.'], 403);
        }
        if (!GifService::configured()) {
            json_out(['ok' => false, 'error' => 'GIF search is not configured by the admin yet. Add a Giphy API key in Admin → Settings.', 'gifs' => [], 'next' => '']);
        }
        $col = MessageService::isGuest($user) ? 'sender_guest_id' : 'sender_id';
        $recent = (int) Database::scalar(
            "SELECT COUNT(*) FROM messages WHERE $col = ? AND created_at >= datetime('now', '-10 seconds')",
            [$user['id']]
        );
        if ($recent > 30) {
            json_out(['error' => 'Too many requests. Slow down.'], 429);
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        $limit = max(1, min(50, (int) ($_GET['limit'] ?? 24)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $gifs = $q !== '' ? GifService::search($q, $limit, $offset) : GifService::trending($limit, $offset);
        json_out([
            'ok' => true,
            'gifs' => $gifs,
            'next' => ($gifs && count($gifs) === $limit) ? (string) ($offset + $limit) : '',
        ]);
    }

    /** POST /api/upload — upload an image and post it as an image message
     *  (channel, or DM when `dm` is given). */
    public static function upload(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $restriction = ModerationService::restriction($user);
        if ($restriction) {
            json_out(['error' => $restriction], 403);
        }
        if (config_get('uploads_enabled', '1') !== '1') {
            json_out(['error' => 'Image uploads are disabled on this server.'], 403);
        }
        if (self::rateLimited($user)) {
            json_out(['error' => 'You are sending messages too quickly. Slow down.'], 429);
        }
        if (!isset($_FILES['file']) || !UploadService::isImageUpload($_FILES['file'])) {
            json_out(['error' => 'Choose an image file first.'], 400);
        }
        $stored = UploadService::store($_FILES['file'], 'upload');
        if (!$stored['ok']) {
            json_out(['error' => $stored['error']], 400);
        }
        // Downscale to a max 1600px image (keeps large photos manageable).
        $abs = UploadService::dir('upload') . '/' . basename($stored['url']);
        $scaled = UploadService::downscale($abs, (string) $stored['ext'], 1600);
        $url = $scaled === false
            ? $stored['url']
            : str_replace('\\', '/', substr($scaled, strlen(ROOT . '/public')));
        $caption = mb_substr(trim((string) ($_POST['caption'] ?? '')), 0, 300);
        $content = $url . ($caption !== '' ? "\n" . $caption : '');

        // Private-message upload: send an image PM, same rules as /msg.
        $dm = trim((string) ($_POST['dm'] ?? ''));
        if ($dm !== '') {
            $t = Auth::findActor($dm);
            if (!$t) {
                json_out(['error' => 'No such user.'], 404);
            }
            $blocked = BanService::sendBlocked($user, $content, 'p');
            if ($blocked) {
                json_out(['error' => $blocked], 403);
            }
            $censor = CensorService::check($content, true);
            if ($censor) {
                ModerationService::record($user, 'badword', $censor['action'], $censor['word'], $content, 'p');
                if ($censor['action'] === 'censor') {
                    $content = $censor['censored'];
                } else {
                    json_out(['error' => 'Message blocked by the word filter.'], 403);
                }
            }
            $pmId = MessageService::insertPm($user, $t, $content, 'image');
            MessageService::notifyDm($t, $user, $pmId);
            MessageService::logPm((int) $user['id'], $user['username'], $t['username'], $content, MessageService::isGuest($user) ? 1 : 0);
            $row = Database::row('SELECT * FROM private_messages WHERE id = ?', [$pmId]);
            $pmMessage = [
                'id' => (int) $row['id'],
                'kind' => $row['kind'] ?? 'image',
                'content' => $row['content'],
                'created_at' => $row['created_at'],
                'username' => $user['username'],
                'sender_id' => (int) $user['id'],
                'role' => $user['role'],
                'guest' => MessageService::isGuest($user) ? 1 : 0,
                'level' => 'normal',
                'is_pm' => true,
            ];
            Realtime::dm($user, $t, $pmMessage);
            Realtime::bell($t);
            json_out(['ok' => true, 'message' => $pmMessage]);
        }

        $channel = ChannelService::findBySlug((string) ($_POST['channel'] ?? ''));
        if (!$channel) {
            json_out(['error' => 'Channel not found.'], 404);
        }
        $member = AccessService::member($channel['id'], $user);
        if (!$member) {
            json_out(['error' => 'You are not a member of this channel.'], 403);
        }
        $blocked = BanService::canPost($channel, $user, $member);
        if ($blocked) {
            json_out(['error' => $blocked], 403);
        }
        // Image uploads run the same spamfilter + word filter as text messages
        // (the caption is visible text), recording any hit on the queue.
        $blocked = BanService::sendBlocked($user, $content, 'c');
        if ($blocked) {
            json_out(['error' => $blocked], 403);
        }
        $censor = CensorService::check($content, CensorService::isChannelFiltered($channel));
        if ($censor) {
            ModerationService::record($user, 'badword', $censor['action'], $censor['word'], $content, 'c', (int) $channel['id']);
            if ($censor['action'] === 'censor') {
                $content = $censor['censored'];
            } else {
                json_out(['error' => 'Message blocked by the word filter.'], 403);
            }
        }
        $msg = MessageService::send((int) $channel['id'], $user, $content, 'image');
        $msg['channel'] = $channel['slug'];
        Realtime::message($channel['slug'], $msg);
        json_out(['ok' => true, 'message' => $msg]);
    }

    public static function command(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $restriction = ModerationService::restriction($user);
        if ($restriction) {
            json_out(['error' => $restriction], 403);
        }
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

    /** Build the JSON payload the poll endpoint returns (shared with SSE). */
    private static function pollPayload(array $user, int $since, bool $markRead = true): array
    {
        $out = ['ok' => true, 'messages' => [], 'presence' => [], 'notify_count' => 0, 'dm_list' => [], 'bg_messages' => []];
        // Admin-triggered "reconnect all clients": every tab reloads while this
        // flag is set so it re-renders with the current gateway config.
        if (Realtime::reconnectRequested()) {
            $out['reconnect'] = 1;
        }

        $notifyCount = (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR guest_user_id = ?) AND read = 0', [$user['id'], $user['id']]);
        $out['notify_count'] = $notifyCount;
        // Live DM sidebar data — returned on every poll regardless of which page
        // the user is on, so a DM sent to someone sitting in a channel surfaces.
        $out['dm_list'] = MessageService::dmSummaries($user);
        // Live unread badges for the channel sidebar (cleared when a channel is
        // opened; the client updates the sidebar from this on every poll).
        $joined = ChannelService::joinedChannelNames($user);
        $out['channel_unread'] = array_map(
            fn ($c) => ['slug' => $c['slug'], 'unread' => (int) $c['unread']],
            $joined
        );
        // Live "active chatters" count for each sidebar channel.
        $out['channel_presence'] = array_map(
            fn ($c) => ['slug' => $c['slug'], 'online' => (int) $c['online']],
            $joined
        );
        if ((int) ($user['guest'] ?? 0) !== 1) {
            $out['friends'] = FriendService::getFriendsWithStatus((int) $user['id']);
            $out['friend_requests'] = FriendService::getPendingIncoming((int) $user['id']);
            $out['channel_invites'] = ChannelService::pendingInvites((int) $user['id']);
        }
        // Background channel messages since the client's global watermark — the
        // fuel for channel audio alerts. Excludes the channel being viewed.
        $bgSince = max(0, (int) ($_GET['bg_since'] ?? 0));
        $bgExclude = 0;
        if (isset($_GET['channel']) && $_GET['channel'] !== '') {
            $bgChannel = ChannelService::findBySlug((string) $_GET['channel']);
            if ($bgChannel) {
                $bgExclude = (int) $bgChannel['id'];
            }
        }
        $out['bg_messages'] = MessageService::backgroundSince($user, $bgSince, $bgExclude);

        if (isset($_GET['dm'])) {
            $t = Auth::findActor((string) $_GET['dm']);
            if ($t) {
                $out['messages'] = MessageService::forDm($user, $t, $since);
                if ($markRead && MessageService::hasUnreadDm($user, $t)) {
                    MessageService::markDmRead($user, $t);
                }
                $out['dm'] = $t['username'];
                $out['presence'][] = array_merge([
                    'username' => $t['username'],
                    'level' => 'normal',
                    'role' => $t['role'],
                    'guest' => MessageService::isGuest($t) ? 1 : 0,
                ], Auth::statusInfo($t));
            }
            return $out;
        }

        if (isset($_GET['channel']) && $_GET['channel'] !== '') {
            $channel = ChannelService::findBySlug((string) $_GET['channel']);
            if (!$channel) {
                json_out(['error' => 'Channel not found.'], 404);
            }
            $member = AccessService::member($channel['id'], $user);
            if (!$member) {
                return ['ok' => true, 'redirect' => '/app', 'reason' => 'You are no longer in this channel.'];
            }
            $out['messages'] = MessageService::hydrateReactions(MessageService::forChannel((int) $channel['id'], $since), $user);
            $out['channel'] = $channel['slug'];
            $out['topic'] = $channel['topic'];
            foreach (ChannelService::members((string) $channel['id']) as $m) {
                $out['presence'][] = array_merge([
                    'username' => $m['username'],
                    'level' => $m['level'],
                    'role' => $m['role'],
                    'bot' => (int) $m['bot'],
                    'guest' => (int) $m['guest'],
                    'role_helper' => (int) ($m['role_helper'] ?? 0),
                    'role_color' => $m['role_color'] ?? null,
                    'avatar' => $m['avatar'] ?? null,
                ], Auth::statusInfo($m));
            }
            // Recent notifications for this channel to surface mentions/invites in a toast.
            $out['mentions'] = Database::all(
                'SELECT n.kind, COALESCE(s.username, gs.nick) AS sender, n.sender_id, n.message_id FROM notifications n
                 LEFT JOIN users s ON s.id = n.sender_id
                 LEFT JOIN guests gs ON gs.id = n.sender_guest_id
                 WHERE (n.user_id = ? OR n.guest_user_id = ?) AND n.channel_id = ? AND n.read = 0',
                [$user['id'], $user['id'], $channel['id']]
            );
            return $out;
        }

        return $out;
    }

    public static function poll(): void
    {
        $user = self::requireUser();
        $since = max(0, (int) ($_GET['since'] ?? 0));
        json_out(self::pollPayload($user, $since));
    }

    /** GET /api/ws/ticket — mint a fresh one-time WS handshake ticket (reconnects). */
    public static function wsTicket(): void
    {
        $user = self::requireUser();
        json_out(['ok' => true, 'ticket' => Realtime::mintTicket($user), 'url' => Realtime::clientUrl()]);
    }

    /**
     * POST /api/rt/report — the browser records which realtime transport it
     * actually ended up on (ws/sse/poll). 'none' = WebSocket was forced but is
     * unreachable. Used by the admin status panel to surface silent fallbacks
     * and forced-offline clients. Fire-and-forget from the client.
     */
    public static function reportTransport(): void
    {
        $user = self::requireUser();
        $transport = (string) ($_POST['transport'] ?? '');
        if (!in_array($transport, ['ws', 'sse', 'poll', 'none'], true)) {
            json_out(['error' => 'Bad transport.'], 400);
        }
        $guest = (int) ($user['guest'] ?? 0) === 1 ? 1 : 0;
        Database::query(
            'INSERT INTO rt_transports (actor_id, guest, transport, updated_at)
             VALUES (?, ?, ?, datetime(\'now\'))
             ON CONFLICT (actor_id, guest)
             DO UPDATE SET transport = excluded.transport, updated_at = excluded.updated_at',
            [(int) $user['id'], $guest, $transport]
        );
        json_out(['ok' => true]);
    }

    /**
     * GET /api/stream — Server-Sent Events realtime. Opt-in via the `realtime`
     * setting (poll is the shared-hosting default). Each iteration pushes the
     * same payload the poll endpoint returns, so the client reuses one handler.
     * Long-lived connections hold a PHP worker, so this targets php-fpm/VPS.
     */
    public static function stream(): void
    {
        $user = self::requireUser();
        $since = max(0, (int) ($_GET['since'] ?? 0));

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no'); // nginx: disable proxy buffering for streaming
        header('Connection: keep-alive');
        if (function_exists('ini_set')) {
            @ini_set('zlib.output_compression', '0');
        }
        // Let the script die when the client disconnects (connection_aborted()
        // then turns true), so idle streams never pin a worker forever.

        $interval = max(1, (int) (config_get('poll_interval', '2') ?? 2));
        $start = time();
        $last = 0;
        // Refresh presence inside the loop so a long-lived stream never goes stale:
        // without this, SSE users vanish from the online list after presence_throttle.
        $presenceThrottle = max(5, (int) (config_get('presence_throttle', '30') ?? 30));
        $lastPresence = $start;

        $send = function (string $data): void {
            echo "data: " . $data . "\n\n";
            @ob_flush();
            flush();
        };

        // Send a heartbeat every ~15s so proxies don't time the connection out.
        $heartbeat = $start;
        while (true) {
            if (connection_aborted() || time() - $start > 3600) {
                break;
            }
            // Keep "last seen" fresh on a throttled cadence, mirroring Auth::user().
            if (time() - $lastPresence >= $presenceThrottle) {
                if ((int) ($user['guest'] ?? 0) === 1) {
                    Database::query('UPDATE guests SET last_seen = datetime("now"), ip = ? WHERE id = ?', [client_ip(), $user['id']]);
                } else {
                    Database::query('UPDATE users SET last_seen = datetime("now"), last_ip = ? WHERE id = ?', [client_ip(), $user['id']]);
                }
                $user['last_seen'] = now();
                $lastPresence = time();
            }
            $out = self::pollPayload($user, $since, false);
            // Advance the server-side watermark once the batch is caught up, so the
            // next tick fetches only what's new instead of re-sending the whole
            // delta. When a batch hits the 100-row cap, hold `since` so nothing is
            // dropped on a busy channel — the next tick keeps catching up.
            $batch = $out['messages'] ?? [];
            if (count($batch) < 100) {
                foreach ($batch as $m) {
                    if ((int) ($m['id'] ?? 0) > $since) {
                        $since = (int) $m['id'];
                    }
                }
            }
            $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false && $json !== $last) {
                $send($json);
                $last = $json;
            } elseif (time() - $heartbeat >= 15) {
                $send(': keepalive');
                $heartbeat = time();
            }
            sleep($interval);
        }
        exit;
    }

    public static function historyApi(): void
    {
        $user = self::requireUser();
        $before = max(0, (int) ($_GET['before'] ?? 0));
        $channel = null;
        if (isset($_GET['channel']) && $_GET['channel'] !== '') {
            $channel = ChannelService::findBySlug((string) $_GET['channel']);
            if (!$channel) {
                json_out(['error' => 'Channel not found.'], 404);
            }
            if (!AccessService::member($channel['id'], $user)) {
                json_out(['error' => 'You are not a member of this channel.'], 403);
            }
            $messages = MessageService::hydrateReactions(MessageService::historyBefore((int) $channel['id'], $before), $user);
            json_out(['ok' => true, 'messages' => $messages, 'channel' => $channel['slug']]);
        }
        if (isset($_GET['dm']) && $_GET['dm'] !== '') {
            $t = Auth::findActor((string) $_GET['dm']);
            if (!$t) {
                json_out(['error' => 'No such user.'], 404);
            }
            // Reuse forChannel-style pagination for DMs (older than $before).
            $messages = MessageService::dmHistoryBefore($user, $t, $before);
            json_out(['ok' => true, 'messages' => $messages, 'dm' => $t['username']]);
        }
        json_out(['error' => 'Missing channel or dm.'], 400);
    }

    public static function reaction(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        if (!MessageService::reactionsEnabled()) {
            json_out(['error' => 'Reactions are disabled on this server.'], 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $emoji = trim((string) ($_POST['emoji'] ?? ''));
        $r = MessageService::toggleReaction($id, $user, $emoji);
        if (is_string($r)) {
            json_out(['error' => $r], 400);
        }
        self::pushMsgUpdate($id, 'reaction', ['reactions' => $r['reactions']['rows'] ?? []]);
        json_out(['ok' => true, 'added' => $r['added'], 'reactions' => $r['reactions']]);
    }

    public static function search(): void
    {
        $user = self::requireUser();
        $term = trim((string) ($_GET['q'] ?? ''));
        if ($term === '') {
            json_out(['ok' => true, 'results' => []]);
        }
        $channels = MessageService::searchChannels($user, $term);
        $dms = MessageService::searchDm($user, $term);
        foreach ($channels as &$r) {
            $r['snippet'] = MessageService::snippet($r['content'], $term);
        }
        unset($r);
        foreach ($dms as &$r) {
            $r['snippet'] = MessageService::snippet($r['content'], $term);
        }
        unset($r);
        json_out(['ok' => true, 'results' => ['channels' => $channels, 'dms' => $dms]]);
    }

    public static function browseData(): void
    {
        $user = self::requireUser();
        $myChannels = ChannelService::ownedChannels($user);
        $myIds = array_column($myChannels, 'id');
        $channels = array_values(array_filter(
            ChannelService::publicChannels(''),
            fn ($c) => !in_array($c['id'], $myIds, true)
        ));
        $joined = ChannelService::joinedChannelNames($user);
        $joinedMap = [];
        foreach ($joined as $c) {
            $joinedMap[$c['id']] = true;
        }
        json_out([
            'ok' => true,
            'channels' => array_map(fn ($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'slug' => $c['slug'], 'topic' => $c['topic'] ?? '', 'description' => $c['description'] ?? '', 'members' => (int) $c['members'], 'online' => (int) $c['members'], 'visibility' => $c['visibility'], 'joined' => isset($joinedMap[$c['id']])], $channels),
            'myChannels' => array_map(fn ($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'slug' => $c['slug'], 'topic' => $c['topic'] ?? '', 'description' => $c['description'] ?? '', 'members' => (int) $c['members'], 'online' => (int) $c['members'], 'visibility' => $c['visibility'], 'joined' => true], $myChannels),
            'online' => online_count(),
            'peak' => (int) (config_get('peak_online', '0') ?? 0),
        ]);
    }

    public static function notifications(): void
    {
        $user = self::requireUser();
        $rows = Database::all(
            'SELECT n.*, COALESCE(s.username, gs.nick) AS sender, c.name AS channel_name FROM notifications n
             LEFT JOIN users s ON s.id = n.sender_id
             LEFT JOIN guests gs ON gs.id = n.sender_guest_id
             LEFT JOIN channels c ON c.id = n.channel_id
             WHERE (n.user_id = ? OR n.guest_user_id = ?) AND n.read = 0 ORDER BY n.id DESC LIMIT 50',
            [$user['id'], $user['id']]
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
        Database::query('UPDATE notifications SET read = 1 WHERE user_id = ? OR guest_user_id = ?', [$user['id'], $user['id']]);
        json_out(['ok' => true]);
    }

    public static function dismissNotification(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            Database::query('DELETE FROM notifications WHERE id = ? AND (user_id = ? OR guest_user_id = ?)', [$id, $user['id'], $user['id']]);
        }
        $count = (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR guest_user_id = ?) AND read = 0', [$user['id'], $user['id']]);
        json_out(['ok' => true, 'notify_count' => $count]);
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
        self::pushMsgUpdate($id, 'delete');
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
            log_audit('message_edit_denied', 'msg#' . $id, $r);
            json_out(['error' => $r], 403);
        }
        log_audit('message_edit', 'msg#' . $id, $user['username']);
        self::pushMsgUpdate($id, 'edit', ['content' => $content]);
        json_out(['ok' => true, 'content' => $content]);
    }

    /** Fan out a channel message edit/delete/reaction to the channel's viewers. */
    private static function pushMsgUpdate(int $messageId, string $action, array $extra = []): void
    {
        $cid = Database::scalar('SELECT channel_id FROM messages WHERE id = ?', [$messageId]);
        if (!$cid) {
            return;
        }
        $slug = (string) (Database::scalar('SELECT slug FROM channels WHERE id = ?', [(int) $cid]) ?? '');
        if ($slug !== '') {
            Realtime::msgUpdate($slug, $action, $messageId, $extra);
        }
    }

    /**
     * POST /api/report — right-click -> report a channel or DM message to staff.
     * Snapshots the sender and content (inline image URLs included) so the report
     * survives edits and deletions. Guests may report too.
     */
    public static function report(): void
    {
        $user = self::requireUser();
        self::requireCsrf();
        $id = (int) ($_POST['id'] ?? 0);
        $pm = ($_POST['pm'] ?? '0') === '1';
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $other = trim((string) ($_POST['other'] ?? ''));
        if ($id < 1) {
            json_out(['error' => 'Missing message.'], 400);
        }
        $isGuest = MessageService::isGuest($user);
        // One report per message per reporter (checked before reason validation so
        // a repeat submission is reported as a duplicate, not a bad request).
        $dup = Database::row(
            'SELECT 1 FROM reports WHERE pm = ? AND message_id = ?
             AND COALESCE(reporter_user_id, 0) = CAST(? AS INTEGER)
             AND COALESCE(reporter_guest_id, 0) = CAST(? AS INTEGER) LIMIT 1',
            [$pm ? 1 : 0, $id, $isGuest ? 0 : (int) $user['id'], $isGuest ? (int) $user['id'] : 0]
        );
        if ($dup) {
            json_out(['error' => 'You have already reported this message.'], 409);
        }
        if ($reason === '') {
            json_out(['error' => 'Please choose a reason.'], 400);
        }
        if ($reason === 'Other' && $other === '') {
            json_out(['error' => 'Please describe the issue.'], 400);
        }
        $other = mb_substr($other, 0, 500);
        if ($pm) {
            $row = Database::row('SELECT * FROM private_messages WHERE id = ?', [$id]);
            if (!$row) {
                json_out(['error' => 'Message not found.'], 404);
            }
            // Reporter must be one of the two participants.
            $meU = $isGuest ? 0 : (int) $user['id'];
            $meG = $isGuest ? (int) $user['id'] : 0;
            $involved = ($row['sender_id'] === null ? 0 : (int) $row['sender_id']) === $meU
                || ($row['recipient_id'] === null ? 0 : (int) $row['recipient_id']) === $meU
                || ($row['sender_guest_id'] === null ? 0 : (int) $row['sender_guest_id']) === $meG
                || ($row['recipient_guest_id'] === null ? 0 : (int) $row['recipient_guest_id']) === $meG;
            if (!$involved) {
                json_out(['error' => 'You cannot report this message.'], 403);
            }
            $senderId = $row['sender_id'] === null ? null : (int) $row['sender_id'];
            $senderGuestId = $row['sender_guest_id'] === null ? null : (int) $row['sender_guest_id'];
            $senderName = (string) ($row['sender_id']
                ? (Database::scalar('SELECT username FROM users WHERE id = ?', [(int) $row['sender_id']]) ?: '')
                : ($row['sender_guest_id']
                    ? (Database::scalar('SELECT nick FROM guests WHERE id = ?', [(int) $row['sender_guest_id']]) ?: '')
                    : ''));
            $content = (string) $row['content'];
            $kind = (string) ($row['kind'] ?? 'message');
            $channelId = null;
        } else {
            $row = Database::row('SELECT * FROM messages WHERE id = ?', [$id]);
            if (!$row) {
                json_out(['error' => 'Message not found.'], 404);
            }
            if ((int) $row['deleted'] === 1) {
                json_out(['error' => 'This message has been removed.'], 410);
            }
            // Reporter must be a member of the channel.
            if (!AccessService::member((int) $row['channel_id'], $user)) {
                json_out(['error' => 'You are not a member of this channel.'], 403);
            }
            $senderId = $row['sender_id'] === null ? null : (int) $row['sender_id'];
            $senderGuestId = $row['sender_guest_id'] === null ? null : (int) $row['sender_guest_id'];
            $senderName = (string) ($row['sender_id']
                ? (Database::scalar('SELECT username FROM users WHERE id = ?', [(int) $row['sender_id']]) ?: '')
                : ($row['sender_guest_id']
                    ? (Database::scalar('SELECT nick FROM guests WHERE id = ?', [(int) $row['sender_guest_id']]) ?: '')
                    : ''));
            $content = (string) $row['content'];
            $kind = (string) ($row['kind'] ?? 'message');
            $channelId = (int) $row['channel_id'];
        }

        Database::query(
            'INSERT INTO reports (message_id, pm, channel_id, reporter_user_id, reporter_guest_id,
                                  sender_user_id, sender_guest_id, sender_name, content, kind, reason, reason_other)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $pm ? 1 : 0, $channelId,
             $isGuest ? null : (int) $user['id'], $isGuest ? (int) $user['id'] : null,
             $senderId, $senderGuestId, mb_substr($senderName, 0, 64), mb_substr($content, 0, 4000),
             $kind !== '' ? $kind : 'message', $reason, $other]
        );
        log_audit('report_add', 'msg#' . $id, $reason . ($other !== '' ? ' / ' . $other : ''));
        json_out(['ok' => true, 'message' => 'Report submitted. Thanks — staff will review it.']);
    }
}
