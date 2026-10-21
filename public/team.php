<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$metrics = new Metrics(db());
$team = $metrics->teamTable()[$id] ?? null;

if (!$team) {
    http_response_code(404);
    View::header('Team not found');
    echo '<section class="hero"><h1>Team not found</h1><p class="verdict">This team has no tracked matches this season.</p><p><a class="button" href="index.php">Back to rankings</a></p></section>';
    View::footer();
    exit;
}

$detail = $metrics->teamDetail($id);
$load = $metrics->matchLoad($id);
$sponsors = json_decode((string) @file_get_contents(APP_ROOT . '/data/sponsors.json'), true)['teams'][(string) $id] ?? [];
$enough = $team['enough_data'];
$fee = isset($_GET['fee']) && is_numeric($_GET['fee']) && $_GET['fee'] > 0 ? (float) $_GET['fee'] : null;
$live = $detail['live'];
$historyAvailable = $metrics->tierAverages() !== []; // false when no summaries exist (e.g. table wiped)
$against = $team['compared_tier'] ? Verdict::tierLabel($team['compared_tier']) : 'its event levels';

// Same fee spread over a typical team's views in the same tier(s), for the fee check comparison.
$seasonViews = Metrics::seasonViews($team);
$tierViews = $enough && $team['expected'] ? $team['expected'] * $team['tracked'] : null;
$perThousand = fn(float $fee, ?float $views) => $views ? $fee / ($views / 1000) : null;

// Opponent + score line for a match row, from this team's side.
$resultLine = function (array $m) use ($id): array {
    $us = $m['team1_id'] == $id ? $m['team1_score'] : $m['team2_score'];
    $them = $m['team1_id'] == $id ? $m['team2_score'] : $m['team1_score'];
    $outcome = $m['winner_id'] === null ? 'draw' : ($m['winner_id'] == $id ? 'win' : 'loss');
    return [$outcome, $us !== null ? "{$us}–{$them}" : ''];
};
$opponent = fn(array $m) => $m['opp_id']
    ? '<a href="team.php?id=' . (int) $m['opp_id'] . '">' . View::avatar(['id' => $m['opp_id']], 20) . Format::e($m['opp_name']) . '</a>'
    : '<span class="muted">TBD</span>';

View::header($team['name']);
?>
<p class="crumbs"><a href="index.php">← Rankings</a></p>

<section class="hero panel team-head">
  <?php if ($live): ?>
    <p class="eyebrow"><span class="tag">LIVE</span> <?= $live['viewers'] !== null ? Format::compact($live['viewers']) . ' watching now' : 'Audience not tracked for this match' ?></p>
  <?php endif; ?>
  <div class="team-id">
    <?= View::avatar($team, 88) ?>
    <div>
      <h1><?= Format::e($team['name']) ?></h1>
      <p class="meta">
        <?= View::flag($team['location'], 16) ?>
        <?= Format::e(Format::country($team['location'])) ?><?php if ($team['tier'] && !empty($team['tier_matches'])): ?> · Most often at <?= Verdict::tierLabel($team['tier']) ?> (<?= (int) $team['tier_matches'] ?> of <?= (int) $team['played'] ?> matches)<?php endif; ?>
      </p>
    </div>
  </div>
  <p class="verdict"><?= Format::e(Verdict::team($team)) ?></p>
</section>

<section class="cards" aria-label="Key figures">
  <div class="card panel">
    <h2>Avg viewers per match</h2>
    <?php if ($enough): ?>
      <p class="figure"><?= Format::int($team['avg_viewers']) ?></p>
      <p class="sub"><?= $team['expected'] ? Format::int($team['expected']) . ' average at ' . $against : '' ?></p>
    <?php else: ?>
      <?= View::progress((int) $team['tracked']) ?>
    <?php endif; ?>
    <?= View::formula('avg-viewers') ?>
  </div>
  <div class="card panel">
    <h2>vs similar events</h2>
    <?php if ($enough): ?>
      <p class="figure"><?= View::delta($team['vs_tier']) ?></p>
      <p class="sub">compared with the average at <?= $against ?></p>
    <?php else: ?>
      <?= View::progress((int) $team['tracked']) ?>
    <?php endif; ?>
    <?= View::formula('vs-tier') ?>
  </div>
  <div class="card panel">
    <h2>Win rate</h2>
    <p class="figure"><?= $team['played'] ? Format::pct($team['win_rate']) : '–' ?></p>
    <?= $team['played'] ? View::bar($team['win_rate'], 'neutral') : '' ?>
    <p class="sub"><?= (int) $team['wins'] ?> of <?= (int) $team['played'] ?> this season</p>
    <?= View::formula('win-rate') ?>
  </div>
  <div class="card panel">
    <h2>Matches tracked</h2>
    <p class="figure"><?= (int) $team['tracked'] ?></p>
    <p class="sub">with audience data</p>
    <?= View::formula('tracked') ?>
  </div>
