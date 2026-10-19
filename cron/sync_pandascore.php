<?php
declare(strict_types=1);

// Hourly: sync tracked tournaments, teams and matches (schedule, results, stream lists) from PandaScore.
// Usage: php cron/sync_pandascore.php            (running + upcoming + finished in the last 3 days)
//        php cron/sync_pandascore.php --backfill (everything since season_start)

require __DIR__ . '/../src/bootstrap.php';

$backfill = in_array('--backfill', $argv, true);
$ps = new PandaScore((string) config('pandascore.token'), (string) config('contact'));
$db = db();
$now = gmdate('Y-m-d H:i:s');
$iso = fn(string $ts) => gmdate('Y-m-d\TH:i:s\Z', strtotime($ts));
$dt = fn(?string $v) => $v ? gmdate('Y-m-d H:i:s', strtotime($v)) : null;
$streams = MatchStreams::load();

foreach (config('games') as $gameKey => $game) {
    $prefix = $game['api_prefix'];
    $tierFilter = ['filter[tier]' => implode(',', $game['tiers'])];

    // 1. Tournaments (page by page). Keep only id => tier for the match step.
    $tiers = [];
    $teamLinks = 0;
    $sources = [
        ["/$prefix/tournaments/running", $tierFilter],
        ["/$prefix/tournaments/upcoming", $tierFilter],
        $backfill
            ? ["/$prefix/tournaments/past", $tierFilter + ['range[begin_at]' => $iso(season_start()) . ',' . $iso($now)]]
            : ["/$prefix/tournaments/past", $tierFilter + ['range[end_at]' => $iso('-3 days') . ',' . $iso($now)]],
    ];
    foreach ($sources as [$path, $query]) {
        foreach ($ps->pages($path, $query, 1800, $backfill ? 100 : 10) as $page) {
            $db->transaction(function (Db $db) use ($page, $gameKey, $now, $dt, &$tiers, &$teamLinks) {
                foreach ($page as $t) {
                    $tiers[$t['id']] = $t['tier'];
                    $db->upsert('tournaments', [
                        'id'          => $t['id'],
                        'game'        => $gameKey,
                        'name'        => $t['name'],
                        'league_name' => $t['league']['name'] ?? null,
                        'serie_name'  => $t['serie']['full_name'] ?? null,
                        'tier'        => $t['tier'],
                        'begin_at'    => $dt($t['begin_at'] ?? null),
                        'end_at'      => $dt($t['end_at'] ?? null),
                        'updated_at'  => $now,
                    ]);
                    foreach ($t['teams'] ?? [] as $team) {
                        upsertTeam($db, $team, $gameKey, $now);
                        $db->run('INSERT IGNORE INTO tournament_teams (tournament_id, team_id) VALUES (?, ?)', [$t['id'], $team['id']]);
                        $teamLinks++;
                    }
                }
            });
            unset($page);
        }
    }
    logline(sprintf('%s: %d tournaments, %d tournament-team links', $game['short'], count($tiers), $teamLinks));

    // 2. Matches for those tournaments (all states), 50 tournaments per request, page by page.
    $matchCount = 0;
    foreach (array_chunk(array_keys($tiers), 50) as $chunk) {
        foreach ($ps->pages("/$prefix/matches", ['filter[tournament_id]' => implode(',', $chunk)], 900, 50) as $page) {
            $db->transaction(function (Db $db) use ($page, $tiers, $gameKey, $now, $dt, $streams, &$matchCount) {
                foreach ($page as $m) {
                    upsertMatch($db, $m, $tiers[$m['tournament_id']], $gameKey, $now, $dt, $streams);
                    $matchCount++;
                }
            });
            unset($page);
        }
    }
    logline("{$game['short']}: $matchCount matches synced");
}

$db->setKv('last_pandascore_sync', $now);
logline("PandaScore requests: {$ps->requests}" . ($ps->remaining !== null ? ", remaining this hour: {$ps->remaining}" : ''));

function upsertTeam(Db $db, array $team, string $game, string $now): void
{
    $db->upsert('teams', [
        'id'         => $team['id'],
        'game'       => $game,
        'name'       => $team['name'],
        'acronym'    => $team['acronym'] ?? null,
        'location'   => isset($team['location']) ? strtoupper(substr($team['location'], 0, 2)) : null,
        'image_url'  => $team['image_url'] ?? null,
        'updated_at' => $now,
    ]);
}

function upsertMatch(Db $db, array $m, string $tier, string $game, string $now, callable $dt, MatchStreams $streams): void
{
    $teams = array_values(array_filter($m['opponents'] ?? [], fn($o) => ($o['type'] ?? '') === 'Team'));
    foreach ($teams as $o) {
        upsertTeam($db, $o['opponent'], $game, $now);
    }
    $team1 = $teams[0]['opponent']['id'] ?? null;
    $team2 = $teams[1]['opponent']['id'] ?? null;
    $scores = array_column($m['results'] ?? [], 'score', 'team_id');

    $db->upsert('matches', [
        'id'            => $m['id'],
        'game'          => $game,
        'tournament_id' => $m['tournament_id'],
        'tier'          => $tier,
        'name'          => $m['name'] ?? null,
        'status'        => $m['status'],
        'scheduled_at'  => $dt($m['scheduled_at'] ?? null),
        'begin_at'      => $dt($m['begin_at'] ?? null),
        'end_at'        => $dt($m['end_at'] ?? null),
        'team1_id'      => $team1,
        'team2_id'      => $team2,
        'team1_score'   => $team1 !== null ? ($scores[$team1] ?? null) : null,
        'team2_score'   => $team2 !== null ? ($scores[$team2] ?? null) : null,
        'winner_id'     => $m['winner_id'] ?? null,
        'forfeit'       => !empty($m['forfeit']) ? 1 : 0,
        'updated_at'    => $now,
    ]);

    $db->run('DELETE FROM match_streams WHERE match_id = ?', [$m['id']]);
    foreach ($streams->streamsFor($m) as [$platform, $channel, $lang]) {
        $db->run('INSERT INTO match_streams (match_id, platform, channel, language) VALUES (?, ?, ?, ?)', [$m['id'], $platform, $channel, $lang]);
    }
}
