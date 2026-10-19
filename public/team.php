<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$metrics = new Metrics(db());
$team = $metrics->teamTable()[$id] ?? null;

if (!$team) {
    http_response_code(404);
    View::header('Team not found');
    echo '<h1>Team not found</h1><p>This team has no tracked matches this season. <a href="index.php">Back to rankings</a>.</p>';
    View::footer();
    exit;
}

$detail = $metrics->teamDetail($id);
$sponsors = json_decode((string) @file_get_contents(APP_ROOT . '/data/sponsors.json'), true)['teams'][(string) $id] ?? [];
$tierLabel = Verdict::tierLabel($team['tier']);
$enough = $team['enough_data'];
$fee = isset($_GET['fee']) && is_numeric($_GET['fee']) && $_GET['fee'] > 0 ? (float) $_GET['fee'] : null;
$live = $detail['live'];
$historyAvailable = $metrics->tierAverages() !== []; // false when no summaries exist (e.g. table wiped)

// Opponent + score line for a match row, from this team's side.
$resultLine = function (array $m) use ($id): array {
    $us = $m['team1_id'] == $id ? $m['team1_score'] : $m['team2_score'];
    $them = $m['team1_id'] == $id ? $m['team2_score'] : $m['team1_score'];
    $outcome = $m['winner_id'] === null ? 'draw' : ($m['winner_id'] == $id ? 'win' : 'loss');
    return [$outcome, $us !== null ? "{$us}–{$them}" : ''];
};

View::header($team['name']);
?>
<p class="crumbs"><a href="index.php">Team rankings</a></p>
<header class="team-head">
  <h1><?= Format::e($team['name']) ?> <?php if ($team['location']): ?><span class="flag" aria-label="Country: <?= Format::e($team['location']) ?>"><?= Format::flag($team['location']) ?></span><?php endif; ?></h1>
  <p class="meta"><?= Format::e($team['location'] ?: 'Country unknown') ?> · <?= $tierLabel ?></p>
  <?php if ($live): ?>
    <p class="live"><span class="dot" aria-hidden="true"></span>Live<?= $live['viewers'] !== null ? ' · ' . Format::int($live['viewers']) . ' watching' : ' · audience not tracked' ?></p>
  <?php endif; ?>
</header>

<p class="verdict"><?= Format::e(Verdict::team($team)) ?></p>

<section class="cards" aria-label="Key figures">
  <div class="card">
    <h2>Avg viewers per match</h2>
    <p class="figure"><?= $enough ? Format::int($team['avg_viewers']) : 'Not enough data' ?></p>
    <?= View::formula('avg-viewers') ?>
  </div>
  <div class="card">
    <h2>vs tier average</h2>
    <p class="figure <?= $enough && $team['vs_tier'] !== null ? ($team['vs_tier'] >= 0 ? 'up' : 'down') : '' ?>"><?= $enough ? Format::pct($team['vs_tier'], true) : 'Not enough data' ?></p>
    <?= View::formula('vs-tier') ?>
  </div>
  <div class="card">
    <h2>Win rate</h2>
    <p class="figure"><?= $team['played'] ? Format::pct($team['win_rate']) : '–' ?></p>
    <p class="sub"><?= (int) $team['wins'] ?> of <?= (int) $team['played'] ?> this season</p>
    <?= View::formula('win-rate') ?>
  </div>
  <div class="card">
    <h2>Matches tracked</h2>
    <p class="figure"><?= (int) $team['tracked'] ?></p>
    <p class="sub">with audience data</p>
    <?= View::formula('tracked') ?>
  </div>
</section>

