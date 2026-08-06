<?php

declare(strict_types=1);

final class ChannelController
{
    /** GET /c/{slug} — shareable channel link with the login/auto-join flow. */
    public static function channelLink(array $params): void
    {
        $user = Auth::user();
        $channel = ChannelService::findBySlug((string) $params['slug']);
        if (!$channel) {
            render_view('errors/notfound', [], null);
        }
        if (!$user) {
            redirect('/login?next=' . rawurlencode('/c/' . rawurlencode($channel['slug'])));
        }
        $member = AccessService::member($channel['id'], (int) $user['id']);
        if ($member) {
            redirect('/app?channel=' . rawurlencode($channel['slug']));
        }
        $restriction = ModerationService::restriction($user);
        if ($restriction) {
            render_view('chat/denied', [
                'channel' => $channel,
                'reason' => $restriction,
                'user' => $user,
            ]);
        }
        $status = ChannelService::joinStatus($channel, $user, $_POST['key'] ?? null);
        if ($status['reason'] === 'need_key') {
            redirect('/app?join=' . rawurlencode($channel['slug']));
        }
        if (!$status['ok']) {
            render_view('chat/denied', [
                'channel' => $channel,
                'reason' => $status['reason'],
                'user' => $user,
            ]);
        }
        ChannelService::join($channel, $user);
        redirect('/app?channel=' . rawurlencode($channel['slug']));
    }

    /** GET /embed/{slug} — public embeddable page: gate on sign-in/register/guest, then open the channel. */
    public static function embed(array $params): void
    {
        $channel = ChannelService::findBySlug((string) $params['slug']);
        if (!$channel) {
            render_view('errors/notfound', [], null);
        }
        $user = Auth::user();
        if ($user) {
            // Signed in: reuse the share-link auto-join flow (the iframe follows the redirect).
            redirect('/c/' . rawurlencode($channel['slug']));
        }
        render_view('embed/landing', [
            'title' => 'Join ' . $channel['name'],
            'channel' => $channel,
            'next' => '/c/' . rawurlencode($channel['slug']),
            'error' => flash(),
        ]);
    }

    public static function joinForm(array $params): void
    {
        $user = Auth::require();
        $channel = ChannelService::findBySlug((string) $params['slug']);
        if (!$channel) {
            render_view('errors/notfound', [], null);
        }
        if (AccessService::member($channel['id'], (int) $user['id'])) {
            redirect('/app?channel=' . rawurlencode($channel['slug']));
        }
        render_view('chat/join', [
            'channel' => $channel,
            'user' => $user,
            'error' => flash(),
        ]);
    }

    public static function joinWithKey(array $params): void
    {
        $user = Auth::require();
        Csrf::verify();
        $channel = ChannelService::findBySlug((string) $params['slug']);
        if (!$channel) {
            render_view('errors/notfound', [], null);
        }
        $restriction = ModerationService::restriction($user);
        if ($restriction) {
            flash($restriction);
            redirect('/app');
        }
        $status = ChannelService::joinStatus($channel, $user, (string) ($_POST['key'] ?? ''));
        if (!$status['ok']) {
            flash($status['reason']);
            redirect('/c/' . rawurlencode($channel['slug']) . '/join');
        }
        ChannelService::join($channel, $user);
        redirect('/app?channel=' . rawurlencode($channel['slug']));
    }

