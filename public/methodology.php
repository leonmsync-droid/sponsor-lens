<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$min = Metrics::minTracked();
$band = Format::pct((float) config('site.in_line_band'));
$tiers = implode(', ', array_map([Verdict::class, 'tierLabel'], array_merge(...array_column(config('games'), 'tiers'))));

View::header('Methodology and data sources', 'methodology.php');
?>
<section class="hero">
  <p class="eyebrow">Methodology</p>
  <h1>How the <span class="accent">numbers</span> are made</h1>
  <p class="verdict">Every figure on this site comes from official match streams and match results, using the formulas below. Treat them as signals, not predictions.</p>
</section>
<div class="prose">

<h2>What's covered</h2>
<ul>
  <li>Counter-Strike 2, PandaScore tournament tiers: <?= $tiers ?>. Tier S and A events are excluded.</li>
  <li>"This season" means matches since <?= Format::e(gmdate('j F Y', strtotime(season_start()))) ?>.</li>
  <li>Teams from <?= Format::e(config('site.highlight_label')) ?> are highlighted in the rankings. Audience figures are global for every team.</li>
</ul>

<h2>Formulas</h2>
<dl class="formulas">
  <dt id="avg-viewers">Avg viewers per match</dt>
  <dd>
    <p>Every 10 minutes while a match is live we read the live viewer count of each of its official Twitch and Kick streams, in every language, and add them up. A match's figure is the mean of those samples.</p>
    <p class="formula-line">avg viewers per match = mean(sum of viewers across official streams, per 10-minute sample)</p>
    <p class="example">Example: three samples of 1,800, 2,400 and 2,100 viewers → 2,100.</p>
    <p>A team's avg viewers per match is the mean of that figure over its tracked matches.</p>
  </dd>

  <dt id="tracked">Matches tracked</dt>
  <dd>
    <p>Matches this season with at least one audience sample. A match is <em>not tracked</em> if it has no official Twitch or Kick stream, or if one of its channels was showing another match at the same time (we can't split those viewers fairly).</p>
    <p>With fewer than <?= $min ?> tracked matches we show "Not enough data" instead of a number.</p>
  </dd>

  <dt id="tier-average">Tier average</dt>
  <dd>
    <p class="formula-line">tier average = mean(avg viewers per match) over all tracked matches in that tier this season</p>
    <p class="example">Example: Tier C matches averaging 900, 1,500 and 1,200 → tier average 1,200.</p>
  </dd>

  <dt id="vs-tier">Audience vs tier average</dt>
  <dd>
    <p class="formula-line">vs tier average = (team avg ÷ tier average) − 1</p>
    <p class="example">Example: team avg 1,656, tier average 1,200 → +38%.</p>
    <p>Teams are only compared with their own tier. If a team's tracked matches span several tiers, its tier average is the mean of the tier averages of those matches, so a Tier B appearance is compared with Tier B.</p>
    <p>Verdicts call a team "in line" with its tier when it is within ±<?= $band ?>.</p>
  </dd>

  <dt id="win-rate">Win rate</dt>
  <dd>
    <p class="formula-line">win rate = wins ÷ decided matches this season</p>
    <p>Decided means finished with a winner. Forfeits are left out. The trend chart shows the win rate over the previous 10 decided matches after each match.</p>
    <p class="example">Example: 13 wins from 20 matches → 65%.</p>
  </dd>

  <dt id="season-views">Estimated season views</dt>
  <dd>
    <p class="formula-line">estimated season views = avg viewers per match × tracked matches this season</p>
    <p class="example">Example: 1,656 × 12 → 19,872.</p>
  </dd>

  <dt id="cost-per-1000">Cost per 1,000 match views</dt>
  <dd>
    <p class="formula-line">cost per 1,000 match views = fee ÷ (estimated season views ÷ 1,000)</p>
    <p class="example">Example: €5,000 ÷ (19,872 ÷ 1,000) ≈ €252 per 1,000 match views.</p>
    <p>An estimate for comparing teams, not a price. It ignores social media, VOD views and anything outside tracked matches.</p>
  </dd>

  <dt id="match-load">Match load (prototype)</dt>
  <dd>
    <p>How demanding a team's schedule has been this season, from completed matches (forfeits excluded).</p>
    <p class="formula-line">maps = sum of the map score (a 2–1 best-of-3 = 3 maps)</p>
    <p class="formula-line">busiest stretch = most matches started within any 7-day window</p>
    <p class="formula-line">typical rest = median days between match days</p>
    <p class="formula-line">short rest = match starts under <?= Metrics::SHORT_REST_HOURS ?>h after the previous one · rested = <?= Metrics::RESTED_HOURS ?>h or more</p>
    <p>"Form under load" compares win rate on short rest with win rate when rested, once each has <?= Metrics::MIN_FORM_SAMPLE ?>+ matches.</p>
    <p class="example">Example: 9 short-rest matches with 4 wins (44%) vs 12 rested matches with 8 wins (67%).</p>
    <p>These are workload signals from the schedule, not a medical or performance assessment. They don't account for opponent strength, travel, online vs LAN, or roster changes.</p>
  </dd>
</dl>

<h2>Data limits</h2>
<ul>
  <li><strong>Official streams on Twitch and Kick only.</strong> YouTube, other platforms and unofficial co-streams are not counted, so real audiences are higher.</li>
  <li><strong>Lower-tier data is thin.</strong> Many tier-3 matches have no official stream, and a few matches can swing a team's numbers.</li>
  <li><strong>Viewers are sampled every 10 minutes</strong>, so short peaks between samples are missed.</li>
  <li><strong>Teams' countries come from PandaScore</strong> and are missing for a few teams.</li>
  <li><strong>Audience tracking started in October 2026.</strong> History fills in as matches are played.</li>
  <li><strong>No personal data.</strong> We store total viewer counts per stream only, never anything about individual viewers, chat or accounts. Raw samples are deleted after 24 hours; only per-match summaries are kept.</li>
</ul>

<h2>Sources and attribution</h2>
<ul>
  <li><a href="https://pandascore.co">PandaScore</a>: tournaments, teams, schedules, results and each match's stream list. We publish our own statistics, not PandaScore's raw data.</li>
  <li><a href="https://dev.twitch.tv/docs/api/">Twitch API</a> and <a href="https://docs.kick.com">Kick API</a>: live viewer counts of official streams.</li>
  <li><a href="https://liquipedia.net/counterstrike/">Liquipedia</a>: team sponsors, entered by hand with a link to the source page. Liquipedia content is licensed under <a href="https://creativecommons.org/licenses/by-sa/3.0/">CC-BY-SA 3.0</a>.</li>
</ul>
</div>
<?php View::footer();
