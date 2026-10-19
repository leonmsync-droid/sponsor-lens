<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$tracked = array_merge(...array_column(config('games'), 'tiers'));
$tier = in_array($_GET['tier'] ?? '', $tracked, true) ? $_GET['tier'] : null;
$showAll = !empty($_GET['all']);
$limit = 100;

$metrics = new Metrics(db());
$rows = $metrics->rankings($tier);
$shown = $showAll ? $rows : array_slice($rows, 0, $limit);
$ranked = array_values(array_filter($rows, fn($r) => $r['enough_data']));
$maxAvg = $ranked ? max(array_column($ranked, 'avg_viewers')) : 0;
$tierAvg = $metrics->tierAverages();
$highlight = config('site.highlight_countries');
$rank = 0;

// Filter links keep the current tier.
$link = fn(array $change) => '?' . http_build_query(array_filter(array_merge(['tier' => $tier], $change)));

View::header('Team rankings', 'index.php');
?>
<section class="hero panel has-art">
  <img class="hero-art" src="assets/hero-cs2.jpg" alt="" width="369" height="492">
  <p class="eyebrow"><span class="tag">CS2 · This season</span> <?= $tier ? Verdict::tierLabel($tier) : 'Tiers ' . strtoupper(implode(', ', $tracked)) ?></p>
  <h1><span class="accent">Most watched</span> tier-2 and tier-3 CS2 teams</h1>
  <p class="lede">Live audience rankings from official Twitch and Kick streams, for sponsors and the teams they back.</p>
  <p class="verdict now"><span class="now-label">Right now</span><?= Format::e(Verdict::rankings($rows, $tier)) ?></p>
  <?php if ($ranked): $top = array_slice($ranked, 0, 3); $others = count($ranked) - count($top); ?>
    <div class="stack">
      <span class="avatars">
        <?php foreach ($top as $t): ?><a href="team.php?id=<?= (int) $t['id'] ?>" title="<?= Format::e($t['name']) ?>"><?= View::avatar($t, 40) ?></a><?php endforeach; ?>
        <?php if ($others > 0): ?><span class="more">+<?= $others ?></span><?php endif; ?>
      </span>
      <span class="stack-text"><b><?= Format::e(implode(', ', array_column($top, 'name'))) ?></b><?= $others > 0 ? ' and ' . $others . ' more ranked' : '' ?></span>
    </div>
  <?php endif; ?>
</section>

<section class="stats" aria-label="Season overview">
  <div class="stat panel">
    <span class="label">Teams ranked</span>
    <span class="value"><?= count($ranked) ?></span>
    <span class="sub">of <?= count($rows) ?> active</span>
  </div>
  <div class="stat panel">
    <span class="label">Top audience</span>
    <span class="value"><?= $ranked ? Format::compact($ranked[0]['avg_viewers']) : '–' ?></span>
    <span class="sub"><?= $ranked ? Format::e($ranked[0]['name']) . ' · avg per match' : 'Not enough data yet' ?></span>
  </div>
  <div class="stat panel wide">
    <span class="label"><a href="methodology.php#tier-average">Tier averages</a></span>
    <span class="tier-avgs">
      <?php foreach ($tracked as $t): ?>
        <span><?= View::tierTag($t) ?><b><?= isset($tierAvg[$t]) ? Format::compact($tierAvg[$t]['avg']) : '–' ?></b></span>
      <?php endforeach; ?>
    </span>
    <span class="sub">avg viewers per match</span>
  </div>
</section>

<nav class="filters" aria-label="Filter teams">
  <div class="segmented" role="group" aria-label="Tier">
    <a href="<?= Format::e($link(['tier' => null])) ?>"<?= $tier === null ? ' aria-current="true"' : '' ?>>All tiers</a>
    <?php foreach ($tracked as $t): ?>
      <a href="<?= Format::e($link(['tier' => $t])) ?>"<?= $tier === $t ? ' aria-current="true"' : '' ?>><?= Verdict::tierLabel($t) ?></a>
    <?php endforeach; ?>
  </div>
</nav>

<?php if (!$rows): ?>
  <p class="empty">No teams match these filters.</p>
<?php else: ?>
<table class="rankings">
  <caption class="sr-only">Teams ranked by average viewers per match</caption>
  <thead>
    <tr>
      <th scope="col" class="rank">#</th>
      <th scope="col">Team</th>
      <th scope="col" class="viewers"><a href="methodology.php#avg-viewers">Avg viewers / match</a></th>
      <th scope="col" class="num"><a href="methodology.php#vs-tier">vs tier avg</a></th>
      <th scope="col" class="num"><a href="methodology.php#win-rate">Win rate</a></th>
      <th scope="col" class="num"><a href="methodology.php#tracked">Tracked</a></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($shown as $r): $isHl = in_array($r['location'], $highlight, true); ?>
    <tr<?= $isHl ? ' class="highlight"' : '' ?>>
      <td class="rank"><?= $r['enough_data'] ? ++$rank : '–' ?></td>
      <td class="team">
        <a href="team.php?id=<?= (int) $r['id'] ?>">
          <?= View::avatar($r) ?>
          <span class="name"><?= Format::e($r['name']) ?></span>
        </a>
        <span class="meta">
          <?= View::flag($r['location']) ?>
          <?= View::tierTag($r['tier']) ?>
        </span>
      </td>
      <?php if ($r['enough_data']): ?>
        <td class="viewers">
          <span class="n"><?= Format::int($r['avg_viewers']) ?></span>
          <?= View::bar($maxAvg ? $r['avg_viewers'] / $maxAvg : 0) ?>
        </td>
        <td class="num vs"><?= View::delta($r['vs_tier']) ?></td>
      <?php else: ?>
        <td class="viewers pending" colspan="2"><span>Not enough data</span><span class="sub"><?= (int) $r['tracked'] ?>/<?= Metrics::minTracked() ?> tracked</span></td>
      <?php endif; ?>
      <td class="num wr"><span class="k">Win rate </span><?= $r['played'] ? Format::pct($r['win_rate']) : '–' ?></td>
      <td class="num tr"><span class="k">Tracked </span><?= (int) $r['tracked'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if (!$showAll && count($rows) > $limit): ?>
  <p class="more"><a class="button ghost" href="<?= Format::e($link(['all' => 1])) ?>">Show all <?= count($rows) ?> teams</a></p>
<?php endif; ?>
<p class="note">Teams that played in the last 120 days. A team is ranked once it has <?= Metrics::minTracked() ?>+ tracked matches; the rest follow, ordered by matches tracked, then win rate.</p>
<?php endif; ?>
<?php View::footer();