    public static function create(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $restriction = ModerationService::restriction($user);
        if ($restriction) {
            json_out(['error' => $restriction], 403);
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $result = ChannelService::create($user, $name);
        if (is_string($result)) {
            json_out(['error' => $result], 400);
        }
        json_out(['ok' => true, 'redirect' => '/app?channel=' . rawurlencode($result['slug'])]);
    }

    public static function join(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $restriction = ModerationService::restriction($user);
        if ($restriction) {
            json_out(['error' => $restriction], 403);
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $key = $_POST['key'] ?? null;
        $channel = ChannelService::find($name);
        if (!$channel) {
            $created = ChannelService::create($user, $name);
            if (is_string($created)) {
                json_out(['error' => $created], 400);
            }
            ChannelService::join($created, $user);
            json_out(['ok' => true, 'redirect' => '/app?channel=' . rawurlencode($created['slug'])]);
        }
        $status = ChannelService::joinStatus($channel, $user, $key ? (string) $key : null);
        if (!$status['ok']) {
            json_out(['error' => $status['reason']], 403);
        }
        ChannelService::join($channel, $user);
        json_out(['ok' => true, 'redirect' => '/app?channel=' . rawurlencode($channel['slug'])]);
    }

    public static function part(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $channel = ChannelService::findBySlug((string) ($_POST['channel'] ?? ''));
        if (!$channel) {
            json_out(['error' => 'Channel not found.'], 404);
        }
        $reason = trim((string) ($_POST['reason'] ?? ''));
        ChannelService::part($channel, $user, $reason ?: null);
        json_out(['ok' => true, 'redirect' => '/app']);
    }

    /** POST /api/channel/notify — set the user's notification mode for a channel. */
    public static function notify(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $channel = ChannelService::findBySlug((string) ($_POST['channel'] ?? ''));
        if (!$channel) {
            json_out(['error' => 'Channel not found.'], 404);
        }
        if (!AccessService::member($channel['id'], $user)) {
            json_out(['error' => 'You are not a member of this channel.'], 403);
        }
        $mode = (string) ($_POST['mode'] ?? 'all');
        if (!in_array($mode, ['all', 'mentions', 'muted'], true)) {
            json_out(['error' => 'Invalid notification mode.'], 400);
        }
        ChannelService::setNotifyMode($channel['id'], $user, $mode);
        json_out(['ok' => true, 'mode' => $mode]);
    }

    /** POST /api/channel/delete — founder deletes their channel (history preserved). */
    public static function deleteChannel(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $channel = ChannelService::findBySlug((string) ($_POST['channel'] ?? ''));
        if (!$channel) {
            json_out(['error' => 'Channel not found.'], 404);
        }
        $r = ChannelService::delete($channel['id'], $user);
        if ($r !== true) {
            json_out(['error' => $r], 403);
        }
        json_out(['ok' => true, 'redirect' => '/app']);
    }

    public static function acceptInvite(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $slug = trim((string) ($_POST['channel'] ?? ''));
        $channel = ChannelService::findBySlug($slug);
        if (!$channel) {
            json_out(['error' => 'Channel not found.'], 404);
        }
        $invite = Database::row('SELECT * FROM invites WHERE channel_id = ? AND user_id = ?', [(int) $channel['id'], (int) $user['id']]);
        if (!$invite) {
            json_out(['error' => 'Invite not found.'], 404);
        }
        ChannelService::join($channel, $user);
        Database::query('DELETE FROM notifications WHERE kind = "invite" AND user_id = ? AND channel_id = ?', [(int) $user['id'], (int) $channel['id']]);
        json_out(['ok' => true, 'redirect' => '/app?channel=' . rawurlencode($channel['slug'])]);
    }

    public static function declineInvite(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $slug = trim((string) ($_POST['channel'] ?? ''));
        $channel = ChannelService::findBySlug($slug);
        if (!$channel) {
            json_out(['error' => 'Channel not found.'], 404);
        }
        Database::query('DELETE FROM invites WHERE channel_id = ? AND user_id = ?', [(int) $channel['id'], (int) $user['id']]);
        Database::query('DELETE FROM notifications WHERE kind = "invite" AND user_id = ? AND channel_id = ?', [(int) $user['id'], (int) $channel['id']]);
        json_out(['ok' => true]);
    }

    /** POST /api/channel/bg — channel owner sets the channel's chat background
     *  (upload an image and/or pick a colour; empty bg_color clears the colour). */
    public static function setBackground(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $channel = ChannelService::findBySlug((string) ($_POST['channel'] ?? ''));
        if (!$channel) {
            json_out(['error' => 'Channel not found.'], 404);
        }
        if ($user['role'] !== 'admin' && (int) $channel['owner_id'] !== (int) $user['id']) {
            json_out(['error' => 'Only the channel owner can set the background.'], 403);
        }

        $color = ThemeService::hex((string) ($_POST['bg_color'] ?? ''));
        $fit = in_array($_POST['bg_fit'] ?? '', ThemeService::CHAT_BG_FITS, true)
            ? (string) $_POST['bg_fit']
            : 'contain';
        $overlay = (int) ($_POST['bg_overlay'] ?? -1);
        $overlay = $overlay >= 0 && $overlay <= 100 ? $overlay : ThemeService::CHAT_BG_OVERLAY_DEFAULT;
        $fields = ['bg_color' => $color !== '' ? $color : null, 'bg_fit' => $fit, 'bg_overlay' => $overlay];

        $hasFile = isset($_FILES['file'])
            && !empty($_FILES['file']['tmp_name'])
            && is_uploaded_file((string) $_FILES['file']['tmp_name']);
        if ($hasFile) {
            $stored = UploadService::store($_FILES['file'], 'theme');
            if (!$stored['ok']) {
                json_out(['error' => $stored['error']], 400);
            }
            if (!empty($channel['bg_image'])) {
                UploadService::remove((string) $channel['bg_image']);
            }
            $fields['bg_image'] = $stored['url'];
        }

        ChannelService::update((string) $channel['id'], $fields);
        log_audit('channel_bg', $channel['name'], implode(',', array_filter($fields)));
        json_out([
            'ok' => true,
            'bg_color' => $fields['bg_color'] ?? null,
            'bg_fit' => $fit,
            'bg_overlay' => $overlay,
            'bg_image' => $fields['bg_image'] ?? (isset($channel['bg_image']) ? (string) $channel['bg_image'] : null),
        ]);
    }

    /** POST /api/channel/bg/remove — channel owner clears the channel background. */
    public static function removeBackground(): void
    {
        $user = Auth::require();
        Csrf::verify();
        $channel = ChannelService::findBySlug((string) ($_POST['channel'] ?? ''));
        if (!$channel) {
            json_out(['error' => 'Channel not found.'], 404);
        }
        if ($user['role'] !== 'admin' && (int) $channel['owner_id'] !== (int) $user['id']) {
            json_out(['error' => 'Only the channel owner can remove the background.'], 403);
        }
        if (!empty($channel['bg_image'])) {
            UploadService::remove((string) $channel['bg_image']);
        }
        ChannelService::update((string) $channel['id'], ['bg_image' => null, 'bg_color' => null, 'bg_fit' => 'contain', 'bg_overlay' => ThemeService::CHAT_BG_OVERLAY_DEFAULT]);
        log_audit('channel_bg_remove', $channel['name']);
        json_out(['ok' => true]);
    }
}