</section>

<section class="fee panel" aria-labelledby="fee-h">
  <div class="fee-intro">
    <h2 id="fee-h">Fee check</h2>
    <p class="muted">What would a sponsorship cost per 1,000 match views?</p>
  </div>
  <?php if ($enough): ?>
    <form method="get" id="fee-form" data-season-views="<?= (int) round($seasonViews) ?>" data-tier-views="<?= (int) round((float) $tierViews) ?>" data-tier-label="<?= Format::e($against) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <label for="fee">Your fee</label>
      <div class="money-input"><span aria-hidden="true">€</span><input id="fee" name="fee" type="number" min="1" step="any" inputmode="decimal" value="<?= $fee !== null ? Format::e((string) $fee) : '' ?>" placeholder="5,000"></div>
      <noscript><button class="button" type="submit">Calculate</button></noscript>
    </form>
    <div class="fee-out" aria-live="polite">
      <p class="fee-result" id="fee-result"><?= $fee !== null ? '≈ ' . Format::money($perThousand($fee, $seasonViews)) . ' <span>per 1,000 match views</span>' : '<span>Enter a fee to see the cost per 1,000 match views</span>' ?></p>
      <p class="fee-compare" id="fee-compare"><?php
        if ($fee !== null && $tierViews) {
            echo 'A typical team at ' . Format::e($against) . ': ≈ ' . Format::money($perThousand($fee, $tierViews)) . ' per 1,000 at the same fee.';
        } ?></p>
    </div>
    <p class="note">Estimate based on ≈ <?= Format::int($seasonViews) ?> match views this season (<?= Format::int($team['avg_viewers']) ?> avg viewers × <?= (int) $team['tracked'] ?> tracked matches). <?= View::formula('cost-per-1000', 'See the formula') ?></p>
  <?php else: ?>
    <div class="fee-pending">
      <?= View::progress((int) $team['tracked']) ?>
      <p class="muted">The fee check unlocks at <?= Metrics::minTracked() ?> tracked matches. <?= View::formula('cost-per-1000', 'See the formula') ?></p>
    </div>
  <?php endif; ?>
</section>

<section class="charts">
  <div class="panel chart">
    <h2>Avg viewers per match</h2>
    <p class="caption">One point per tracked match, spaced by date<?= $enough && $team['expected'] ? ', against the average at ' . $against . ' (dashed)' : '' ?>. A dotted line means no matches for over a week.</p>
    <?php if ($enough && $detail['viewers']): ?>
      <div class="canvas"><canvas id="viewers-chart" role="img" aria-label="Line chart of average viewers per tracked match"></canvas></div>
    <?php elseif (!$historyAvailable): ?>
      <p class="chart-empty">Viewership history unavailable.</p>
    <?php else: ?>
      <div class="chart-empty"><?= View::progress((int) $team['tracked']) ?></div>
    <?php endif; ?>
  </div>
  <div class="panel chart">
    <h2>Win rate, rolling last 10</h2>
    <p class="caption">Share of the previous 10 decided matches won, after each match (last 30 shown).</p>
    <?php if ($detail['rolling_win_rate']): ?>
      <div class="canvas"><canvas id="winrate-chart" role="img" aria-label="Bar chart of rolling 10-match win rate"></canvas></div>
    <?php else: ?>
      <p class="chart-empty">No decided matches this season.</p>
    <?php endif; ?>
  </div>
</section>

