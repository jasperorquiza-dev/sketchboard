<?php
declare(strict_types=1);

return [
    'app' => [
        'secret_key' => 'replace-with-a-long-random-secret',
    ],
    'database' => [
        'host' => '127.0.0.1',
        'user' => 'sketchboard',
        'password' => 'replace-with-a-strong-password',
        'name' => 'sketchboard',
    ],
    'smtp' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'username' => 'noreply@example.com',
        'password' => 'replace-with-an-app-password',
        'from_email' => 'noreply@example.com',
        'from_name' => 'Sketchboard',
    ],
];
