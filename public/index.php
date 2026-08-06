<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$router = new Router();

// Auth
$router->get('/login', [AuthController::class, 'loginForm']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/login/mfa', [AuthController::class, 'mfaForm']);
$router->post('/login/mfa', [AuthController::class, 'mfaVerify']);
$router->get('/login/mfa/setup', [AuthController::class, 'mfaSetupForm']);
$router->post('/login/mfa/setup', [AuthController::class, 'mfaSetupVerify']);
$router->get('/register', [AuthController::class, 'registerForm']);
$router->post('/register', [AuthController::class, 'register']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->post('/guest', [AuthController::class, 'guestLogin']);
$router->get('/forgot-password', [AuthController::class, 'forgotPasswordForm']);
$router->post('/forgot-password', [AuthController::class, 'forgotPassword']);
$router->get('/reset-password/{token}', [AuthController::class, 'resetPasswordForm']);
$router->post('/reset-password/{token}', [AuthController::class, 'resetPassword']);
$router->get('/magic-link', [AuthController::class, 'magicLoginForm']);
$router->post('/magic-link', [AuthController::class, 'magicLoginRequest']);
$router->get('/magic/{token}', [AuthController::class, 'magicLogin']);

// Main app + shareable channel links
$router->get('/', [BrowseController::class, 'index']);
$router->get('/app', [ChatController::class, 'app']);
$router->get('/browse', [BrowseController::class, 'browse']);
$router->get('/c/{slug}', [ChannelController::class, 'channelLink']);
$router->get('/c/{slug}/join', [ChannelController::class, 'joinForm']);
$router->post('/c/{slug}/join', [ChannelController::class, 'joinWithKey']);
$router->get('/embed/{slug}', [ChannelController::class, 'embed']);

// Chat API
$router->get('/api/version', [ChatController::class, 'version']);
$router->post('/api/send', [ChatController::class, 'send']);
$router->post('/api/upload', [ChatController::class, 'upload']);
$router->post('/api/command', [ChatController::class, 'command']);
$router->get('/api/poll', [ChatController::class, 'poll']);
$router->get('/api/stream', [ChatController::class, 'stream']);
$router->get('/api/ws/ticket', [ChatController::class, 'wsTicket']);
$router->post('/api/rt/report', [ChatController::class, 'reportTransport']);
$router->get('/api/notifications', [ChatController::class, 'notifications']);
$router->get('/api/browse', [ChatController::class, 'browseData']);
$router->post('/api/notifications/read', [ChatController::class, 'readNotifications']);
$router->post('/api/notifications/dismiss', [ChatController::class, 'dismissNotification']);
$router->post('/api/channels', [ChannelController::class, 'create']);
$router->post('/api/join', [ChannelController::class, 'join']);
$router->post('/api/part', [ChannelController::class, 'part']);
$router->post('/api/channel/notify', [ChannelController::class, 'notify']);
$router->post('/api/channel/delete', [ChannelController::class, 'deleteChannel']);
$router->post('/api/channel/invite/accept', [ChannelController::class, 'acceptInvite']);
$router->post('/api/channel/invite/decline', [ChannelController::class, 'declineInvite']);
$router->post('/api/message/delete', [ChatController::class, 'deleteMessage']);
$router->post('/api/message/edit', [ChatController::class, 'editMessage']);
$router->post('/api/message/reaction', [ChatController::class, 'reaction']);
$router->post('/api/report', [ChatController::class, 'report']);
$router->get('/api/history', [ChatController::class, 'historyApi']);
$router->get('/api/search', [ChatController::class, 'search']);
$router->get('/api/gifs', [ChatController::class, 'gifSearch']);

// Users / profiles
$router->get('/u/{username}', [UserController::class, 'profile']);
$router->get('/api/online', [UserController::class, 'online']);
$router->post('/api/password', [UserController::class, 'changePassword']);
$router->post('/api/profile', [UserController::class, 'updateProfile']);
$router->post('/api/avatar', [UserController::class, 'uploadAvatar']);
$router->post('/api/avatar/remove', [UserController::class, 'removeAvatar']);
$router->post('/api/mfa/begin', [UserController::class, 'mfaBegin']);
$router->post('/api/mfa/enable', [UserController::class, 'mfaEnable']);
$router->post('/api/mfa/disable', [UserController::class, 'mfaDisable']);

// Friends
$router->get('/api/friends', [FriendController::class, 'list']);
$router->get('/api/friend/status', [FriendController::class, 'status']);
$router->post('/api/friend/request', [FriendController::class, 'send']);
$router->post('/api/friend/accept', [FriendController::class, 'accept']);
$router->post('/api/friend/decline', [FriendController::class, 'decline']);
$router->post('/api/friend/remove', [FriendController::class, 'remove']);
$router->post('/api/friend/cancel', [FriendController::class, 'cancel']);
$router->post('/api/friend/block', [FriendController::class, 'block']);
$router->post('/api/friend/unblock', [FriendController::class, 'unblock']);

// Sound alerts (user settings)
$router->post('/api/sound/prefs', [SoundController::class, 'prefs']);
$router->post('/api/sound/override', [SoundController::class, 'setOverride']);
$router->post('/api/sound/override/remove', [SoundController::class, 'removeOverride']);

// Push notifications (user settings)
$router->post('/api/push/subscribe', [PushController::class, 'subscribe']);
$router->post('/api/push/unsubscribe', [PushController::class, 'unsubscribe']);
$router->post('/api/push/prefs', [PushController::class, 'prefs']);
$router->post('/api/push/mute', [PushController::class, 'mute']);
$router->post('/api/push/unmute', [PushController::class, 'unmute']);

// Theme settings (per-user) + live CSS preview
$router->get('/api/theme/css', [ThemeController::class, 'css']);
$router->post('/api/theme', [ThemeController::class, 'save']);
$router->post('/api/theme/bg', [ThemeController::class, 'uploadBg']);
$router->post('/api/theme/bg/remove', [ThemeController::class, 'removeBg']);
$router->post('/api/channel/bg', [ChannelController::class, 'setBackground']);
$router->post('/api/channel/bg/remove', [ChannelController::class, 'removeBackground']);

// Legal pages (public)
$router->get('/terms', [LegalController::class, 'terms']);
$router->get('/privacy', [LegalController::class, 'privacy']);
$router->get('/api/legal/terms', [LegalController::class, 'apiTerms']);
$router->get('/api/legal/privacy', [LegalController::class, 'apiPrivacy']);

// Progressive Web App manifest (installed app metadata)
$router->get('/manifest', [PwaController::class, 'manifest']);

// Support tickets (registered users + staff)
$router->get('/support', [SupportController::class, 'index']);
$router->post('/support', [SupportController::class, 'create']);
$router->get('/support/{id}', [SupportController::class, 'show']);
$router->post('/support/{id}/reply', [SupportController::class, 'reply']);
$router->post('/support/{id}/close', [SupportController::class, 'close']);
$router->post('/support/{id}/reopen', [SupportController::class, 'reopen']);

// Admin dashboard
$router->get('/admin', [AdminController::class, 'overview']);
$router->get('/admin/analytics', [AdminController::class, 'analytics']);
$router->get('/admin/users', [AdminController::class, 'users']);
$router->get('/admin/users/{id}', [AdminController::class, 'userShow']);
$router->get('/admin/moderation', [AdminController::class, 'moderation']);
$router->get('/admin/reports', [AdminController::class, 'reports']);
$router->get('/admin/support', [AdminController::class, 'support']);
$router->get('/admin/support/{id}', [SupportController::class, 'show']);
$router->post('/admin/support/create', [SupportController::class, 'staffCreate']);
$router->post('/admin/support/{id}/assign', [SupportController::class, 'assign']);
$router->get('/admin/legal', [AdminController::class, 'legal']);
$router->get('/admin/channels', [AdminController::class, 'channels']);
$router->get('/admin/bans', [AdminController::class, 'bans']);
$router->get('/admin/spamfilters', [AdminController::class, 'spamfilters']);
$router->get('/admin/badwords', [AdminController::class, 'badwords']);
$router->get('/admin/roles', [AdminController::class, 'roles']);
$router->get('/admin/opers', [AdminController::class, 'opers']);
$router->get('/admin/operclasses', [AdminController::class, 'operclasses']);
$router->get('/admin/motd', [AdminController::class, 'motd']);
$router->get('/admin/sounds', [AdminController::class, 'sounds']);
$router->get('/admin/logs', [AdminController::class, 'logs']);
$router->get('/admin/logs/day', [AdminController::class, 'logDay']);
$router->get('/admin/logs/export', [AdminController::class, 'logDayExport']);
$router->get('/admin/settings', [AdminController::class, 'settings']);
$router->get('/admin/theme', [AdminController::class, 'theme']);
$router->get('/admin/invites', [AdminController::class, 'invites']);
$router->get('/admin/openclaw', [AdminController::class, 'openclaw']);
$router->post('/admin/action', [AdminController::class, 'action']);
$router->get('/admin/ws/status', [AdminController::class, 'wsStatus']);
$router->post('/admin/ws/reconnect', [AdminController::class, 'wsReconnect']);
$router->post('/admin/ws/control', [AdminController::class, 'wsControl']);
$router->post('/admin/deploy', [AdminController::class, 'deploy']);
$router->post('/admin/deploy/stream', [AdminController::class, 'deployStream']);

// Incoming webhooks (public, token-authenticated) + admin management
$router->post('/api/webhooks/{token}', [WebhookController::class, 'post']);
$router->get('/admin/webhooks', [WebhookController::class, 'admin']);
$router->post('/admin/webhooks/action', [WebhookController::class, 'action']);

$router->get('/api/openclaw/channels', [OpenClawController::class, 'channels']);
$router->get('/api/openclaw/messages', [OpenClawController::class, 'messages']);
$router->get('/api/openclaw/pms', [OpenClawController::class, 'pms']);
$router->post('/api/openclaw/send', [OpenClawController::class, 'send']);
$router->post('/api/openclaw/pm', [OpenClawController::class, 'pm']);

$router->dispatch();
