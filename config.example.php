<?php
// Copy OUTSIDE the public directory as config.php. Never put secrets in HTML.
return [
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'ambulance_refer',
    'db_user' => 'refer_app',
    'db_password' => '', // Set privately on the hospital server.
    'password_hash' => '', // Use: php -r "echo password_hash(readline('Password: '), PASSWORD_DEFAULT), PHP_EOL;"
    'origin' => 'https://refer.hospital.example', // Exact scheme + host + optional port; no trailing slash/path.
];
