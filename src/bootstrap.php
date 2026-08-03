<?php

declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('LVC_VERSION', '1.6.0');

error_reporting(E_ALL);
ini_set('display_errors', '1');

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
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
require ROOT . '/src/services/WebhookService.php';
require ROOT . '/src/services/Mailer.php';
require ROOT . '/src/services/InviteService.php';
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
require ROOT . '/src/controllers/WebhookController.php';

Database::init();
