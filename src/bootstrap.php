<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('LVC_VERSION', '1.7.1');

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Sessions must survive cross-site iframe contexts (the public embed widget),
// so use SameSite=None over HTTPS. Plain-HTTP/local installs fall back to Lax.
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['SERVER_PORT'] ?? '') === '443';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $secure,
    'samesite' => $secure ? 'None' : 'Lax',
]);
session_start();

require ROOT . '/src/Database.php';
require ROOT . '/src/Helpers.php';
require ROOT . '/src/Csrf.php';
require ROOT . '/src/Auth.php';
require ROOT . '/src/Router.php';
require ROOT . '/src/services/AccessService.php';
require ROOT . '/src/services/ChannelService.php';
require ROOT . '/src/services/MessageService.php';
require ROOT . '/src/services/BanService.php';
require ROOT . '/src/services/CensorService.php';
require ROOT . '/src/services/UploadService.php';
require ROOT . '/src/services/GifService.php';
require ROOT . '/src/services/SoundService.php';
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

Database::init();

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
$__corsOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($__corsOrigin !== '') {
    $__corsAllowed = false;
    $__corsList = trim((string) (config_get('app_origins') ?? getenv('CHAT_CORS_ORIGINS')));
    if ($__corsList !== '') {
        foreach (explode(',', $__corsList) as $__o) {
            if (strcasecmp(trim($__o), $__corsOrigin) === 0) {
                $__corsAllowed = true;
                break;
            }
        }
    }
    if (!$__corsAllowed && ($__corsOrigin === 'null' || preg_match('#^http://127\.0\.0\.1(:\d+)?$#', $__corsOrigin))) {
        $__corsAllowed = true;
    }
    if ($__corsAllowed) {
        header('Access-Control-Allow-Origin: ' . $__corsOrigin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF');
        header('Access-Control-Max-Age: 600');
    }
    unset($__o, $__corsList);
}
unset($__corsOrigin, $__corsAllowed);
