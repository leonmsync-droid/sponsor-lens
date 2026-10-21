<?php
declare(strict_types=1);

// Hourly: builds per-match audience summaries from raw samples, then deletes raw samples older than 24 hours.
// Only matches with samples newer than the previous run (minus an hour of slack) are rebuilt, so a match live
// across two runs gets its extra samples, while an older summary is never rebuilt from a partly-deleted sample set.
// Summaries live in audience_match_summary and survive the cleanup.
// Usage: php cron/build_summaries.php

require __DIR__ . '/../src/bootstrap.php';

$db = db();
$now = gmdate('Y-m-d H:i:s');
$since = gmdate('Y-m-d H:i:s', strtotime($db->getKv('last_summary_build') ?? '1970-01-01') - 3600);

$matchIds = array_column($db->all('SELECT match_id FROM stream_samples GROUP BY match_id HAVING MAX(sampled_at) >= ?', [$since]), 'match_id');
foreach ($matchIds as $matchId) {
    $rows = $db->all('SELECT sampled_at, platform, language, viewers FROM stream_samples WHERE match_id = ?', [$matchId]);

    // Total per sample time (summed across streams), plus per-language and per-platform totals.
    $totals = $byLang = $byPlatform = [];
    foreach ($rows as $r) {
        $totals[$r['sampled_at']] = ($totals[$r['sampled_at']] ?? 0) + $r['viewers'];
        $lang = $r['language'] ?: 'other';
        $byLang[$lang] = ($byLang[$lang] ?? 0) + $r['viewers'];
        $byPlatform[$r['platform']] = ($byPlatform[$r['platform']] ?? 0) + $r['viewers'];
    }
    $n = count($totals);
    $avg = fn(array $sums) => array_map(fn($v) => (int) round($v / $n), $sums);

    $db->upsert('audience_match_summary', [
        'match_id'     => $matchId,
        'samples'      => $n,
        'avg_viewers'  => (int) round(array_sum($totals) / $n),
        'peak_viewers' => max($totals),
        'by_language'  => json_encode($avg($byLang)),
        'by_platform'  => json_encode($avg($byPlatform)),
        'built_at'     => $now,
    ], ['match_id']);
}
logline(count($matchIds) . ' match summaries built');
if ($matchIds) {
    Metrics::clearCache();
}

$deleted = $db->run('DELETE FROM stream_samples WHERE sampled_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)])->rowCount();
logline("$deleted raw samples older than 24h deleted");
$db->setKv('last_summary_build', $now);

// Headline figures for the RankPortfolio hub: counts only, no team or match details.
$measured = (int) $db->value('SELECT COUNT(*) FROM audience_match_summary');
$teams = (int) $db->value(
    'SELECT COUNT(DISTINCT t) FROM (
        SELECT m.team1_id AS t FROM matches m JOIN audience_match_summary a ON a.match_id = m.id
        UNION ALL
        SELECT m.team2_id FROM matches m JOIN audience_match_summary a ON a.match_id = m.id
     ) x WHERE t IS NOT NULL'
);
file_put_contents(STORAGE_DIR . '/stats.json', json_encode([
    'matches_measured' => $measured,
    'teams_tracked' => $teams,
    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
]));

// Prune API response cache files older than a day (hourly sync URLs include timestamps, so they don't overwrite).
$pruned = 0;
foreach (glob(STORAGE_DIR . '/cache/ps_*.json') ?: [] as $file) {
    if (filemtime($file) < time() - 86400 && unlink($file)) {
        $pruned++;
    }
}
logline("$pruned old cache files pruned");
