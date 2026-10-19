<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$tracked = array_merge(...array_column(config('games'), 'tiers'));
$tier = in_array($_GET['tier'] ?? '', $tracked, true) ? $_GET['tier'] : null;
$highlightOnly = !empty($_GET['hl']);
$showAll = !empty($_GET['all']);
$limit = 100;

$metrics = new Metrics(db());
$rows = $metrics->rankings($tier, $highlightOnly);
$shown = $showAll ? $rows : array_slice($rows, 0, $limit);
$highlight = config('site.highlight_countries');
$rank = 0;

View::header('Team rankings', 'index.php');
?>
<h1>Most-watched tier-2 and tier-3 CS2 teams this season</h1>
<p class="verdict"><?= Format::e(Verdict::rankings($rows, $tier)) ?></p>

<form class="filters" method="get">
  <label>Tier
    <select name="tier">
      <option value="">All tracked tiers</option>
      <?php foreach ($tracked as $t): ?>
        <option value="<?= $t ?>"<?= $tier === $t ? ' selected' : '' ?>><?= Verdict::tierLabel($t) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="toggle">
    <input type="checkbox" name="hl" value="1"<?= $highlightOnly ? ' checked' : '' ?>>
    <?= Format::e(config('site.highlight_label')) ?> only
  </label>
  <noscript><button type="submit">Apply</button></noscript>
</form>
<script src="assets/filters.js"></script>

<?php if (!$rows): ?>
  <p class="empty">No teams match these filters.</p>
<?php else: ?>
<div class="table-wrap">
<table class="rankings">
  <caption class="sr-only">Teams ranked by average viewers per match</caption>
  <thead>
    <tr>
      <th scope="col">#</th>
      <th scope="col">Team</th>
      <th scope="col" class="num"><a href="methodology.php#avg-viewers">Avg viewers / match</a></th>
      <th scope="col" class="num stand-out"><a href="methodology.php#vs-tier">vs tier average</a></th>
      <th scope="col" class="num"><a href="methodology.php#win-rate">Win rate</a></th>
      <th scope="col" class="num"><a href="methodology.php#tracked">Matches tracked</a></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($shown as $r):
      $isHl = in_array($r['location'], $highlight, true);
      $vsClass = $r['enough_data'] && $r['vs_tier'] !== null ? ($r['vs_tier'] > 0 ? 'up' : ($r['vs_tier'] < 0 ? 'down' : '')) : ''; ?>
    <tr<?= $isHl ? ' class="highlight"' : '' ?>>
      <td><?= $r['enough_data'] ? ++$rank : '–' ?></td>
      <td>
        <a href="team.php?id=<?= (int) $r['id'] ?>"><?= Format::e($r['name']) ?></a>
        <?php if ($r['location']): ?><span class="flag" title="<?= Format::e($r['location']) ?>" aria-label="Country: <?= Format::e($r['location']) ?>"><?= Format::flag($r['location']) ?></span><?php endif; ?>
        <span class="tier"><?= Verdict::tierLabel($r['tier']) ?></span>
      </td>
      <?php if ($r['enough_data']): ?>
        <td class="num"><?= Format::int($r['avg_viewers']) ?></td>
        <td class="num stand-out <?= $vsClass ?>"><?= Format::pct($r['vs_tier'], true) ?></td>
      <?php else: ?>
        <td class="num muted" colspan="2">Not enough data</td>
      <?php endif; ?>
      <td class="num"><?= $r['played'] ? Format::pct($r['win_rate']) : '–' ?></td>
      <td class="num"><?= (int) $r['tracked'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (!$showAll && count($rows) > $limit): ?>
  <p><a href="?<?= Format::e(http_build_query(array_filter(['tier' => $tier, 'hl' => $highlightOnly ? 1 : null, 'all' => 1]))) ?>">Show all <?= count($rows) ?> teams</a></p>
<?php endif; ?>
<p class="note">Teams that played in the last 120 days. Audience needs <?= Metrics::minTracked() ?>+ tracked matches before a team is ranked; others are ordered by matches tracked, then win rate.</p>
<?php endif; ?>
<?php View::footer();
