<?php
declare(strict_types=1);

// All derived numbers shown on the site (formulas are mirrored on the Methodology page).
//   Avg viewers per match   = mean of a match's 10-minute samples, summed across its official streams
//   Tier average            = mean avg-viewers-per-match over all tracked matches in that tier this season
//   vs tier average         = team avg ÷ expected − 1, where expected = mean of the tier averages of the tiers
//                             the team's tracked matches were played in (= its tier average for single-tier teams)
//   Win rate                = wins ÷ decided matches this season (forfeits excluded); trend = rolling last 10
//   Estimated season views  = avg viewers per match × tracked matches this season
//   Cost per 1,000 views    = fee ÷ (estimated season views ÷ 1,000)
final class Metrics
{
    private const CACHE_TTL = 300;

    public function __construct(private Db $db) {}

    // Call after any job that changes matches or summaries, so pages don't serve a stale table.
    public static function clearCache(): void
    {
        @unlink(STORAGE_DIR . '/cache/team_table.json');
    }

    public static function minTracked(): int
    {
        return (int) config('site.min_tracked_matches');
    }

    // Decided matches this season (finished, has a winner, not a forfeit), oldest first.
    private function decidedMatches(?int $teamId = null): array
    {
        $sql = "SELECT id, tier, team1_id, team2_id, winner_id, COALESCE(begin_at, scheduled_at) AS played_at
                FROM matches
                WHERE status = 'finished' AND winner_id IS NOT NULL AND forfeit = 0
                  AND COALESCE(begin_at, scheduled_at) >= ?";
        $params = [season_start()];
        if ($teamId !== null) {
            $sql .= ' AND (team1_id = ? OR team2_id = ?)';
            array_push($params, $teamId, $teamId);
        }
        return $this->db->all($sql . ' ORDER BY played_at, id', $params);
    }

    // Tracked matches (have an audience summary) this season, oldest first. Empty if the summary table is wiped.
    private function trackedMatches(?int $teamId = null): array
    {
        $sql = 'SELECT m.id, m.tier, m.team1_id, m.team2_id, COALESCE(m.begin_at, m.scheduled_at) AS played_at,
                       s.avg_viewers, s.peak_viewers
                FROM matches m JOIN audience_match_summary s ON s.match_id = m.id
                WHERE COALESCE(m.begin_at, m.scheduled_at) >= ?';
        $params = [season_start()];
        if ($teamId !== null) {
            $sql .= ' AND (m.team1_id = ? OR m.team2_id = ?)';
            array_push($params, $teamId, $teamId);
        }
        try {
            return $this->db->all($sql . ' ORDER BY played_at, m.id', $params);
        } catch (PDOException $e) {
            return []; // table missing: site keeps working without audience data
        }
    }

    // [tier => ['avg' => float, 'matches' => int]]
    public function tierAverages(): array
    {
        $sums = [];
        foreach ($this->trackedMatches() as $m) {
            $sums[$m['tier']][] = (int) $m['avg_viewers'];
        }
        return array_map(fn($v) => ['avg' => array_sum($v) / count($v), 'matches' => count($v)], $sums);
    }

    // One row per team that played this season, keyed by team id. Cached for a few minutes.
    public function teamTable(): array
    {
        $cache = STORAGE_DIR . '/cache/team_table.json';
        if (is_file($cache) && filemtime($cache) > time() - self::CACHE_TTL) {
            return json_decode((string) file_get_contents($cache), true);
        }

        $rows = [];
        $touch = function (int $id) use (&$rows) {
            $rows[$id] ??= ['played' => 0, 'wins' => 0, 'tiers' => [], 'last_played' => null, 'tracked' => []];
        };

        foreach ($this->decidedMatches() as $m) {
            foreach ([$m['team1_id'], $m['team2_id']] as $id) {
                if ($id === null) {
                    continue;
                }
                $touch((int) $id);
                $r = &$rows[(int) $id];
                $r['played']++;
                $r['wins'] += (int) ($m['winner_id'] == $id);
                $r['tiers'][$m['tier']] = ($r['tiers'][$m['tier']] ?? 0) + 1;
                $r['last_played'] = $m['played_at'];
                unset($r);
            }
        }
        foreach ($this->trackedMatches() as $m) {
            foreach ([$m['team1_id'], $m['team2_id']] as $id) {
                if ($id !== null) {
                    $touch((int) $id);
                    $rows[(int) $id]['tracked'][] = [$m['tier'], (int) $m['avg_viewers']];
                }
            }
        }

        $tierAvg = $this->tierAverages();
        $teams = $rows ? $this->db->all('SELECT id, name, acronym, location, image_url FROM teams WHERE id IN (' . implode(',', array_keys($rows)) . ')') : [];
        $out = [];
        foreach ($teams as $t) {
            $r = $rows[$t['id']];
            $out[$t['id']] = $t + $this->summarise($r, $tierAvg);
        }
        if (!is_dir(dirname($cache))) {
            mkdir(dirname($cache), 0775, true);
        }
        file_put_contents($cache, json_encode($out));
        return $out;
    }

