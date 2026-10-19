<?php
declare(strict_types=1);

// Shared page chrome and small UI pieces.
final class View
{
    public static function header(string $title, string $active = ''): void
    {
        $nav = ['index.php' => 'Rankings', 'methodology.php' => 'Methodology'];
        ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= Format::e($title) ?> · Sponsor Lens</title>
<meta name="description" content="Which tier-2 and tier-3 Counter-Strike 2 teams draw the most viewers, and what a sponsorship costs per 1,000 match views.">
<meta name="color-scheme" content="dark light">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="site">
  <div class="site-inner">
    <a class="brand" href="index.php" aria-label="Sponsor Lens home">
      <span>Sponsor<b>Lens</b></span>
      <small class="brand-tag">Tier-2 CS2 sponsor data</small>
    </a>
    <nav aria-label="Main">
      <?php foreach ($nav as $href => $label): ?>
        <a href="<?= $href ?>"<?= $active === $href ? ' aria-current="page"' : '' ?>><?= $label ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
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
  <div class="site-inner">
    <p>
      Match data <a href="https://pandascore.co">PandaScore</a> ·
      Live viewers from official streams on <a href="https://www.twitch.tv">Twitch</a> and <a href="https://kick.com">Kick</a> ·
      Sponsors <a href="https://liquipedia.net/counterstrike/">Liquipedia</a> (<a href="https://creativecommons.org/licenses/by-sa/3.0/">CC-BY-SA 3.0</a>)
    </p>
    <p>Counter-Strike 2 and its artwork are trademarks of Valve Corporation. Sponsor Lens is not affiliated with or endorsed by Valve.</p>
    <p>Free, non-commercial project. Figures are signals, not predictions. <a href="methodology.php">How we calculate them</a>.<?= $synced ? ' Updated ' . Format::dateTime($synced) . '.' : '' ?></p>
  </div>
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

    // Country flag from public/assets/flags (flag-icons, MIT), 4:3. Empty when the code or file is unknown.
    public static function flag(?string $cc, int $height = 14): string
    {
        $cc = strtolower((string) $cc);
        if (!preg_match('/^[a-z]{2}$/', $cc) || !is_file(APP_ROOT . "/public/assets/flags/$cc.svg")) {
            return '';
        }
        $name = Format::e(Format::country(strtoupper($cc)));
        return sprintf('<img class="flag" src="assets/flags/%s.svg" alt="%s" title="%s" width="%d" height="%d">',
            $cc, $name, $name, (int) round($height * 4 / 3), $height);
    }

    // Round team logo (cached by logo.php, or an initials avatar).
    public static function avatar(array $team, int $size = 32): string
    {
        return sprintf(
            '<img class="avatar" src="logo.php?id=%d" alt="" width="%d" height="%d" loading="lazy">',
            (int) $team['id'], $size, $size
        );
    }

    // "↑ 17%" / "↓ 3%" coloured by direction; neutral inside the "in line" band.
    public static function delta(?float $vs): string
    {
        if ($vs === null) {
            return '<span class="delta">–</span>';
        }
        $band = (float) config('site.in_line_band');
        $class = $vs > $band ? 'up' : ($vs < -$band ? 'down' : 'flat');
        $arrow = $vs > 0 ? '↑' : ($vs < 0 ? '↓' : '');
        $label = ($vs > 0 ? 'plus ' : ($vs < 0 ? 'minus ' : '')) . number_format(abs($vs) * 100, 0) . '%';
        return '<span class="delta ' . $class . '" aria-label="' . $label . '"><span aria-hidden="true">' . $arrow . ' ' . number_format(abs($vs) * 100, 0) . '%</span></span>';
    }

    public static function tierTag(?string $tier): string
    {
        return '<span class="tier-tag">' . Verdict::tierLabel($tier) . '</span>';
    }

    // Horizontal bar, 0–1. SVG attributes rather than inline styles, so the strict CSP still applies.
    public static function bar(float $fraction, string $class = ''): string
    {
        $w = max(0, min(100, round($fraction * 100, 1)));
        return '<svg class="bar ' . $class . '" viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true">'
            . '<rect class="track" width="100" height="6" rx="3"/><rect class="fill" width="' . $w . '" height="6" rx="3"/></svg>';
    }

    // "Not enough data" as progress towards the minimum, instead of a fake number.
    public static function progress(int $tracked): string
    {
        $min = Metrics::minTracked();
        return '<p class="figure pending">Not enough data</p>'
            . self::bar(min($tracked, $min) / $min, 'progress')
            . '<p class="sub">' . $tracked . ' of ' . $min . ' tracked matches so far</p>';
    }
}
