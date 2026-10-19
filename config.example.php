<?php
// Copy to config.php (same folder, which sits OUTSIDE the web root) and fill in.
// config.php is git-ignored. Never commit real keys.

return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'sponsor_lens',
        'user'    => 'sponsor_lens',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    'pandascore' => [
        // https://app.pandascore.co/dashboard -> API token (free Fixtures plan)
        'token' => '',
    ],

    'twitch' => [
        // https://dev.twitch.tv/console/apps -> register an app (client credentials flow)
        'client_id'     => '',
        'client_secret' => '',
    ],

    'kick' => [
        // https://kick.com/settings/developer -> create an app (client credentials flow)
        'client_id'     => '',
        'client_secret' => '',
    ],

    // Shown in the footer and sent as contact info in API User-Agents.
    'contact' => 'you@example.com',

    // Season start (inclusive). All "this season" metrics count from here.
    'season_start' => '2026-01-01',
];