    private function summarise(array $r, array $tierAvg): array
    {
        // Primary tier: where the team played most decided matches (ties go to the higher tier).
        $tiers = $r['tiers'];
        uksort($tiers, fn($a, $b) => [$tiers[$b], $a] <=> [$tiers[$a], $b]);
        $primary = array_key_first($tiers) ?? ($r['tracked'][0][0] ?? null);

        $tracked = count($r['tracked']);
        $avg = $expected = $vsTier = null;
        if ($tracked > 0) {
            $avg = array_sum(array_column($r['tracked'], 1)) / $tracked;
            $expected = array_sum(array_map(fn($m) => $tierAvg[$m[0]]['avg'], $r['tracked'])) / $tracked;
            $vsTier = $expected > 0 ? $avg / $expected - 1 : null;
        }
        $trackedTiers = array_unique(array_column($r['tracked'], 0));

        return [
            'tier'          => $primary,
            'played'        => $r['played'],
            'wins'          => $r['wins'],
            'win_rate'      => $r['played'] ? $r['wins'] / $r['played'] : null,
            'last_played'   => $r['last_played'],
            'tracked'       => $tracked,
            'enough_data'   => $tracked >= self::minTracked(),
            'avg_viewers'   => $avg,
            'expected'      => $expected,
            'vs_tier'       => $vsTier,
            'compared_tier' => count($trackedTiers) === 1 ? reset($trackedTiers) : null, // null = mixed tiers
        ];
    }