<section class="panel load" aria-labelledby="load-h">
  <div class="load-head">
    <div>
      <h2 id="load-h">Match load</h2>
      <p class="caption">How hard the schedule has been this season. <?= View::formula('match-load', 'How is this calculated?') ?></p>
    </div>
    <span class="tag ghost">Prototype</span>
  </div>
  <p class="load-verdict"><?= Format::e(Verdict::load($load)) ?></p>
  <?php if ($load['matches']): ?>
  <div class="load-stats">
    <div><span class="label">Last 28 days</span><span class="value"><?= (int) $load['recent']['matches'] ?></span><span class="sub"><?= (int) $load['recent']['maps'] ?> maps</span></div>
    <div><span class="label">Busiest 7 days</span><span class="value"><?= (int) $load['busiest']['matches'] ?></span><span class="sub"><?= (int) $load['busiest']['maps'] ?> maps</span></div>
    <div><span class="label">Typical rest</span><span class="value"><?= $load['median_rest'] !== null ? Format::e(rtrim(rtrim(number_format($load['median_rest'], 1), '0'), '.')) . 'd' : '–' ?></span><span class="sub">between match days</span></div>
    <div><span class="label">Season</span><span class="value"><?= (int) $load['matches'] ?></span><span class="sub"><?= (int) $load['maps'] ?> maps</span></div>
  </div>
  <div class="load-body">
    <div class="load-chart">
      <p class="caption">Maps played per week, last 16 weeks.</p>
      <div class="canvas"><canvas id="load-chart" role="img" aria-label="Bar chart of maps played per week"></canvas></div>
    </div>
    <div class="load-form">
      <p class="caption">Win rate by rest before the match.</p>
      <?php foreach (['short' => 'Short rest (under ' . Metrics::SHORT_REST_HOURS . 'h)', 'rested' => 'Rested (' . Metrics::RESTED_HOURS . 'h or more)'] as $key => $label): $g = $load[$key]; ?>
        <div class="form-row">
          <div class="form-label"><span><?= $label ?></span><b><?= $g['rate'] !== null ? Format::pct($g['rate']) : '–' ?></b></div>
          <?= View::bar($g['rate'] ?? 0, $key === 'short' ? 'warn' : '') ?>
          <span class="sub"><?= $g['rate'] !== null ? (int) $g['won'] . ' of ' . (int) $g['played'] . ' won' : 'Needs ' . Metrics::MIN_FORM_SAMPLE . '+ matches (' . (int) $g['played'] . ' so far)' ?></span>
        </div>
      <?php endforeach; ?>
      <p class="note">Doesn't account for opponent strength. A workload signal, not a medical or performance assessment.</p>
    </div>
  </div>
  <?php endif; ?>
</section>

<section class="lists">
  <div class="panel">
    <h2>Recent results</h2>
    <?php if ($detail['recent']): ?>
    <ul class="matches">
      <?php foreach ($detail['recent'] as $m): [$outcome, $score] = $resultLine($m); ?>
        <li>
          <span class="badge <?= $outcome ?>" title="<?= ucfirst($outcome) ?>"><?= $outcome === 'win' ? 'W' : ($outcome === 'loss' ? 'L' : 'D') ?></span>
          <span class="opp"><?= $opponent($m) ?><span class="muted"><?= Format::date($m['played_at']) ?> · <?= Format::e($m['league_name']) ?><?= $m['forfeit'] ? ' · forfeit' : '' ?></span></span>
          <span class="score"><?= $score ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?><p class="muted">No results yet.</p><?php endif; ?>
  </div>
  <div class="panel">
    <h2>Upcoming</h2>
    <?php if ($detail['upcoming']): ?>
    <ul class="matches">
      <?php foreach ($detail['upcoming'] as $m): ?>
        <li>
          <?= $m['status'] === 'running' ? '<span class="badge live-badge">Live</span>' : '<span class="when">' . Format::e(gmdate('j M', strtotime($m['played_at']))) . '<small>' . Format::e(gmdate('H:i', strtotime($m['played_at']))) . '</small></span>' ?>
          <span class="opp"><?= $opponent($m) ?><span class="muted"><?= Format::e($m['league_name']) ?><?= $m['audience_note'] === 'no_official_stream' ? ' · audience not tracked' : '' ?></span></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="note">Times in UTC.</p>
    <?php else: ?><p class="muted">Nothing scheduled.</p><?php endif; ?>
  </div>
  <div class="panel">
    <h2>Known sponsors</h2>
    <?php if ($sponsors): ?>
      <ul class="tags">
        <?php foreach ($sponsors['sponsors'] ?? [] as $s): ?><li><?= Format::e($s) ?></li><?php endforeach; ?>
      </ul>
      <p class="note">Source: <a href="<?= Format::e($sponsors['source']) ?>">Liquipedia</a> (<a href="https://creativecommons.org/licenses/by-sa/3.0/">CC-BY-SA 3.0</a>)<?= !empty($sponsors['checked']) ? ', checked ' . Format::e($sponsors['checked']) : '' ?>.</p>
    <?php else: ?>
      <p class="muted">No sponsors recorded yet.</p>
    <?php endif; ?>
  </div>
</section>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script type="application/json" id="team-data"><?= json_encode([
    'viewers'   => $enough ? $detail['viewers'] : [],
    'tierAvg'   => $enough && $team['expected'] ? (int) round($team['expected']) : null,
    'tierLabel' => 'Average at ' . $against,
    'winRate'   => $detail['rolling_win_rate'],
    'load'      => $load['weeks'] ?? [],
], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="assets/team.js?v=<?= filemtime(__DIR__ . '/assets/team.js') ?>"></script>
<?php View::footer();
