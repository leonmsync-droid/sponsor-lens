<?php
// Public (non-secret) site settings.

return [
    // Rows below this many tracked matches show "Not enough data" for audience metrics.
    'min_tracked_matches' => 3,

    // ± band for "in line with the average at similar events" in verdicts.
    'in_line_band' => 0.10,

    // PandaScore grades tournaments, not teams, so tiers are always shown as event levels.
    'tier_labels' => ['s' => 'S-tier events', 'a' => 'A-tier events', 'b' => 'B-tier events', 'c' => 'C-tier events', 'd' => 'D-tier events'],
    // Short names for the event level filter and averages.
    'level_names' => ['s' => 'Top', 'a' => 'Major', 'b' => 'Bigger', 'c' => 'Mid', 'd' => 'Smaller'],
];
