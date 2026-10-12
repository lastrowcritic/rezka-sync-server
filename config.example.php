<?php

declare(strict_types=1);

return [
    'db_host' => getenv('REZKA_DB_HOST') ?: 'localhost',
    'db_name' => getenv('REZKA_DB_NAME') ?: '',
    'db_user' => getenv('REZKA_DB_USER') ?: '',
    'db_password' => getenv('REZKA_DB_PASSWORD') ?: '',
    'session_pepper' => getenv('REZKA_SESSION_PEPPER') ?: '',
    // Turn this on while you create the accounts for your devices, then turn it off again.
    'allow_registration' => false,
];
