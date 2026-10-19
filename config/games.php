<?php
// Which games and tiers are tracked. Add a game or tier here — no code changes needed.
// 'api_prefix' is the PandaScore path segment, e.g. /csgo/tournaments.

return [
    'cs2' => [
        'name'       => 'Counter-Strike 2',
        'short'      => 'CS2',
        'api_prefix' => 'csgo',
        'tiers'      => ['b', 'c', 'd'],
    ],
];
