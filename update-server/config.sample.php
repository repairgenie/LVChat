<?php

declare(strict_types=1);

// LVChat Update Server configuration.
//
// Copy this file to config.php (next to it) and edit the values.
// config.php is git-ignored — it never ships with the app.

// Public display name used in the header/footer.
return [
    'site_name' => 'LVChat Updates',

    // Base public URL of this server (used for links in the UI and feeds).
    // Example: 'https://updates.lasvegasbestinternet.com'
    'base_url' => '',

    // Operator password. Either:
    //   - 'admin_pass'            the plaintext password (simplest), or
    //   - 'admin_pass_hash'       a password_hash()/bcrypt hash of it (recommended).
    //
    // Generate a hash with:
    //   php -r 'echo password_hash("your-password", PASSWORD_BCRYPT), PHP_EOL;'
    // Then put the output in admin_pass_hash below.
    'admin_pass' => '',
    'admin_pass_hash' => '',
];
