<?php
declare(strict_types=1);

// Team logo, cached on our server: downloaded once from PandaScore's CDN (small thumbnail),
// then served from storage/logos. Teams without a logo get an initials avatar (SVG).
// Only the URL stored for that team id is ever fetched.

require __DIR__ . '/../src/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$team = $id ? db()->one('SELECT name, image_url FROM teams WHERE id = ?', [$id]) : null;
if (!$team) {
    http_response_code(404);
    exit;
}

$dir = STORAGE_DIR . '/logos';
$file = "$dir/$id.png";
$url = (string) $team['image_url'];

if (!is_file($file) && preg_match('~^https://cdn-api\.pandascore\.co/images/team/image/\d+/[^/]+$~', $url)) {
    $thumb = preg_replace('~/([^/]+)$~', '/thumb_$1', $url);
    $ch = curl_init($thumb);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false]);
    $png = curl_exec($ch);
    $ok = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200 && str_starts_with((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE), 'image/');
    curl_close($ch);
    if ($ok && $png !== false && strlen($png) < 2_000_000) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($file, $png);
    }
}

header('Cache-Control: public, max-age=604800');
if (is_file($file)) {
    header('Content-Type: ' . (mime_content_type($file) ?: 'image/png'));
    readfile($file);
    exit;
}

// Initials avatar; hue derived from the team id so each team keeps its colour.
$hue = ($id * 47) % 360;
header('Content-Type: image/svg+xml');
echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="hsl(' . $hue . ' 45% 32%)"/>'
    . '<text x="32" y="33" dominant-baseline="middle" text-anchor="middle" font-family="system-ui,sans-serif" font-weight="800" font-size="24" fill="#fff">'
    . Format::e(Format::initials($team['name'])) . '</text></svg>';
