<?php
// Copy this file to .env.php and edit values for your environment
return [
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => 'real_leads_checker',
    'DB_USER' => 'root',
    'DB_PASS' => 'password',

    // 32+ char random string, used for encrypting secrets (OpenSSL AES-256-CBC)
    'APP_KEY' => 'change_this_to_a_32plus_char_random_secret_key',

    // Timezone and pagination defaults
    'DEFAULT_TIMEZONE' => 'UTC',
    'DEFAULT_PAGE_SIZE' => 25,

    // Cron protection token
    'CRON_TOKEN' => 'set_a_long_random_token_here',
];

