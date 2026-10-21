<?php
declare(strict_types=1);

// Expanded row on the rankings table: a short summary of one team, loaded when the row is opened.
// Returns an HTML fragment; the full picture stays on team.php.
require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, max-age=300');

$id = (int) ($_GET['id'] ?? 0);
$metrics = new Metrics(db());
$team = $metrics->teamTable()[$id] ?? null;
if (!$team) {
    http_response_code(404);
    exit;
}

$detail = $metrics->teamDetail($id);
$viewers = $team['enough_data'] ? $detail['viewers'] : [];
$best = $viewers ? array_reduce($viewers, fn($b, $v) => $b === null || $v['viewers'] > $b['viewers'] ? $v : $b) : null;
$form = array_slice($detail['recent'], 0, 5);
$next = $detail['upcoming'][0] ?? null;
$outcome = fn(array $m) => $m['winner_id'] === null ? 'draw' : ((int) $m['winner_id'] === $id ? 'win' : 'loss');
?>
<div class="preview-body">
  <p class="preview-verdict"><?= Format::e(Verdict::team($team)) ?></p>
  <dl class="preview-stats">
    <div>
      <dt>Record</dt>
      <dd><?= $team['played'] ? (int) $team['wins'] . '–' . ((int) $team['played'] - (int) $team['wins']) : '–' ?></dd>
    </div>
    <div>
      <dt>Last <?= count($form) ?: 5 ?></dt>
      <dd class="form">
        <?php foreach (array_reverse($form) as $m): $o = $outcome($m); ?>
          <span class="badge <?= $o ?>" title="<?= ucfirst($o) ?> vs <?= Format::e($m['opp_name'] ?? 'TBD') ?>, <?= Format::date($m['played_at']) ?>"><?= strtoupper($o[0]) ?></span>
        <?php endforeach; ?>
        <?= $form ? '' : '–' ?>
      </dd>
    </div>
    <div>
      <dt>Best match audience</dt>
      <dd><?= $best ? Format::int($best['viewers']) . '<small>' . Format::date($best['date']) . '</small>' : '–' ?></dd>
    </div>
    <div>
      <dt>Next match</dt>
      <dd><?php if (!$next): ?>–<?php elseif ($next['status'] === 'running'): ?><span class="badge live-badge">Live</span> <?= Format::e($next['opp_name'] ?? 'TBD') ?><?php else: ?><?= Format::e($next['opp_name'] ?? 'TBD') ?><small><?= Format::e(gmdate('j M, H:i', strtotime($next['played_at']))) ?> UTC</small><?php endif; ?></dd>
    </div>
  </dl>
  <?php if (count($viewers) >= 2):
      $max = max(array_column($viewers, 'viewers')) ?: 1;
      $n = count($viewers);
      $points = implode(' ', array_map(fn($i, $v) => round($i / ($n - 1) * 300, 1) . ',' . round(46 - $v['viewers'] / $max * 42, 1), array_keys($viewers), $viewers));
  ?>
    <figure class="preview-spark">
      <svg viewBox="0 0 300 50" preserveAspectRatio="none" aria-hidden="true"><polyline points="<?= $points ?>"/></svg>
      <figcaption>Avg viewers, each tracked match this season</figcaption>
    </figure>
  <?php endif; ?>
  <a class="button" href="team.php?id=<?= $id ?>">Full team page</a>
</div>
