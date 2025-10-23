<?php
// Config for running inside Docker PHP container (uses service name 'mysql')
return [
    'DB_HOST' => 'mysql',
    'DB_PORT' => '3306',
    'DB_NAME' => 'real_leads_checker',
    'DB_USER' => 'rlc',
    'DB_PASS' => 'rlcpass',

    'APP_KEY' => 'please_change_to_a_random_32plus_char_secret________________',

    'DEFAULT_TIMEZONE' => 'UTC',
    'DEFAULT_PAGE_SIZE' => 25,

    'CRON_TOKEN' => 'change_me_long_random_token',
];

