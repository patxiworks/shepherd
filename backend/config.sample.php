<?php
// Copy this file to config.php (which is gitignored) and fill in real
// credentials. config.php is required by includes/db.php.

return [
    'db' => [
        'host' => 'localhost',
        // MAMP's bundled MySQL listens on 8889, not the MySQL default of
        // 3306 — set this when developing against MAMP locally. Leave null
        // (or omit) for a normal cPanel/production MySQL on the default port.
        'port' => null,
        'name' => 'pastores',
        'user' => 'pastores_user',
        'pass' => 'change-me',
        'charset' => 'utf8mb4',
    ],
    // Comma-separated list of origins allowed to call api/*.php directly.
    // Leave empty if only the Next.js server (server-side) calls this API,
    // since server-to-server requests don't need CORS at all.
    'allowed_origins' => [],
];
