<?php

declare(strict_types=1);

// Fixture module schema.php — idempotent migration.
return static function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS good_module_items (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL DEFAULT "")');
};
