<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$router = new Router();

// Auth
$router->get('/login', [AuthController::class, 'loginForm']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'registerForm']);
$router->post('/register', [AuthController::class, 'register']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->post('/guest', [AuthController::class, 'guestLogin']);

// Main app + shareable channel links
$router->get('/', [BrowseController::class, 'index']);
$router->get('/app', [ChatController::class, 'app']);
$router->get('/browse', [BrowseController::class, 'browse']);
$router->get('/c/{slug}', [ChannelController::class, 'channelLink']);
$router->get('/c/{slug}/join', [ChannelController::class, 'joinForm']);
$router->post('/c/{slug}/join', [ChannelController::class, 'joinWithKey']);

// Chat API
$router->get('/api/version', [ChatController::class, 'version']);
$router->post('/api/send', [ChatController::class, 'send']);
$router->post('/api/command', [ChatController::class, 'command']);
$router->get('/api/poll', [ChatController::class, 'poll']);
$router->get('/api/notifications', [ChatController::class, 'notifications']);
$router->post('/api/notifications/read', [ChatController::class, 'readNotifications']);
$router->post('/api/channels', [ChannelController::class, 'create']);
$router->post('/api/join', [ChannelController::class, 'join']);
$router->post('/api/part', [ChannelController::class, 'part']);
$router->post('/api/message/delete', [ChatController::class, 'deleteMessage']);
$router->post('/api/message/edit', [ChatController::class, 'editMessage']);

// Users / profiles
$router->get('/u/{username}', [UserController::class, 'profile']);
$router->get('/api/online', [UserController::class, 'online']);
$router->post('/api/password', [UserController::class, 'changePassword']);
$router->post('/api/profile', [UserController::class, 'updateProfile']);

// Admin dashboard
$router->get('/admin', [AdminController::class, 'overview']);
$router->get('/admin/users', [AdminController::class, 'users']);
$router->get('/admin/channels', [AdminController::class, 'channels']);
$router->get('/admin/bans', [AdminController::class, 'bans']);
$router->get('/admin/spamfilters', [AdminController::class, 'spamfilters']);
$router->get('/admin/badwords', [AdminController::class, 'badwords']);
$router->get('/admin/roles', [AdminController::class, 'roles']);
$router->get('/admin/motd', [AdminController::class, 'motd']);
$router->get('/admin/logs', [AdminController::class, 'logs']);
$router->get('/admin/logs/day', [AdminController::class, 'logDay']);
$router->get('/admin/logs/export', [AdminController::class, 'logDayExport']);
$router->get('/admin/settings', [AdminController::class, 'settings']);
$router->post('/admin/action', [AdminController::class, 'action']);

$router->dispatch();
