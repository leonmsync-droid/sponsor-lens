<?php
// Public (non-secret) site settings.

return [
    // Rows below this many tracked matches show "Not enough data" for audience metrics.
    'min_tracked_matches' => 3,

    // ± band for "in line with tier average" in verdicts.
    'in_line_band' => 0.10,

    // Display names for PandaScore tiers.
    'tier_labels' => ['s' => 'Tier S', 'a' => 'Tier A', 'b' => 'Tier B', 'c' => 'Tier C', 'd' => 'Tier D'],
];
