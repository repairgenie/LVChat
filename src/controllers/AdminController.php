<?php

declare(strict_types=1);

final class AdminController
{
    /** Native desktop apps available for download (config key prefix). */
    public const DOWNLOAD_APPS = ['desktop', 'messenger'];

    /** Per-app platforms, each with a URL + version config key pair. */
    public const DOWNLOAD_PLATFORMS = ['win', 'mac', 'linux_rpm', 'linux_deb', 'linux_appimage'];

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
            'overviewMessages' => AnalyticsService::messagesDaily(AnalyticsService::rangeSince('30')),
            'overviewTopUsers' => AnalyticsService::topUsers(AnalyticsService::rangeSince('30'), 5),
        ]);
    }

    /** GET /admin/analytics — charts over chat, moderation, growth, and ops data. */
    public static function analytics(): void
    {
        $admin = self::require();
        $range = (string) ($_GET['range'] ?? '30');
        if (!in_array($range, AnalyticsService::ranges(), true)) {
            $range = '30';
        }
        $since = AnalyticsService::rangeSince($range);
        render_view('admin/analytics', [
            'admin' => $admin,
            'range' => $range,
            'kpis' => AnalyticsService::kpis($since),
            'activeCounts' => AnalyticsService::activeCounts(),
            'messagesDaily' => AnalyticsService::messagesDaily($since),
            'pmsDaily' => AnalyticsService::pmsDaily($since),
            'registrationsDaily' => AnalyticsService::registrationsDaily($since),
            'dauDaily' => AnalyticsService::dauDaily($since),
            'topUsers' => AnalyticsService::topUsers($since),
            'leastActive' => AnalyticsService::leastActive(12),
            'hourly' => AnalyticsService::activityByHour($since),
            'weekday' => AnalyticsService::activityByWeekday($since),
            'topChannels' => AnalyticsService::topChannels($since),
            'topDmSenders' => AnalyticsService::topDmSenders($since),
            'censorLeaders' => AnalyticsService::censorLeaders($since),
            'spamLeaders' => AnalyticsService::spamLeaders($since),
            'topMatchedWords' => AnalyticsService::topMatchedWords($since),
            'moderationDaily' => AnalyticsService::moderationDaily($since),
            'moderationMix' => AnalyticsService::moderationMix($since),
            'banMix' => AnalyticsService::banMix(),
            'reportMix' => AnalyticsService::reportMix($since),
            'topReported' => AnalyticsService::topReported($since),
            'reportReasons' => AnalyticsService::reportReasons($since),
            'auditDaily' => AnalyticsService::auditDaily($since),
            'ticketsDaily' => AnalyticsService::ticketsDaily($since),
            'inviteStats' => AnalyticsService::inviteStats($since),
            'webhooks' => AnalyticsService::webhooks(),
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

    /** GET /admin/users/{id} — staff-only moderation history + notes for an account. */
    public static function userShow(array $params): void
    {
        $staff = ModerationService::requireStaff();
        $u = Database::row('SELECT * FROM users WHERE id = ?', [(int) $params['id']]);
        if (!$u) {
            render_view('errors/notfound', [], null);
        }
        render_view('admin/users_show', [
            'admin' => $staff,
            'user' => $u,
            'history' => ModerationService::history((int) $u['id']),
            'events' => ModerationService::eventsForUser((int) $u['id']),
        ]);
    }

    /** GET /admin/moderation — the moderation queue (staff+admin). */
    public static function moderation(): void
    {
        $staff = ModerationService::requireStaff();
        $summary = Database::all(
            "SELECT COALESCE(u.username, g.nick) AS username, COALESCE(me.user_id, 0) AS user_id,
                    COALESCE(me.guest_id, 0) AS guest_id, COUNT(*) AS hits,
                    SUM(CASE WHEN me.kind = 'badword' THEN 1 ELSE 0 END) AS badwords,
                    SUM(CASE WHEN me.kind = 'spamfilter' THEN 1 ELSE 0 END) AS spamfilters,
                    SUM(CASE WHEN me.kind NOT IN ('badword','spamfilter') THEN 1 ELSE 0 END) AS actions,
                    MAX(me.created_at) AS last_hit
             FROM moderation_events me
             LEFT JOIN users u ON u.id = me.user_id
             LEFT JOIN guests g ON g.id = me.guest_id
             GROUP BY me.user_id, me.guest_id
             ORDER BY hits DESC LIMIT 200"
        );
        $events = Database::all(
            'SELECT me.*, u.username, g.nick AS guest_name FROM moderation_events me
             LEFT JOIN users u ON u.id = me.user_id
             LEFT JOIN guests g ON g.id = me.guest_id
             ORDER BY me.id DESC LIMIT 300'
        );
        render_view('admin/moderation', ['admin' => $staff, 'summary' => $summary, 'events' => $events]);
    }

    /** GET /admin/reports — message reports queue (staff+admin). */
    public static function reports(): void
    {
        $staff = ModerationService::requireStaff();
        $status = trim((string) ($_GET['status'] ?? 'open'));
        $where = '';
        $params = [];
        if (in_array($status, ['open', 'investigated', 'resolved', 'dismissed'], true)) {
            $where = 'WHERE r.status = ?';
            $params[] = $status;
        }
        $rows = Database::all(
            "SELECT r.*, ru.username AS reporter_name, rg.nick AS reporter_guest_name,
                    sh.username AS handled_name
             FROM reports r
             LEFT JOIN users ru ON ru.id = r.reporter_user_id
             LEFT JOIN guests rg ON rg.id = r.reporter_guest_id
             LEFT JOIN users sh ON sh.id = r.handled_by
             $where
             ORDER BY r.id DESC LIMIT 200",
            $params
        );
        render_view('admin/reports', ['admin' => $staff, 'reports' => $rows, 'status' => $status]);
    }

    /** GET /admin/support — all support tickets (staff+admin). */
    public static function support(): void
    {
        $staff = ModerationService::requireStaff();
        $status = trim((string) ($_GET['status'] ?? ''));
        $assignee = trim((string) ($_GET['assignee'] ?? ''));
        $tickets = SupportService::all($status, $assignee, (int) $staff['id']);
        render_view('admin/support', [
            'admin' => $staff,
            'tickets' => $tickets,
            'status' => $status,
            'assignee' => $assignee,
            'staff' => SupportService::staff(),
            'users' => Database::all("SELECT id, username, email FROM users WHERE guest = 0 ORDER BY username COLLATE NOCASE LIMIT 500"),
        ]);
    }

    /** GET /admin/legal — tiptap editors for the ToS and Privacy Policy (admin only). */
    public static function legal(): void
    {
        $admin = Auth::requireAdmin();
        render_view('admin/legal', [
            'admin' => $admin,
            'terms' => LegalService::get('terms'),
            'privacy' => LegalService::get('privacy'),
        ]);
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

    /** GET /admin/urls — global list of channel URLs/domains that may not be
     *  used as a Channel URL (enforced in ChannelService::setChannelUrl). */
    public static function bannedUrls(): void
    {
        $admin = self::require();
        render_view('admin/banned_urls', ['admin' => $admin, 'banned' => UrlBanService::all()]);
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

    /** Sound alerts uploaded by admins and offered to every user. */
    public static function sounds(): void
    {
        $admin = self::require();
        render_view('admin/sounds', ['admin' => $admin, 'sounds' => SoundService::listAll()]);
    }

    public static function logs(): void
    {
        $admin = self::require();
        $channel = trim((string) ($_GET['channel'] ?? ''));
        $q = trim((string) ($_GET['q'] ?? ''));
        $sql = "SELECT channel_name, substr(created_at,1,10) AS day, COUNT(*) AS entries
                FROM chat_logs WHERE channel_name IS NOT NULL";
        $params = [];
        if ($channel !== '') {
            $sql .= ' AND channel_name = ?';
            $params[] = $channel;
        }
        if ($q !== '') {
            $sql .= ' AND content LIKE ? ESCAPE "\\"';
            $params[] = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $q) . '%';
        }
        $sql .= ' GROUP BY channel_name, day ORDER BY day DESC, channel_name COLLATE NOCASE LIMIT 1000';
        $rows = Database::all($sql, $params);
        $channels = MessageService::loggedChannels();
        render_view('admin/logs', ['admin' => $admin, 'rows' => $rows, 'channel' => $channel, 'channels' => $channels, 'q' => $q]);
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
        $keys = ['site_name', 'site_tagline', 'logo_url', 'registration_enabled', 'registration_requires_approval', 'registration_rate_limit', 'spamfilter_enabled', 'uploads_enabled', 'reactions_enabled', 'gifs_enabled', 'giphy_api_key', 'webhooks_enabled', 'chat_logging_enabled', 'max_channels_per_user', 'presence_throttle', 'poll_interval', 'realtime', 'realtime_force', 'ws_ip', 'ws_port', 'ws_ssl_cert', 'ws_ssl_key', 'timezone', 'motd', 'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_from_email', 'smtp_from_name', 'mfa_require_admin', 'mfa_require_staff', 'mfa_require_user', 'download_update_url', 'updater_enabled', 'updater_url', 'app_origins'];
        foreach (self::DOWNLOAD_APPS as $dlApp) {
            foreach (self::DOWNLOAD_PLATFORMS as $dlPlat) {
                $keys[] = "download_{$dlApp}_{$dlPlat}_url";
                $keys[] = "download_{$dlApp}_{$dlPlat}_version";
            }
        }
        $settings = [];
        foreach ($keys as $k) {
            $settings[$k] = (string) config_get($k, '');
        }
        // The password is write-only: never echo it back. The view just reports
        // whether one is already stored so admins can leave the field blank.
        $settings['smtp_has_password'] = trim((string) (config_get('smtp_password', '') ?? '')) !== '';
        render_view('admin/settings', ['admin' => $admin, 'settings' => $settings]);
    }

    /** GET /admin/updates — installed vs upstream latest for all three apps. */
    public static function updates(): void
    {
        $admin = self::require();
        render_view('admin/updates', [
            'admin' => $admin,
            'status' => UpdaterService::statusAll(),
            'updaterUrl' => UpdaterService::enabled() ? UpdaterService::baseUrl() : '',
            'enabled' => UpdaterService::enabled(),
            'cachedAt' => UpdaterService::cachedAt(),
            'lastDownload' => self::lastWebDownload(),
        ]);
    }

    /** POST /admin/updates/check — force a manifest refetch. */
    public static function updatesCheck(): void
    {
        $admin = self::require();
        Csrf::verify();
        $ok = UpdaterService::enabled();
        if ($ok) {
            UpdaterService::fetchManifest(true);
            $ok = UpdaterService::cachedAt() !== 0;
        }
        flash($ok ? 'Update feed refreshed.' : 'Could not reach the update server — check the URL in Admin → Settings → Updates.');
        redirect('/admin/updates');
    }

    /** POST /admin/updates/pin — copy upstream values into the custom download
     *  fields so an admin can freeze the current upstream state (or edit it). */
    public static function updatesPin(): void
    {
        $admin = self::require();
        Csrf::verify();
        $app = (string) ($_POST['app'] ?? '');
        if (!in_array($app, UpdaterService::APPS, true) || $app === 'web') {
            flash('That app cannot be pinned here.');
            redirect('/admin/updates');
        }
        if (!UpdaterService::enabled()) {
            flash('Enable the update feed first (Admin → Settings → Updates).');
            redirect('/admin/updates');
        }
        UpdaterService::fetchManifest(true);
        $latest = UpdaterService::latestVersion($app);
        if ($latest === '') {
            flash('No upstream version published for ' . h($app) . '.');
            redirect('/admin/updates');
        }
        $pinned = 0;
        foreach (UpdaterService::PLATFORMS as $plat) {
            $url = UpdaterService::latestUrl($app, $plat);
            if ($url === '') {
                continue;
            }
            config_set("download_{$app}_{$plat}_url", $url);
            config_set("download_{$app}_{$plat}_version", $latest);
            $pinned++;
        }
        if ($pinned === 0) {
            flash('The upstream feed has no download links for ' . h($app) . '.');
        } else {
            log_audit('updates_pin', $app);
            flash("Pinned {$pinned} platform link(s) for " . h($app) . ' v' . h($latest) . ' into the custom download fields.');
        }
        redirect('/admin/updates');
    }

    /** POST /admin/updates/download-web — fetch + sha256-verify the web tarball. */
    public static function updatesDownloadWeb(): void
    {
        $admin = self::require();
        Csrf::verify();
        $result = UpdaterService::downloadWebUpdate();
        if (!empty($result['ok'])) {
            log_audit('updates_download', 'web ' . ($result['version'] ?? ''), $result['filename'] ?? null);
            $_SESSION['last_web_download'] = $result;
            flash('Downloaded and verified v' . h($result['version'] ?? '') . ' — ' . h($result['filename'] ?? '') . ' (' . number_format((int) ($result['size'] ?? 0)) . ' bytes). ' . h($result['instructions'] ?? ''));
        } else {
            flash('Update download failed: ' . h($result['error'] ?? 'unknown error'));
        }
        redirect('/admin/updates');
    }

    /** The last verified web-update download (if any) for the Updates page. */
    private static function lastWebDownload(): ?array
    {
        $v = $_SESSION['last_web_download'] ?? null;
        if (!is_array($v)) {
            return null;
        }
        if (!empty($v['path']) && !is_file($v['path'])) {
            unset($_SESSION['last_web_download']);
            return null;
        }
        return $v;
    }

    /** GET /admin/updates/download?file=… — stream a verified web-update archive. */
    public static function updatesDownload(): void
    {
        $admin = self::require();
        $file = basename((string) ($_GET['file'] ?? ''));
        if ($file === '' || $file === '.' || $file === '..') {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        $path = ROOT . '/data/updates/' . $file;
        if (!is_file($path)) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . h($file) . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }

    /** GET /admin/theme — server-wide appearance: preset library + chat background. */
    public static function theme(): void
    {
        $admin = self::require();
        render_view('admin/theme', [
            'admin' => $admin,
            'presets' => ThemeService::presets(),
            'globalTheme' => ThemeService::globalTheme(),
            'customizationEnabled' => ThemeService::customizationEnabled(),
            'rendered' => ThemeService::render(ThemeService::globalTheme()),
        ]);
    }

    public static function invites(): void
    {
        $admin = self::require();
        $invites = InviteService::all();
        $lastLink = $_SESSION['invite_link'] ?? null;
        unset($_SESSION['invite_link']);
        render_view('admin/invites', [
            'admin' => $admin,
            'invites' => $invites,
            'smtp' => Mailer::configured(),
            'lastLink' => $lastLink,
        ]);
    }

    public static function openclaw(): void
    {
        $admin = self::require();
        $bots = OpenClawBotService::all();
        $channels = Database::all('SELECT id, name FROM channels ORDER BY name COLLATE NOCASE');
        $botChannels = [];
        $botPmUsers = [];
        foreach ($bots as $bot) {
            $botChannels[(int) $bot['id']] = OpenClawBotService::channelsForBot((int) $bot['id']);
            $botPmUsers[(int) $bot['id']] = OpenClawBotService::pmUsersForBot((int) $bot['id']);
        }
        render_view('admin/openclaw', [
            'admin' => $admin,
            'bots' => $bots,
            'channels' => $channels,
            'botChannels' => $botChannels,
            'botPmUsers' => $botPmUsers,
        ]);
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
                ModerationService::note($id, $admin, 'ban', (string) ($_POST['reason'] ?? ''));
                log_audit('user_ban', 'user#' . $id);
                $message = 'User banned.';
                break;
            case 'user_unban':
                $id = (int) ($_POST['id'] ?? 0);
                Database::query('UPDATE users SET banned = 0, ban_reason = NULL WHERE id = ?', [$id]);
                ModerationService::note($id, $admin, 'unban', '');
                log_audit('user_unban', 'user#' . $id);
                $message = 'User unbanned.';
                break;
            case 'user_admin':
                $id = (int) ($_POST['id'] ?? 0);
                Database::query('UPDATE users SET role = "admin" WHERE id = ?', [$id]);
                // Auto-join the operator action log.
                Database::query(
                    "INSERT OR IGNORE INTO channel_members (channel_id, user_id, level)
                     SELECT c.id, ?, 'normal' FROM channels c WHERE c.slug = 'oper-log'",
                    [$id]
                );
                ModerationService::note($id, $admin, 'role', 'Promoted to admin');
                log_audit('user_admin', 'user#' . $id);
                $message = 'User promoted to admin.';
                break;
            case 'user_deadmin':
                $id = (int) ($_POST['id'] ?? 0);
                Database::query('UPDATE users SET role = "user" WHERE id = ? AND id != ?', [$id, $admin['id']]);
                // Demoted users lose access to the operator action log.
                Database::query(
                    "DELETE FROM channel_members WHERE user_id = ? AND channel_id IN
                     (SELECT id FROM channels WHERE slug = 'oper-log')",
                    [$id]
                );
                ModerationService::note($id, $admin, 'role', 'Admin rights removed');
                log_audit('user_deadmin', 'user#' . $id);
                $message = 'Admin rights removed.';
                break;
            case 'user_staff':
                $id = (int) ($_POST['id'] ?? 0);
                Database::query('UPDATE users SET role = "staff" WHERE id = ?', [$id]);
                ModerationService::note($id, $admin, 'role', 'Promoted to staff');
                log_audit('user_staff', 'user#' . $id);
                $message = 'User promoted to staff.';
                break;
            case 'user_destaff':
                $id = (int) ($_POST['id'] ?? 0);
                Database::query('UPDATE users SET role = "user" WHERE id = ?', [$id]);
                ModerationService::note($id, $admin, 'role', 'Staff role removed');
                log_audit('user_destaff', 'user#' . $id);
                $message = 'Staff role removed.';
                break;
            case 'user_reset':
                $id = (int) ($_POST['id'] ?? 0);
                $pw = bin2hex(random_bytes(6));
                Database::query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_ARGON2ID), $id]);
                Database::query('DELETE FROM sessions WHERE user_id = ?', [$id]);
                ModerationService::note($id, $admin, 'reset_password', 'Password reset by an administrator');
                log_audit('user_reset', 'user#' . $id);
                $message = "Password reset to: $pw (user must use it to log in)";
                break;
            case 'user_mfa_reset':
                $id = (int) ($_POST['id'] ?? 0);
                TotpService::disable($id);
                Database::query('DELETE FROM sessions WHERE user_id = ?', [$id]);
                ModerationService::note($id, $admin, 'mfa_reset', 'MFA reset by an administrator');
                log_audit('user_mfa_reset', 'user#' . $id);
                $message = 'MFA reset. The user will be asked to set it up again at next login.';
                break;
            case 'user_delete':
                $id = (int) ($_POST['id'] ?? 0);
                if ($id === (int) $admin['id']) {
                    $ok = false;
                    $message = 'You cannot delete your own account.';
                    break;
                }
                $u = Database::row('SELECT * FROM users WHERE id = ?', [$id]);
                if (!$u) {
                    $ok = false;
                    $message = 'User not found.';
                    break;
                }
                if ($u['role'] === 'admin' && (int) Database::scalar('SELECT COUNT(*) FROM users WHERE role = "admin"') <= 1) {
                    $ok = false;
                    $message = 'You cannot delete the last admin account.';
                    break;
                }
                // Owned channels pass to the channel's chosen successor (if
                // still a member) or the longest-standing remaining member
                // before the row goes, then IRC cleanup runs on every channel
                // the user was in (empty unregistered channels vanish).
                $channelIds = array_column(Database::all('SELECT DISTINCT channel_id FROM channel_members WHERE user_id = ?', [$id]), 'channel_id');
                foreach ($channelIds as $cid) {
                    $ch = Database::row('SELECT * FROM channels WHERE id = ?', [$cid]);
                    if ($ch && (int) $ch['owner_id'] === $id) {
                        $heir = null;
                        if (!empty($ch['successor_id']) && AccessService::member($cid, (int) $ch['successor_id'])) {
                            $heir = ['user_id' => (int) $ch['successor_id']];
                        }
                        if (!$heir) {
                            $heir = Database::row(
                                'SELECT user_id FROM channel_members WHERE channel_id = ? AND user_id IS NOT NULL AND user_id != ?
                                 ORDER BY joined_at ASC, user_id ASC LIMIT 1',
                                [$cid, $id]
                            );
                        }
                        if ($heir) {
                            Database::query('UPDATE channels SET owner_id = ? WHERE id = ?', [$heir['user_id'], $cid]);
                            Database::query('UPDATE channel_members SET level = "founder" WHERE channel_id = ? AND user_id = ?', [$cid, $heir['user_id']]);
                        }
                    }
                }
                // A freed nick must not leave a usable o:line behind (opers are
                // keyed by username, not user id) — anyone could claim the nick
                // and /oper with it.
                Database::query('DELETE FROM opers WHERE username = ? COLLATE NOCASE', [$u['username']]);
                Database::query("DELETE FROM reactions WHERE actor_type = 'user' AND actor_id = ?", [$id]);
                // The row goes last: sessions and every FK reference cascade or
                // set NULL. Messages keep their content (sender_id -> NULL) and
                // the append-only chat_logs archive keeps the username forever.
                Database::query('DELETE FROM users WHERE id = ?', [$id]);
                foreach ($channelIds as $cid) {
                    ChannelService::afterMemberRemoval($cid);
                }
                log_audit('user_delete', $u['username'], (string) $id);
                $message = 'User ' . $u['username'] . ' deleted.';
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
            case 'banned_url_add':
                $err = UrlBanService::add(
                    (string) ($_POST['domain'] ?? ''),
                    (string) ($_POST['reason'] ?? ''),
                    (int) $admin['id']
                );
                if ($err) {
                    $ok = false;
                    $message = $err;
                } else {
                    $message = 'Domain banned.';
                }
                break;
            case 'banned_url_del':
                UrlBanService::remove((int) ($_POST['id'] ?? 0));
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
                    $reason = trim((string) ($_POST['reason'] ?? 'Banned by admin (IP)'));
                    BanService::addBan('zline', null, (string) $u['last_ip'], $reason, null, (int) $admin['id']);
                    ModerationService::note($id, $admin, 'zline_ip', "IP {$u['last_ip']}" . ($reason !== '' ? ' — ' . $reason : ''));
                    log_audit('zline_ip', $u['username'], $u['last_ip']);
                    $message = "IP {$u['last_ip']} banned (zline).";
                } else {
                    $ok = false;
                    $message = 'No IP recorded for that user.';
                }
                break;
            case 'badword_add':
                $word = strtolower(trim((string) ($_POST['word'] ?? '')));
                $action = ($_POST['badword_action'] ?? 'censor') === 'block' ? 'block' : 'censor';
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
            case 'sound_add':
                $r = SoundService::add($_FILES['file'] ?? [], (string) ($_POST['name'] ?? ''));
                if (!$r['ok']) {
                    $ok = false;
                    $message = $r['error'];
                } else {
                    log_audit('sound_add', 'sound#' . $r['id']);
                    $message = 'Sound added.';
                }
                break;
            case 'sound_toggle':
                SoundService::toggle((int) ($_POST['id'] ?? 0));
                $message = 'Sound toggled.';
                break;
            case 'sound_del':
                SoundService::remove((int) ($_POST['id'] ?? 0));
                log_audit('sound_del', 'sound#' . (int) ($_POST['id'] ?? 0));
                $message = 'Sound deleted.';
                break;
            case 'role_save':
                $id = (int) ($_POST['id'] ?? 0);
                $name = trim((string) ($_POST['name'] ?? ''));
                $color = trim((string) ($_POST['color'] ?? '#5865f2'));
                $helper = ($_POST['helper'] ?? '0') === '1' ? 1 : 0;
                $perms = array_map('strval', (array) ($_POST['perms'] ?? []));
                $allowedPerms = ['oper', 'manage_users', 'manage_channels', 'manage_bans', 'manage_badwords', 'manage_roles'];
                $perms = array_values(array_intersect($allowedPerms, $perms));
                if ($name === '') {
                    $ok = false;
                    $message = 'A role name is required.';
                } elseif ($id > 0) {
                    Database::query('UPDATE roles SET name = ?, color = ?, perms = ?, helper = ? WHERE id = ?', [$name, $color, json_encode($perms), $helper, $id]);
                    log_audit('role_update', $name);
                    $message = 'Role updated.';
                } else {
                    Database::query('INSERT INTO roles (name, color, perms, helper) VALUES (?, ?, ?, ?)', [$name, $color, json_encode($perms), $helper]);
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
                ModerationService::note($id, $admin, 'role', 'Custom role set to ' . ($roleId > 0 ? (Database::scalar('SELECT name FROM roles WHERE id = ?', [$roleId]) ?: "role #$roleId") : 'none'));
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
            case 'user_create':
                $username = trim((string) ($_POST['username'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));
                $role = (string) ($_POST['role'] ?? 'user');
                if (!in_array($role, ['user', 'staff', 'admin'], true)) {
                    $role = 'user';
                }
                $pw = bin2hex(random_bytes(8)); // 16 hex chars, shown once
                $result = Auth::register($username, $email, $pw, true, true);
                if (!$result['ok']) {
                    $ok = false;
                    $message = implode(' ', $result['errors']);
                } else {
                    if ($role === 'staff' || $role === 'admin') {
                        Database::query('UPDATE users SET role = ? WHERE id = ?', [$role, $result['id']]);
                    }
                    log_audit('user_create', $username, $role);
                    $message = "User $username created. Password: $pw (shown once)";
                    if (($_POST['email_welcome'] ?? '0') === '1') {
                        if (Mailer::configured()) {
                            $sent = Mailer::sendWelcome($email, $username, $pw);
                            $message .= $sent['ok'] ? ' — welcome email sent.' : ' — welcome email failed: ' . $sent['error'];
                        } else {
                            $message .= ' — welcome email not sent (SMTP not configured).';
                        }
                    }
                }
                break;
            case 'invite_create':
                $email = trim((string) ($_POST['email'] ?? ''));
                $message = trim((string) ($_POST['message'] ?? ''));
                $invite = InviteService::create($email, $message, (int) $admin['id']);
                if (!$invite['ok']) {
                    $ok = false;
                    $message = $invite['error'];
                } else {
                    log_audit('invite_create', $email);
                    if ($invite['email_sent']) {
                        $message = "Invite sent to $email.";
                    } else {
                        $message = "Invite created for $email, but the email could not be sent (" . ($invite['error'] ?? 'SMTP not configured') . '). The link was shown below — copy and share it manually.';
                        $_SESSION['invite_link'] = $invite['link'];
                    }
                }
                break;
            case 'invite_resend':
                $id = (int) ($_POST['id'] ?? 0);
                $res = InviteService::resend($id);
                if (!$res['ok']) {
                    $ok = false;
                    $message = $res['error'];
                } else {
                    log_audit('invite_resend', $res['email']);
                    if ($res['email_sent']) {
                        $message = "Invite re-sent to {$res['email']}.";
                    } else {
                        $message = "New invite link generated for {$res['email']}, but the email could not be sent (" . ($res['error'] ?? 'SMTP not configured') . '). The link was shown below.';
                        $_SESSION['invite_link'] = $res['link'];
                    }
                }
                break;
            case 'invite_revoke':
                $id = (int) ($_POST['id'] ?? 0);
                $inv = InviteService::row($id);
                InviteService::revoke($id);
                log_audit('invite_revoke', $inv['email'] ?? ('invite#' . $id));
                $message = 'Invite revoked.';
                break;
            case 'smtp_test':
                $to = trim((string) ($_POST['email'] ?? ''));
                if ($to === '') {
                    $to = (string) ($admin['email'] ?? '');
                }
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    $ok = false;
                    $message = 'A valid recipient email is required for the test.';
                } else {
                    $site = (string) (config_get('site_name', 'LVChat') ?: 'LVChat');
                    $sent = Mailer::send($to, 'SMTP test from ' . $site, "This is a test email. Your SMTP settings are working correctly.");
                    $message = $sent['ok']
                        ? "Test email sent to $to."
                        : 'SMTP test failed: ' . $sent['error'];
                }
                break;
            case 'motd_save':
                config_set('motd', (string) ($_POST['motd'] ?? ''));
                log_audit('motd_save');
                $message = 'MOTD saved.';
                break;
            case 'settings_save':
                // The gateway reads ws_ip/ws_port only at boot. If these change
                // here without a restart, the daemon keeps the old port while
                // every browser is told the new one — refused, silent polling
                // fallback, phantom 0 connections. Capture the old values so we
                // can restart the daemon when the bind actually changed.
                $oldWsPort = (int) (config_get('ws_port', '8080') ?? 8080);
                $oldWsIp = (string) (config_get('ws_ip', '0.0.0.0') ?? '0.0.0.0');
                $oldWsCert = (string) (config_get('ws_ssl_cert', '') ?? '');
                $oldWsKey = (string) (config_get('ws_ssl_key', '') ?? '');
                config_set('site_name', trim((string) ($_POST['site_name'] ?? 'LVChat')));
                config_set('site_tagline', trim((string) ($_POST['site_tagline'] ?? 'Discord-style web chat')));
                config_set('logo_url', trim((string) ($_POST['logo_url'] ?? '')));
                config_set('registration_enabled', ($_POST['registration_enabled'] ?? '0') === '1' ? '1' : '0');
                config_set('registration_requires_approval', ($_POST['registration_requires_approval'] ?? '0') === '1' ? '1' : '0');
                config_set('registration_rate_limit', (string) max(0, (int) ($_POST['registration_rate_limit'] ?? 20)));
                config_set('spamfilter_enabled', ($_POST['spamfilter_enabled'] ?? '0') === '1' ? '1' : '0');
                config_set('uploads_enabled', ($_POST['uploads_enabled'] ?? '0') === '1' ? '1' : '0');
                config_set('reactions_enabled', ($_POST['reactions_enabled'] ?? '0') === '1' ? '1' : '0');
                config_set('gifs_enabled', ($_POST['gifs_enabled'] ?? '0') === '1' ? '1' : '0');
                config_set('giphy_api_key', trim((string) ($_POST['giphy_api_key'] ?? '')));
                config_set('webhooks_enabled', ($_POST['webhooks_enabled'] ?? '0') === '1' ? '1' : '0');
                config_set('chat_logging_enabled', ($_POST['chat_logging_enabled'] ?? '0') === '1' ? '1' : '0');
                config_set('max_channels_per_user', (string) max(1, (int) ($_POST['max_channels_per_user'] ?? 100)));
                config_set('presence_throttle', (string) max(5, (int) ($_POST['presence_throttle'] ?? 30)));
                config_set('poll_interval', (string) max(1, (int) ($_POST['poll_interval'] ?? 2)));
                config_set('realtime', in_array(($_POST['realtime'] ?? 'poll'), ['poll', 'sse', 'ws'], true) ? (string) $_POST['realtime'] : 'poll');
                config_set('realtime_force', ($_POST['realtime_force'] ?? '0') === '1' ? '1' : '0');
                $wsIp = trim((string) ($_POST['ws_ip'] ?? '0.0.0.0'));
                if ($wsIp !== '' && strtolower($wsIp) !== 'localhost' && !filter_var($wsIp, FILTER_VALIDATE_IP)) {
                    $wsIp = '0.0.0.0';
                }
                config_set('ws_ip', $wsIp === '' ? '0.0.0.0' : $wsIp);
                config_set('ws_port', (string) max(1, min(65535, (int) ($_POST['ws_port'] ?? 8080))));
                config_set('ws_ssl_cert', trim((string) ($_POST['ws_ssl_cert'] ?? '')));
                config_set('ws_ssl_key', trim((string) ($_POST['ws_ssl_key'] ?? '')));
                $tz = trim((string) ($_POST['timezone'] ?? 'UTC'));
                if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
                    $tz = 'UTC';
                }
                config_set('timezone', $tz);
                config_set('smtp_enabled', ($_POST['smtp_enabled'] ?? '0') === '1' ? '1' : '0');
                // The SMTP fields are disabled (and therefore not submitted)
                // whenever SMTP is off, so fall back to the stored values to
                // avoid wiping the configuration on an unrelated save.
                config_set('smtp_host', trim((string) ($_POST['smtp_host'] ?? config_get('smtp_host', ''))));
                config_set('smtp_port', (string) max(1, (int) ($_POST['smtp_port'] ?? config_get('smtp_port', '587'))));
                $smtpEnc = (string) ($_POST['smtp_encryption'] ?? config_get('smtp_encryption', 'tls'));
                config_set('smtp_encryption', in_array($smtpEnc, ['none', 'ssl', 'tls'], true) ? $smtpEnc : 'tls');
                config_set('smtp_username', trim((string) ($_POST['smtp_username'] ?? config_get('smtp_username', ''))));
                $smtpPass = (string) ($_POST['smtp_password'] ?? '');
                if ($smtpPass !== '') {
                    config_set('smtp_password', $smtpPass);
                }
                config_set('smtp_from_email', trim((string) ($_POST['smtp_from_email'] ?? config_get('smtp_from_email', ''))));
                config_set('smtp_from_name', trim((string) ($_POST['smtp_from_name'] ?? config_get('smtp_from_name', ''))));
                config_set('mfa_require_admin', ($_POST['mfa_require_admin'] ?? '0') === '1' ? '1' : '0');
                config_set('mfa_require_staff', ($_POST['mfa_require_staff'] ?? '0') === '1' ? '1' : '0');
                config_set('mfa_require_user', ($_POST['mfa_require_user'] ?? '0') === '1' ? '1' : '0');
                // Desktop app download links + versions (Admin → Settings →
                // Desktop apps & downloads). Empty URLs just hide that button.
                config_set('download_update_url', trim((string) ($_POST['download_update_url'] ?? '')));
                foreach (self::DOWNLOAD_APPS as $dlApp) {
                    foreach (self::DOWNLOAD_PLATFORMS as $dlPlat) {
                        config_set("download_{$dlApp}_{$dlPlat}_url", trim((string) ($_POST["download_{$dlApp}_{$dlPlat}_url"] ?? '')));
                        config_set("download_{$dlApp}_{$dlPlat}_version", trim((string) ($_POST["download_{$dlApp}_{$dlPlat}_version"] ?? '')));
                    }
                }
                // Update feed (Admin → Settings → Updates). Enabling clears the
                // cached manifest so the next page view re-fetches from upstream.
                config_set('updater_enabled', ($_POST['updater_enabled'] ?? '0') === '1' ? '1' : '0');
                config_set('updater_url', rtrim(trim((string) ($_POST['updater_url'] ?? '')), '/'));
                @unlink(UpdaterService::manifestPath());
                // Allowed web-messenger origins (CORS). Normalize: split on
                // commas, trim, drop trailing slashes, de-dupe case-insensitively.
                // Only http(s) origins with a host are kept; the built-in
                // loopback origins and `null` are always allowed by bootstrap.
                $origins = [];
                foreach (explode(',', (string) ($_POST['app_origins'] ?? '')) as $o) {
                    $o = rtrim(trim($o), '/');
                    if ($o === '' || preg_match('#^https?://[a-z0-9.\-\[\]:]+$#i', $o) !== 1) {
                        continue;
                    }
                    if (!in_array(strtolower($o), array_map('strtolower', $origins), true)) {
                        $origins[] = $o;
                    }
                }
                if ($origins === []) {
                    // Empty state: remove the key so a server-set CHAT_CORS_ORIGINS
                    // env fallback (bootstrap) still applies if there is one.
                    Database::query('DELETE FROM server_config WHERE key = ?', ['app_origins']);
                } else {
                    config_set('app_origins', implode(', ', $origins));
                }
                log_audit('settings_save');
                $message = 'Settings saved.';
                $newWsPort = (int) (config_get('ws_port', '8080') ?? 8080);
                $newWsIp = (string) (config_get('ws_ip', '0.0.0.0') ?? '0.0.0.0');
                $newWsCert = (string) (config_get('ws_ssl_cert', '') ?? '');
                $newWsKey = (string) (config_get('ws_ssl_key', '') ?? '');
                if (($newWsPort !== $oldWsPort || $newWsIp !== $oldWsIp || $newWsCert !== $oldWsCert || $newWsKey !== $oldWsKey)
                    && (string) (config_get('realtime', 'poll') ?? 'poll') === 'ws') {
                    // Restart the daemon so it re-reads the new bind address.
                    // Without this the running daemon keeps the old port and
                    // every client silently falls back to polling.
                    $status = Realtime::daemonStatus();
                    if (!empty($status['running'])) {
                        $cmd = escapeshellarg(self::cliPhp()) . ' ' . escapeshellarg(ROOT . '/bin/ws-server.php') . ' restart -d';
                        [$code, $output] = CommandRunner::run($cmd, 20);
                        log_audit('ws_daemon_restart', '', trim($output) !== '' ? trim($output) : null);
                        $message = $code === 0
                            ? 'Settings saved. Gateway restarted to apply the new bind address/port.'
                            : 'Settings saved. Gateway restart FAILED: ' . trim($output);
                    } else {
                        $message = 'Settings saved. The gateway is not running — start it to apply the new bind address/port.';
                    }
                }
                break;
            case 'theme_save':
                $current = ThemeService::globalTheme();
                $image = (string) ($_POST['chat_bg_image'] ?? '');
                if (ThemeService::localPath($image) === '' && !empty($current['overrides']['chat_bg_image'])) {
                    $image = (string) $current['overrides']['chat_bg_image'];
                }
                ThemeService::saveGlobal([
                    'preset' => (string) ($_POST['preset'] ?? ''),
                    'mode' => (string) ($_POST['mode'] ?? ''),
                    'overrides' => [
                        'accent' => (string) ($_POST['accent'] ?? ''),
                        'sidebar' => (string) ($_POST['sidebar'] ?? ''),
                        'font' => (string) ($_POST['font'] ?? ''),
                        'chat_bg_color' => (string) ($_POST['chat_bg_color'] ?? ''),
                        'chat_bg_image' => $image,
                        'chat_bg_fit' => (string) ($_POST['chat_bg_fit'] ?? ''),
                        'chat_bg_overlay' => (int) ($_POST['chat_bg_overlay'] ?? -1),
                    ],
                ]);
                config_set('theme_user_customization', ($_POST['theme_user_customization'] ?? '0') === '1' ? '1' : '0');
                log_audit('theme_save');
                $message = 'Theme saved.';
                break;
            case 'theme_bg_upload':
                if (!isset($_FILES['file']) || !UploadService::isImageUpload($_FILES['file'])) {
                    $ok = false;
                    $message = 'Choose an image file first.';
                    break;
                }
                $stored = UploadService::store($_FILES['file'], 'theme');
                if (!$stored['ok']) {
                    $ok = false;
                    $message = $stored['error'];
                    break;
                }
                $current = ThemeService::globalTheme();
                if (!empty($current['overrides']['chat_bg_image'])) {
                    UploadService::remove((string) $current['overrides']['chat_bg_image']);
                }
                $current['overrides']['chat_bg_image'] = $stored['url'];
                ThemeService::saveGlobal($current);
                log_audit('theme_bg_upload');
                $message = 'Background uploaded.';
                break;
            case 'theme_bg_remove':
                $current = ThemeService::globalTheme();
                if (!empty($current['overrides']['chat_bg_image'])) {
                    UploadService::remove((string) $current['overrides']['chat_bg_image']);
                }
                $current['overrides']['chat_bg_image'] = '';
                ThemeService::saveGlobal($current);
                log_audit('theme_bg_remove');
                $message = 'Background removed.';
                break;
            case 'theme_reset':
                $current = ThemeService::globalTheme();
                if (!empty($current['overrides']['chat_bg_image'])) {
                    UploadService::remove((string) $current['overrides']['chat_bg_image']);
                }
                config_set('theme', '');
                log_audit('theme_reset');
                $message = 'Theme reset to the default.';
                break;
            case 'user_approve':
                $id = (int) ($_POST['id'] ?? 0);
                ModerationService::setStatus($id, 'active', null, $admin, true, 'approve');
                $message = 'Account approved.';
                break;
            case 'user_activate':
                $id = (int) ($_POST['id'] ?? 0);
                ModerationService::setStatus($id, 'active', trim((string) ($_POST['reason'] ?? '')) ?: null, $admin);
                $message = 'Account activated.';
                break;
            case 'user_pending':
                $id = (int) ($_POST['id'] ?? 0);
                ModerationService::setStatus($id, 'pending', trim((string) ($_POST['reason'] ?? '')) ?: null, $admin);
                $message = 'Account set to pending approval.';
                break;
            case 'user_suspend':
                $id = (int) ($_POST['id'] ?? 0);
                $reason = trim((string) ($_POST['reason'] ?? ''));
                if ($reason === '') {
                    $ok = false;
                    $message = 'A reason is required when suspending an account.';
                } else {
                    ModerationService::setStatus($id, 'suspended', $reason, $admin);
                    $message = 'Account suspended.';
                }
                break;
            case 'user_note':
                $id = (int) ($_POST['id'] ?? 0);
                $reason = trim((string) ($_POST['reason'] ?? ''));
                if ($reason === '') {
                    $ok = false;
                    $message = 'A note is required.';
                } else {
                    ModerationService::note($id, $admin, 'note', $reason);
                    log_audit('user_note', 'user#' . $id);
                    $message = 'Note added.';
                }
                break;
            case 'report_status':
                $id = (int) ($_POST['id'] ?? 0);
                $status = (string) ($_POST['status'] ?? '');
                $resolution = trim((string) ($_POST['resolution'] ?? ''));
                $report = Database::row('SELECT * FROM reports WHERE id = ?', [$id]);
                if (!$report) {
                    $ok = false;
                    $message = 'Report not found.';
                } elseif (!in_array($status, ['investigated', 'resolved', 'dismissed'], true)) {
                    $ok = false;
                    $message = 'Invalid report status.';
                } else {
                    Database::query(
                        'UPDATE reports SET status = ?, handled_by = ?, handled_at = datetime("now"), resolution = ? WHERE id = ?',
                        [$status, (int) $admin['id'], $resolution, $id]
                    );
                    if ((int) $report['sender_user_id'] > 0) {
                        ModerationService::note((int) $report['sender_user_id'], $admin, 'report', 'Report #' . $id . ' (' . $report['reason'] . ') — ' . $status . ($resolution !== '' ? ': ' . $resolution : ''));
                    }
                    log_audit('report_status', 'report#' . $id, "$status / $resolution");
                    $message = 'Report updated.';
                }
                break;
            case 'legal_save':
                LegalService::save('terms', (string) ($_POST['terms'] ?? ''));
                LegalService::save('privacy', (string) ($_POST['privacy'] ?? ''));
                log_audit('legal_save');
                $message = 'Legal pages saved.';
                break;
            case 'legal_reset':
                $which = ($_POST['which'] ?? '') === 'privacy' ? 'privacy' : 'terms';
                LegalService::save($which, LegalService::boilerplate($which));
                log_audit('legal_reset', $which);
                $message = ucfirst($which) . ' reset to the US/Nevada boilerplate.';
                break;
            case 'openclaw_create':
                $r = OpenClawBotService::create(
                    (string) ($_POST['name'] ?? ''),
                    (string) ($_POST['system_prompt'] ?? ''),
                    (string) ($_POST['avatar'] ?? ''),
                    $admin
                );
                if (!$r['ok']) {
                    $ok = false;
                    $message = $r['error'];
                } else {
                    $_SESSION['openclaw_api_key'] = $r['api_key'];
                    $message = 'OpenClaw bot created.';
                }
                break;
            case 'openclaw_delete':
                $r = OpenClawBotService::delete((int) ($_POST['id'] ?? 0), $admin);
                if ($r !== true) {
                    $ok = false;
                    $message = (string) $r;
                } else {
                    $message = 'OpenClaw bot deleted.';
                }
                break;
            case 'openclaw_toggle':
                OpenClawBotService::toggle((int) ($_POST['id'] ?? 0));
                $message = 'OpenClaw bot toggled.';
                break;
            case 'openclaw_assign_channel':
                OpenClawBotService::assignChannel(
                    (int) ($_POST['bot_id'] ?? 0),
                    (int) ($_POST['channel_id'] ?? 0),
                    (string) ($_POST['respond_mode'] ?? 'mentions')
                );
                $message = 'Bot assigned to channel.';
                break;
            case 'openclaw_remove_channel':
                OpenClawBotService::removeChannel(
                    (int) ($_POST['bot_id'] ?? 0),
                    (int) ($_POST['channel_id'] ?? 0)
                );
                $message = 'Bot removed from channel.';
                break;
            case 'openclaw_pm_grant':
                $username = trim((string) ($_POST['username'] ?? ''));
                $targetUser = Database::row('SELECT id FROM users WHERE username = ? COLLATE NOCASE', [$username]);
                if (!$targetUser) {
                    $ok = false;
                    $message = 'User not found.';
                } else {
                    OpenClawBotService::grantPmAccess((int) ($_POST['bot_id'] ?? 0), (int) $targetUser['id']);
                    $message = 'PM access granted.';
                }
                break;
            case 'openclaw_pm_revoke':
                OpenClawBotService::revokePmAccess(
                    (int) ($_POST['bot_id'] ?? 0),
                    (int) ($_POST['user_id'] ?? 0)
                );
                $message = 'PM access revoked.';
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

    /** GET /admin/ws/status — is the realtime gateway up? (Admin → Settings UI.) */
    public static function wsStatus(): void
    {
        Auth::requireAdmin();
        json_out(array_merge(['ok' => true], Realtime::daemonStatus()));
    }

    /** POST /admin/ws/reconnect — force every open chat tab to reload onto the current gateway config. */
    public static function wsReconnect(): void
    {
        Auth::requireAdmin();
        Csrf::verify();
        Realtime::reconnectClients();
        log_audit('ws_reconnect_clients');
        json_out(array_merge(['ok' => true, 'reconnect' => true], Realtime::daemonStatus()));
    }

    /** POST /admin/ws/control — start/stop/restart the realtime gateway daemon. */
    public static function wsControl(): void
    {
        Auth::requireAdmin();
        Csrf::verify();
        $action = (string) ($_POST['action'] ?? '');
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            json_out(['error' => 'Unknown action.'], 400);
        }
        $cli = escapeshellarg(self::cliPhp()) . ' ' . escapeshellarg(ROOT . '/bin/ws-server.php');
        $wsPort = (int) (config_get('ws_port', '8080') ?? 8080);
        $pushParts = parse_url((string) Realtime::pushUrl());
        $pushPort = (int) ($pushParts['port'] ?? 9001);
        $notes = [];

        // Workerman's stop/restart signals the master PID read from the pid file.
        // A stale pid file or an orphaned instance makes that a no-op while the
        // old daemon keeps the ports — so also sweep the daemon ports directly.
        $killStale = function () use ($wsPort, $pushPort, &$notes): void {
            foreach ([$wsPort, $pushPort] as $p) {
                $pids = self::gatewayPidsOnPort($p);
                if (!$pids) {
                    continue;
                }
                foreach ($pids as $pid) {
                    CommandRunner::run('kill -TERM ' . (int) $pid . ' 2>/dev/null', 5);
                }
                usleep(500000);
                foreach ($pids as $pid) {
                    CommandRunner::run('kill -9 ' . (int) $pid . ' 2>/dev/null', 5);
                }
                $notes[] = 'Force-stopped stale gateway process(es) on port ' . $p . ' (pid ' . implode(',', $pids) . ').';
            }
        };

        if ($action === 'stop') {
            CommandRunner::run($cli . ' stop', 20);
            $killStale();
            log_audit('ws_daemon_stop', '', $notes ? implode(' ', $notes) : null);
            json_out(['ok' => true, 'action' => 'stop', 'output' => $notes ? implode("\n", $notes) : 'Gateway stopped.', 'status' => Realtime::daemonStatus()]);
        }

        // start / restart: bring down anything stale, then start fresh.
        if ($action === 'restart') {
            CommandRunner::run($cli . ' stop', 20);
        }
        $killStale();
        [$code, $output] = CommandRunner::run($cli . ' start -d', 20);
        if ($code !== 0 && self::gatewayPidsOnPort($wsPort)) {
            // Something reclaimed the port mid-start — clear it and retry once.
            $killStale();
            [$code, $output] = CommandRunner::run($cli . ' start -d', 20);
        }
        log_audit('ws_daemon_' . $action, '', trim($output) !== '' ? trim($output) : null);
        json_out([
            'ok' => $code === 0,
            'action' => $action,
            'output' => ($notes ? implode("\n", $notes) . "\n" : '') . $output,
            'status' => Realtime::daemonStatus(),
        ]);
    }

    /**
     * PIDs of LVChat gateway processes (cmdline contains ws-server.php) that are
     * currently listening on a TCP port. Reads /proc on Linux; empty elsewhere.
     */
    private static function gatewayPidsOnPort(int $port): array
    {
        $hex = strtoupper(dechex($port));
        $inodes = [];
        foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $f) {
            $lines = @file($f);
            if (!$lines) {
                continue;
            }
            foreach ($lines as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) < 10 || ($parts[3] ?? '') !== '0A') {
                    continue; // not a LISTEN socket
                }
                $local = (string) ($parts[1] ?? '');
                $colon = strrpos($local, ':');
                if ($colon !== false && strtoupper(substr($local, $colon + 1)) === $hex) {
                    $inodes[(int) ($parts[9] ?? 0)] = true;
                }
            }
        }
        if (!$inodes) {
            return [];
        }
        $pids = [];
        foreach (glob('/proc/[0-9]*') ?: [] as $dir) {
            $pid = (int) basename($dir);
            $cmd = @file_get_contents($dir . '/cmdline');
            // Workerman replaces the worker's cmdline with its process title
            // ("WorkerMan: worker process …"); the master keeps the start file
            // (…/ws-server.php). Match both so the worker holding the push
            // socket is found too.
            if ($cmd === false
                || (stripos($cmd, 'ws-server.php') === false && stripos($cmd, 'WorkerMan') === false)) {
                continue;
            }
            foreach (@glob($dir . '/fd/*') ?: [] as $fd) {
                $target = @readlink($fd);
                if ($target !== false && preg_match('/socket:\[(\d+)\]/', $target, $m) && isset($inodes[(int) $m[1]])) {
                    $pids[$pid] = true;
                    break;
                }
            }
        }
        return array_keys($pids);
    }

    /**
     * The PHP CLI binary used to run scripts from a web request. Under
     * php-fpm, PHP_BINARY points at the FPM daemon (e.g. php-fpm8.3), which
     * prints its own usage instead of running a script — use the CLI binary
     * next to it, or `php` resolved via PATH.
     */
    private static function cliPhp(): string
    {
        if (PHP_SAPI === 'cli') {
            return PHP_BINARY;
        }
        $cli = PHP_BINDIR . '/php';
        return is_executable($cli) ? $cli : 'php';
    }

    /** POST /admin/deploy — run bin/deploy.sh from the web UI. */
    public static function deploy(): void
    {
        Auth::requireAdmin();
        Csrf::verify();
        $cmd = 'bash ' . escapeshellarg(ROOT . '/bin/deploy.sh');
        [$code, $output] = CommandRunner::run($cmd, 180);
        log_audit('deploy_script', '', trim($output) !== '' ? trim($output) : null);
        json_out(['ok' => $code === 0, 'exit_code' => $code, 'output' => $output]);
    }

    /**
     * POST /admin/deploy/stream — run bin/deploy.sh and stream its output to
     * the browser line-by-line (like a terminal), so the admin sees progress
     * live in the deploy modal. CSRF token accepted via POST field or query.
     * Degrades to popen/exec when proc_open is disabled by the host.
     */
    public static function deployStream(): void
    {
        Auth::requireAdmin();
        $sent = $_POST['csrf'] ?? ($_GET['csrf'] ?? '');
        if (!is_string($sent) || !hash_equals(Csrf::token(), $sent)) {
            http_response_code(419);
            exit('CSRF token mismatch');
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no'); // nginx: disable proxy buffering for streaming
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        echo "bash bin/deploy.sh\n";
        if (!CommandRunner::available()) {
            echo "\n[Shell execution is disabled on this server (proc_open/popen/exec all off in php.ini).\n";
            echo "Enable one of them, or run  bash bin/deploy.sh  over SSH.]\n";
            flush();
            log_audit('deploy_script', '', 'blocked: no shell functions available');
            exit;
        }
        echo "(via " . CommandRunner::backend() . ")\n\n";
        flush();

        $cmd = 'bash ' . escapeshellarg(ROOT . '/bin/deploy.sh');
        $code = CommandRunner::stream($cmd, 300);
        log_audit('deploy_script', '', 'streamed, exit=' . $code);
        exit;
    }
}
