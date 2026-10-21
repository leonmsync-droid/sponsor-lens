<?php
declare(strict_types=1);

// Plain-English verdict sentences, generated from the numbers by simple rules.
final class Verdict
{
    // "C-tier events": PandaScore grades events, so a tier never describes a team on its own.
    public static function tierLabel(?string $tier): string
    {
        return config('site.tier_labels')[$tier] ?? 'unknown events';
    }

    // "Mid (C)", for the event level filter and averages.
    public static function levelLabel(?string $tier): string
    {
        $name = config('site.level_names')[$tier] ?? null;
        return $name ? $name . ' (' . strtoupper($tier) . ')' : 'Unknown';
    }

    // Team page: compare with the average at the same event levels, ±in_line_band counts as "in line".
    public static function team(array $t): string
    {
        $min = Metrics::minTracked();
        if (!$t['enough_data']) {
            $audience = "Not enough audience data yet ({$t['tracked']} of $min tracked matches).";
            return $t['played']
                ? $audience . sprintf(' Wins %s of its matches this season (%d of %d).', Format::pct($t['win_rate']), $t['wins'], $t['played'])
                : $audience;
        }

        $against = $t['compared_tier'] ? 'the average at ' . self::tierLabel($t['compared_tier']) : 'the average at the event levels it plays at';
        $band = (float) config('site.in_line_band');
        $vs = $t['vs_tier'];
        if ($vs === null) {
            return 'Not enough audience data yet to compare with similar events.';
        }
        if (abs($vs) <= $band) {
            return "Draws about as many viewers per match as $against (within ±" . Format::pct($band) . ').';
        }
        return sprintf('Draws %s %s viewers per match than %s.', Format::pct(abs($vs)), $vs > 0 ? 'more' : 'fewer', $against);
    }

    // Rankings: one sentence about the top of the filtered table.
    public static function rankings(array $rows, ?string $tier): string
    {
        $scope = $tier ? 'team most often at ' . self::tierLabel($tier) : 'tier-2 or tier-3 team';
        $leaders = array_values(array_filter($rows, fn($r) => $r['enough_data']));
        if (!$leaders) {
            return "No $scope has " . Metrics::minTracked() . ' tracked matches yet, so teams are ordered by matches tracked, then win rate. Audience figures fill in as matches are played.';
        }
        $top = $leaders[0];
        $sentence = sprintf('%s leads, drawing %s viewers per match', $top['name'], Format::int($top['avg_viewers']));
        if ($top['vs_tier'] !== null && abs($top['vs_tier']) > (float) config('site.in_line_band')) {
            $sentence .= sprintf(' — %s %s the average at similar events', Format::pct(abs($top['vs_tier'])), $top['vs_tier'] > 0 ? 'above' : 'below');
        }
        return $sentence . sprintf('. %d %s enough data to rank.', count($leaders), count($leaders) === 1 ? 'team has' : 'teams have');
    }

    // Match load: busiest stretch, then form on short rest vs rested when both samples are big enough.
    public static function load(array $l): string
    {
        if (!$l['matches']) {
            return 'No completed matches this season yet.';
        }
        $b = $l['busiest'];
        $span = gmdate('j M', $b['to']);
        if (gmdate('Y-m-d', $b['from']) !== gmdate('Y-m-d', $b['to'])) {
            $span = (gmdate('M', $b['from']) === gmdate('M', $b['to']) ? gmdate('j', $b['from']) : gmdate('j M', $b['from'])) . '–' . $span;
        }
        $sentence = sprintf('Busiest stretch: %d matches (%d maps) in 7 days, %s.', $b['matches'], $b['maps'], $span);

        $short = $l['short']['rate'];
        $rested = $l['rested']['rate'];
        if ($short !== null && $rested !== null) {
            $gap = $short - $rested;
            $sentence .= abs($gap) < 0.10
                ? sprintf(' Form held up on short rest (%s won vs %s when rested).', Format::pct($short), Format::pct($rested))
                : sprintf(' Won %s of matches on short rest vs %s when rested.', Format::pct($short), Format::pct($rested));
        }
        return $sentence;
    }
}
