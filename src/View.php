<?php
declare(strict_types=1);

// Shared page chrome.
final class View
{
    public static function header(string $title, string $active = ''): void
    {
        $nav = ['index.php' => 'Team rankings', 'methodology.php' => 'Methodology'];
        ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= Format::e($title) ?> · Sponsor Lens</title>
<meta name="description" content="Which tier-2 and tier-3 Counter-Strike 2 teams draw the most viewers, and what a sponsorship costs per 1,000 match views.">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="site">
  <a class="brand" href="index.php">Sponsor Lens</a>
  <nav>
    <?php foreach ($nav as $href => $label): ?>
      <a href="<?= $href ?>"<?= $active === $href ? ' aria-current="page"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
  </nav>
</header>
<main>
        <?php
    }

    public static function footer(): void
    {
        $synced = db()->getKv('last_pandascore_sync');
        ?>
</main>
<footer class="site">
  <p>
    Match data: <a href="https://pandascore.co">PandaScore</a>.
    Live viewers: official streams on <a href="https://www.twitch.tv">Twitch</a> and <a href="https://kick.com">Kick</a>.
    Sponsors: <a href="https://liquipedia.net/counterstrike/">Liquipedia</a> (<a href="https://creativecommons.org/licenses/by-sa/3.0/">CC-BY-SA 3.0</a>).
  </p>
  <p>Free, non-commercial project. Figures are signals, not predictions. <a href="methodology.php">How we calculate them</a>.<?= $synced ? ' Last updated ' . Format::dateTime($synced) . '.' : '' ?></p>
</footer>
</body>
</html>
        <?php
    }

    // Link a figure to its formula on the Methodology page.
    public static function formula(string $anchor, string $label = 'How is this calculated?'): string
    {
        return '<a class="formula" href="methodology.php#' . Format::e($anchor) . '">' . Format::e($label) . '</a>';
    }
}