<section class="fee" aria-labelledby="fee-h">
  <h2 id="fee-h">Fee check</h2>
  <?php if ($enough): ?>
    <form method="get" id="fee-form"
          data-season-views="<?= (int) round(Metrics::seasonViews($team)) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <label for="fee">Your fee (€)</label>
      <input id="fee" name="fee" type="number" min="1" step="any" inputmode="decimal" value="<?= $fee !== null ? Format::e((string) $fee) : '' ?>" placeholder="e.g. 5000">
      <noscript><button type="submit">Calculate</button></noscript>
    </form>
    <p class="fee-result" id="fee-result" aria-live="polite"><?php
      if ($fee !== null) {
          echo '≈ ' . Format::money(Metrics::costPerThousand($fee, $team)) . ' per 1,000 match views';
      } ?></p>
    <p class="note">Estimate. Based on ≈ <?= Format::int(Metrics::seasonViews($team)) ?> match views this season (<?= Format::int($team['avg_viewers']) ?> avg viewers × <?= (int) $team['tracked'] ?> tracked matches). <?= View::formula('cost-per-1000', 'See the formula') ?></p>
  <?php else: ?>
    <p class="muted">Not enough data yet. The fee check needs <?= Metrics::minTracked() ?> tracked matches; this team has <?= (int) $team['tracked'] ?>. <?= View::formula('cost-per-1000', 'See the formula') ?></p>
  <?php endif; ?>
</section>

<section class="charts">
  <div class="chart">
    <h2>Avg viewers per match</h2>
    <p class="caption">Average live viewers across official streams, per tracked match.</p>
    <?php if ($enough && $detail['viewers']): ?>
      <canvas id="viewers-chart" height="180" role="img" aria-label="Line chart of average viewers per tracked match"></canvas>
    <?php elseif (!$historyAvailable): ?>
      <p class="muted chart-empty">Viewership history unavailable.</p>
    <?php else: ?>
      <p class="muted chart-empty">Not enough data yet (<?= (int) $team['tracked'] ?> of <?= Metrics::minTracked() ?> tracked matches).</p>
    <?php endif; ?>
  </div>
  <div class="chart">
    <h2>Win rate, rolling last 10</h2>
    <p class="caption">Share of the previous 10 decided matches won, after each match (last 30 shown).</p>
    <?php if ($detail['rolling_win_rate']): ?>
      <canvas id="winrate-chart" height="180" role="img" aria-label="Bar chart of rolling 10-match win rate"></canvas>
    <?php else: ?>
      <p class="muted chart-empty">No decided matches this season.</p>
    <?php endif; ?>
  </div>
</section>

<section class="lists">
  <div>
    <h2>Recent results</h2>
    <?php if ($detail['recent']): ?>
    <ul class="matches">
      <?php foreach ($detail['recent'] as $m): [$outcome, $score] = $resultLine($m); ?>
        <li>
          <span class="badge <?= $outcome ?>"><?= ucfirst($outcome) ?></span>
          <span class="score"><?= $score ?></span>
          vs <?= $m['opp_id'] ? '<a href="team.php?id=' . (int) $m['opp_id'] . '">' . Format::e($m['opp_name']) . '</a>' : 'TBD' ?>
          <?= $m['forfeit'] ? '<span class="muted">(forfeit)</span>' : '' ?>
          <span class="muted"><?= Format::date($m['played_at']) ?> · <?= Format::e($m['league_name']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?><p class="muted">No results yet.</p><?php endif; ?>
  </div>
  <div>
    <h2>Upcoming matches</h2>
    <?php if ($detail['upcoming']): ?>
    <ul class="matches">
      <?php foreach ($detail['upcoming'] as $m): ?>
        <li>
          <?= $m['status'] === 'running' ? '<span class="badge live-badge">Live</span>' : '<span class="muted">' . Format::dateTime($m['played_at']) . '</span>' ?>
          vs <?= $m['opp_id'] ? '<a href="team.php?id=' . (int) $m['opp_id'] . '">' . Format::e($m['opp_name']) . '</a>' : 'TBD' ?>
          <span class="muted">· <?= Format::e($m['league_name']) ?><?= $m['audience_note'] === 'no_official_stream' ? ' · audience not tracked' : '' ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?><p class="muted">Nothing scheduled.</p><?php endif; ?>
  </div>
  <div>
    <h2>Known sponsors</h2>
    <?php if ($sponsors): ?>
      <ul class="sponsors">
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
    'viewers'  => $enough ? $detail['viewers'] : [],
    'winRate'  => $detail['rolling_win_rate'],
], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="assets/team.js"></script>
<?php View::footer();
