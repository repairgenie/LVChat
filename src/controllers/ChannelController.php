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
}
