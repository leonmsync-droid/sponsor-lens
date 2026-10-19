<?php
declare(strict_types=1);

// Build-order step 2 checkpoint. Straight from the PandaScore API (no database needed):
//   - confirms the CS2 videogame slug
//   - lists running + upcoming tier b–d tournaments with team counts and GB/ES teams
//   - checks whether match stream lists (official Twitch/Kick streams) come through on our plan
// Usage: php scripts/check_tournaments.php

require __DIR__ . '/../src/bootstrap.php';

$ps = new PandaScore((string) config('pandascore.token'), (string) config('contact'));
$highlight = ['GB', 'ES'];

foreach (config('games') as $key => $game) {
    $prefix = $game['api_prefix'];
    $tiers = implode(',', $game['tiers']);

    echo "\n=== {$game['name']} ===\n";
    foreach ($ps->get('/videogames', [], 86400) as $vg) {
        if (stripos($vg['name'], 'counter') !== false || in_array($vg['slug'], ['cs-go', 'csgo', 'cs-2', 'cs2'], true)) {
            echo "PandaScore videogame: id {$vg['id']}, slug '{$vg['slug']}', name '{$vg['name']}'\n";
        }
    }

    $tournaments = [];
    foreach (['running', 'upcoming'] as $state) {
        foreach ($ps->getAll("/$prefix/tournaments/$state", ['filter[tier]' => $tiers], 600, 10) as $t) {
            $t['_state'] = $state;
            $tournaments[$t['id']] = $t;
        }
    }
    usort($tournaments, fn($a, $b) => [$a['tier'], $a['begin_at'] ?? ''] <=> [$b['tier'], $b['begin_at'] ?? '']);

    $allTeams = [];
    $hlTeams = [];
    $tournamentIds = [];
    printf("\n%-8s %-4s %-62s %-12s %5s  %s\n", 'STATE', 'TIER', 'TOURNAMENT', 'STARTS', 'TEAMS', 'GB/ES TEAMS');
    foreach ($tournaments as $t) {
        $tournamentIds[] = $t['id'];
        $teams = $t['teams'] ?? null;
        if ($teams === null) {
            $teams = $ps->get("/tournaments/{$t['id']}/teams", ['page[size]' => 100], 3600);
        }
        $hl = [];
        foreach ($teams as $team) {
            $allTeams[$team['id']] = $team;
            if (in_array($team['location'] ?? '', $highlight, true)) {
                $hl[] = "{$team['name']} ({$team['location']})";
                $hlTeams[$team['id']] = $team;
            }
        }
        $label = trim(($t['league']['name'] ?? '') . ' ' . ($t['serie']['full_name'] ?? '') . ' — ' . $t['name']);
        printf(
            "%-8s %-4s %-62s %-12s %5d  %s\n",
            $t['_state'],
            strtoupper($t['tier']),
            mb_strimwidth($label, 0, 62, '…'),
            substr($t['begin_at'] ?? '?', 0, 10),
            count($teams),
            $hl ? implode(', ', $hl) : '–'
        );
    }

    $byTier = array_count_values(array_map(fn($t) => strtoupper($t['tier']), $tournaments));
    ksort($byTier);
    $hlByCountry = array_count_values(array_map(fn($t) => $t['location'], $hlTeams));

    echo "\nTournaments: " . count($tournaments) . ' (' . implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($byTier), $byTier)) . ")\n";
    echo 'Distinct teams: ' . count($allTeams) . "\n";
    echo 'GB teams: ' . ($hlByCountry['GB'] ?? 0) . ', ES teams: ' . ($hlByCountry['ES'] ?? 0) . "\n";
    echo 'Teams with no location set: ' . count(array_filter($allTeams, fn($t) => empty($t['location']))) . "\n";

    // Stream list check on running + upcoming matches in these tournaments.
    $matches = [];
    foreach (array_chunk($tournamentIds, 50) as $chunk) {
        foreach (['running', 'upcoming'] as $state) {
            $matches = array_merge($matches, $ps->getAll("/$prefix/matches/$state", ['filter[tournament_id]' => implode(',', $chunk)], 600, 5));
        }
    }
    $withList = $withOfficial = 0;
    $channels = [];
    foreach ($matches as $m) {
        if (!empty($m['streams_list'])) {
            $withList++;
        }
        $official = array_filter($m['streams_list'] ?? [], fn($s) => !empty($s['official']) && PandaScore::streamChannel($s['raw_url'] ?? null));
        if ($official) {
            $withOfficial++;
            foreach ($official as $s) {
                $channels[implode('/', PandaScore::streamChannel($s['raw_url']))] = $s['language'] ?? '?';
            }
        }
    }
    echo "\nStream list check: " . count($matches) . " running/upcoming matches, $withList with a streams_list, $withOfficial with an official Twitch or Kick stream\n";
    if ($channels) {
        echo 'Official channels seen: ' . implode(', ', array_map(fn($c, $l) => "$c ($l)", array_keys($channels), $channels)) . "\n";
    }
    if ($matches && !$withList) {
        echo "!! streams_list is empty on every match — likely not on this plan. Fallback: data/stream_seed.json\n";
    }
}

echo "\nPandaScore requests used: {$ps->requests}" . ($ps->remaining !== null ? ", remaining this hour: {$ps->remaining}" : '') . "\n";