    // Rankings: optional tier filter. Teams with enough audience data first
    // (by avg viewers), then the rest by matches tracked, then win rate.
    public function rankings(?string $tier, int $activeDays = 120): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - $activeDays * 86400);
        $rows = array_filter($this->teamTable(), fn($t) =>
            ($tier === null || $t['tier'] === $tier)
            && ($t['last_played'] >= $since || $t['tracked'] > 0));

        usort($rows, fn($a, $b) =>
            [$b['enough_data'], $b['enough_data'] ? $b['avg_viewers'] : 0, $b['tracked'], $b['win_rate'] ?? 0, $b['played']]
            <=> [$a['enough_data'], $a['enough_data'] ? $a['avg_viewers'] : 0, $a['tracked'], $a['win_rate'] ?? 0, $a['played']]);
        return array_values($rows);
    }

    // Everything the team page needs beyond its table row.
    public function teamDetail(int $teamId): array
    {
        $decided = $this->decidedMatches($teamId);
        $rolling = [];
        $window = [];
        foreach ($decided as $m) {
            $window[] = (int) ($m['winner_id'] == $teamId);
            if (count($window) > 10) {
                array_shift($window);
            }
            $rolling[] = ['date' => substr($m['played_at'], 0, 10), 'win_rate' => array_sum($window) / count($window)];
        }

        $viewers = array_map(fn($m) => [
            'date'    => substr($m['played_at'], 0, 10),
            'viewers' => (int) $m['avg_viewers'],
        ], $this->trackedMatches($teamId));

        $matchCols = "m.id, m.status, m.tier, m.team1_id, m.team2_id, m.team1_score, m.team2_score, m.winner_id, m.forfeit,
                      m.audience_note, COALESCE(m.begin_at, m.scheduled_at) AS played_at,
                      t.name AS tournament, t.league_name, t.serie_name,
                      o.id AS opp_id, o.name AS opp_name, o.location AS opp_location";
        $joins = 'JOIN tournaments t ON t.id = m.tournament_id
                  LEFT JOIN teams o ON o.id = IF(m.team1_id = ?, m.team2_id, m.team1_id)';

        $recent = $this->db->all("SELECT $matchCols FROM matches m $joins
            WHERE (m.team1_id = ? OR m.team2_id = ?) AND m.status = 'finished'
            ORDER BY played_at DESC LIMIT 10", [$teamId, $teamId, $teamId]);
        $upcoming = $this->db->all("SELECT $matchCols FROM matches m $joins
            WHERE (m.team1_id = ? OR m.team2_id = ?) AND m.status IN ('not_started', 'running')
            ORDER BY m.status = 'running' DESC, played_at LIMIT 5", [$teamId, $teamId, $teamId]);

        return [
            'rolling_win_rate' => array_slice($rolling, -30),
            'viewers'          => $viewers,
            'recent'           => $recent,
            'upcoming'         => $upcoming,
            'live'             => $this->live($teamId),
        ];
    }

    // Running match for the team, with viewers from the latest sample in the last 15 minutes (null if not tracked).
    public function live(int $teamId): ?array
    {
        $match = $this->db->one("SELECT id, audience_note FROM matches WHERE status = 'running' AND (team1_id = ? OR team2_id = ?)
                                 ORDER BY begin_at DESC LIMIT 1", [$teamId, $teamId]);
        if (!$match) {
            return null;
        }
        $viewers = $this->db->value('SELECT SUM(viewers) FROM stream_samples
            WHERE match_id = ? AND sampled_at = (SELECT MAX(sampled_at) FROM stream_samples WHERE match_id = ?)
              AND sampled_at >= ?', [$match['id'], $match['id'], gmdate('Y-m-d H:i:s', time() - 900)]);
        return ['match_id' => (int) $match['id'], 'viewers' => $viewers !== null ? (int) $viewers : null];
    }

    public static function seasonViews(array $team): ?float
    {
        return $team['enough_data'] ? $team['avg_viewers'] * $team['tracked'] : null;
    }

    // Cost per 1,000 match views for a fee, or null when there isn't enough data.
    public static function costPerThousand(float $fee, array $team): ?float
    {
        $views = self::seasonViews($team);
        return $views ? $fee / ($views / 1000) : null;
    }

    // Match load this season, from the schedule (finished, non-forfeit matches):
    //   maps            = map score sum (a 2–1 best-of-3 is 3 maps)
    //   short rest      = match starts < SHORT_REST_HOURS after the team's previous match started
    //   rested          = >= RESTED_HOURS since the previous match
    //   busiest stretch = most matches in any 7-day window
    // Signals about workload, not medical or performance claims.
    public const SHORT_REST_HOURS = 24;
    public const RESTED_HOURS = 48;
    public const MIN_FORM_SAMPLE = 5;

    public function matchLoad(int $teamId): array
    {
        $rows = $this->db->all("SELECT begin_at, team1_score, team2_score, winner_id FROM matches
            WHERE status = 'finished' AND forfeit = 0 AND begin_at >= ? AND (team1_id = ? OR team2_id = ?)
            ORDER BY begin_at", [season_start(), $teamId, $teamId]);

        $matches = array_map(fn($r) => [
            't'    => strtotime($r['begin_at'] . ' UTC'),
            'maps' => (int) $r['team1_score'] + (int) $r['team2_score'],
            'won'  => (int) ($r['winner_id'] == $teamId),
        ], $rows);
        $n = count($matches);
        if ($n === 0) {
            return ['matches' => 0];
        }

        // Busiest 7-day window (two pointers over start times).
        $best = ['matches' => 0, 'maps' => 0, 'from' => null, 'to' => null];
        $maps = 0;
        for ($i = 0, $j = 0; $j < $n; $j++) {
            $maps += $matches[$j]['maps'];
            while ($matches[$j]['t'] - $matches[$i]['t'] >= 7 * 86400) {
                $maps -= $matches[$i]['maps'];
                $i++;
            }
            $count = $j - $i + 1;
            if ([$count, $maps] > [$best['matches'], $best['maps']]) {
                $best = ['matches' => $count, 'maps' => $maps, 'from' => $matches[$i]['t'], 'to' => $matches[$j]['t']];
            }
        }

        // Rest before each match, and form on short rest vs rested.
        $short = $rested = ['played' => 0, 'won' => 0];
        for ($k = 1; $k < $n; $k++) {
            $hours = ($matches[$k]['t'] - $matches[$k - 1]['t']) / 3600;
            if ($hours < self::SHORT_REST_HOURS) {
                $short['played']++;
                $short['won'] += $matches[$k]['won'];
            } elseif ($hours >= self::RESTED_HOURS) {
                $rested['played']++;
                $rested['won'] += $matches[$k]['won'];
            }
        }

        // Typical rest between match days (median, in days).
        $days = array_values(array_unique(array_map(fn($m) => intdiv($m['t'], 86400), $matches)));
        $gaps = [];
        for ($k = 1; $k < count($days); $k++) {
            $gaps[] = $days[$k] - $days[$k - 1];
        }
        sort($gaps);
        $medianRest = $gaps ? ($gaps[intdiv(count($gaps) - 1, 2)] + $gaps[intdiv(count($gaps), 2)]) / 2 : null;

        // Last 28 days, and weekly totals for the last 16 weeks (weeks start Monday, UTC).
        $since28 = time() - 28 * 86400;
        $recent = array_filter($matches, fn($m) => $m['t'] >= $since28);
        $thisMonday = strtotime('monday this week 00:00 UTC');
        $weeks = [];
        for ($w = 15; $w >= 0; $w--) {
            $start = $thisMonday - $w * 7 * 86400;
            $weeks[$start] = ['week' => gmdate('Y-m-d', $start), 'matches' => 0, 'maps' => 0, 'won' => 0];
        }
        foreach ($matches as $m) {
            $start = $thisMonday + (int) floor(($m['t'] - $thisMonday) / (7 * 86400)) * 7 * 86400;
            if (isset($weeks[$start])) {
                $weeks[$start]['matches']++;
                $weeks[$start]['maps'] += $m['maps'];
                $weeks[$start]['won'] += $m['won'];
            }
        }

        $rate = fn(array $g) => $g['played'] >= self::MIN_FORM_SAMPLE ? $g['won'] / $g['played'] : null;
        return [
            'matches'     => $n,
            'maps'        => array_sum(array_column($matches, 'maps')),
            'recent'      => ['matches' => count($recent), 'maps' => array_sum(array_column($recent, 'maps'))],
            'busiest'     => $best,
            'median_rest' => $medianRest,
            'short'       => $short + ['rate' => $rate($short)],
            'rested'      => $rested + ['rate' => $rate($rested)],
            'weeks'       => array_values($weeks),
        ];
    }
}
