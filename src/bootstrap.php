<?php

/**
 * LVChat — Discord-style web chat (PHP + SQLite)
 *
 * Copyright (C) LVChat contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * SPDX-License-Identifier: AGPL-3.0-only
 */



declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('LVC_VERSION', '1.7.1');

// Load deployment secrets / developer config from ROOT/.env (if present).
// Never overrides variables already set in the environment.
require ROOT . '/src/Dotenv.php';
Dotenv::load(ROOT . '/.env');

// Composer autoloader (loads TCPDF and any future Composer dependencies).
if (is_file(ROOT . '/vendor/autoload.php')) {
    require ROOT . '/vendor/autoload.php';
}

error_reporting(E_ALL);
// Never leak stack traces, file paths, or DB details to browsers in production
// (verbose error disclosure). Errors are always logged server-side; developers
// and the test harness can opt back into on-screen errors with LVC_DEBUG=1.
if (PHP_SAPI === 'cli' || getenv('LVC_DEBUG') === '1') {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

// Sessions must survive cross-site iframe contexts (the public embed widget),
// so use SameSite=None over HTTPS. Plain-HTTP/local installs fall back to Lax.
// Check proxy headers (X-Forwarded-Proto, CF-Visitor) for TLS-terminating
// reverse proxies (nginx, Cloudflare, Caddy) where PHP sees plain HTTP.
// Only trust proxy headers when a trusted proxy is configured, to prevent
// HSTS injection or Secure cookie forcing on plain HTTP via header spoofing.
$trustedProxy = getenv('TRUSTED_PROXY');
$hasTrustedProxy = $trustedProxy !== false && $trustedProxy !== '' && $trustedProxy !== '0';
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['SERVER_PORT'] ?? '') === '443'
    || ($hasTrustedProxy && (
        strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || !empty($_SERVER['HTTP_X_FORWARDED_SSL'])
        || str_contains((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"https"')
    ));
session_set_cookie_params([
    'httponly' => true,
    'secure' => $secure,
    'samesite' => $secure ? 'None' : 'Lax',
]);
session_start();

require ROOT . '/src/Database.php';
require ROOT . '/src/Helpers.php';
require ROOT . '/src/Icons.php';
require ROOT . '/src/Csrf.php';
require ROOT . '/src/Auth.php';
require ROOT . '/src/Router.php';
require ROOT . '/src/services/AccessService.php';
require ROOT . '/src/services/ChannelService.php';
require ROOT . '/src/services/MessageService.php';
require ROOT . '/src/services/BanService.php';
require ROOT . '/src/services/UrlBanService.php';
require ROOT . '/src/services/CensorService.php';
require ROOT . '/src/services/UploadService.php';
require ROOT . '/src/services/GifService.php';
require ROOT . '/src/services/SoundService.php';
require ROOT . '/src/services/TypingService.php';
require ROOT . '/src/services/ThemeService.php';
require ROOT . '/src/services/WebhookService.php';
require ROOT . '/src/services/OpenClawBotService.php';
require ROOT . '/src/services/Mailer.php';
require ROOT . '/src/services/InviteService.php';
require ROOT . '/src/services/AuthTokenService.php';
require ROOT . '/src/services/ModerationService.php';
require ROOT . '/src/services/SupportService.php';
require ROOT . '/src/services/LegalService.php';
require ROOT . '/src/services/FriendService.php';
require ROOT . '/src/services/ContactGroupService.php';
require ROOT . '/src/services/Realtime.php';
require ROOT . '/src/services/PushService.php';
require ROOT . '/src/services/CommandRunner.php';
require ROOT . '/src/services/TotpService.php';
require ROOT . '/src/services/AnalyticsService.php';
require ROOT . '/src/services/UpdaterService.php';
require ROOT . '/src/services/CommandParser.php';
require ROOT . '/src/services/CommandRegistry.php';
require ROOT . '/src/services/EmbedService.php';
require ROOT . '/src/services/EventLogService.php';
require ROOT . '/src/services/commands/CoreCommands.php';
require ROOT . '/src/services/commands/OpCommands.php';
require ROOT . '/src/services/commands/ChanServCommands.php';
require ROOT . '/src/services/commands/NickServCommands.php';
require ROOT . '/src/services/commands/MemoCommands.php';
require ROOT . '/src/services/commands/HostCommands.php';
require ROOT . '/src/services/commands/OperCommands.php';
require ROOT . '/src/controllers/AuthController.php';
require ROOT . '/src/controllers/ChatController.php';
require ROOT . '/src/controllers/ChannelController.php';
require ROOT . '/src/controllers/BrowseController.php';
require ROOT . '/src/controllers/UserController.php';
require ROOT . '/src/controllers/AdminController.php';
require ROOT . '/src/controllers/SoundController.php';
require ROOT . '/src/controllers/ThemeController.php';
require ROOT . '/src/controllers/WebhookController.php';
require ROOT . '/src/controllers/SupportController.php';
require ROOT . '/src/controllers/LegalController.php';
require ROOT . '/src/controllers/PwaController.php';
require ROOT . '/src/controllers/FriendController.php';
require ROOT . '/src/controllers/ContactGroupController.php';
require ROOT . '/src/controllers/OpenClawController.php';
require ROOT . '/src/controllers/PushController.php';
require ROOT . '/src/controllers/EmbedController.php';
require ROOT . '/src/controllers/MessengerController.php';
require ROOT . '/src/services/LicenseKeys.php';
require ROOT . '/src/services/LicensingService.php';
require ROOT . '/src/ModuleLoader.php';
require ROOT . '/src/controllers/ModuleController.php';

Database::init();

// Discover + boot modules (empty modules/ dir is a silent no-op). Runs on every
// request including the Workerman daemon, so module init.php must be
// side-effect-free. See docs/modules.md.
ModuleLoader::boot();

// Apply the configured server timezone for display formatting.
// Storage remains UTC; this only affects date()/strtotime() output.
$tz = Database::scalar("SELECT value FROM server_config WHERE key = 'timezone'");
if ($tz && in_array($tz, DateTimeZone::listIdentifiers(), true)) {
    date_default_timezone_set($tz);
}

// Cross-origin app clients (the LVChat Messenger Electron app, future native
// mobile apps). CORS headers are emitted ONLY when an allowlisted Origin header
// is present, so the web app's same-origin traffic is completely untouched.
// Allowed origins come from the CHAT_CORS_ORIGINS env var or the `app_origins`
// config key (comma-separated). The built-in `null` origin (file://) and any
// http://127.0.0.1:* origin cover the local Electron messenger out of the box.
//
// Credentialed CORS (Access-Control-Allow-Credentials) is emitted ONLY for
// explicitly-configured origins. The `null` origin and localhost are never
// granted credentials: any opaque-origin page (sandboxed iframe, data: or
// blob: document) sends Origin: null and would otherwise be able to read the
// victim's Authenticated responses (including the CSRF token from /api/csrf)
// with the SameSite=None session cookie. The Electron messenger authenticates
// with the X-LVC-Session bearer header, which needs no cookie credentials.
$__corsOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($__corsOrigin !== '') {
    $__corsAllowed = false;
    $__corsConfigured = false;
    $__corsList = trim((string) (config_get('app_origins') ?? getenv('CHAT_CORS_ORIGINS')));
    if ($__corsList !== '') {
        foreach (explode(',', $__corsList) as $__o) {
            if (strcasecmp(trim($__o), $__corsOrigin) === 0) {
                $__corsAllowed = true;
                $__corsConfigured = true;
                break;
            }
        }
    }
    if (!$__corsAllowed && ($__corsOrigin === 'null' || preg_match('#^http://127\.0\.0\.1(:\d+)?$#', $__corsOrigin))) {
        $__corsAllowed = true; // non-credentialed only
    }
    if ($__corsAllowed) {
        header('Access-Control-Allow-Origin: ' . $__corsOrigin);
        if ($__corsConfigured) {
            header('Access-Control-Allow-Credentials: true');
        }
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF, X-Messenger, X-LVC-Session');
        header('Access-Control-Max-Age: 600');
    }
    unset($__o, $__corsList);
}
unset($__corsOrigin, $__corsAllowed, $__corsConfigured);

// HSTS: tell browsers to only use HTTPS for this domain (1 year, include subdomains).
if ($secure) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Admin session timeout: force re-authentication for admin accounts after 8 hours
// of inactivity, regardless of the 30-day cookie lifetime.
// Check both session-based and bearer-based auth paths.
$adminTimeout = 8 * 3600; // 8 hours
$adminToken = $_SESSION['token'] ?? Auth::headerToken();
if ($secure && $adminToken) {
    $lastActivity = (int) ($_SESSION['admin_last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > $adminTimeout) {
        $u = Auth::user();
        if ($u && $u['role'] === 'admin') {
            Auth::killSessions((int) $u['id']);
            @session_regenerate_id(true);
            unset($_SESSION['token'], $_SESSION['admin_last_activity']);
            redirect('/login');
        }
    }
    $_SESSION['admin_last_activity'] = time();
}
