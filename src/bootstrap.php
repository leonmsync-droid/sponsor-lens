<?php
declare(strict_types=1);

// Shared setup for web pages and cron scripts.

define('APP_ROOT', dirname(__DIR__));
define('STORAGE_DIR', APP_ROOT . '/storage');

date_default_timezone_set('UTC');

spl_autoload_register(function (string $class): void {
    $file = APP_ROOT . '/src/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

function config(?string $key = null)
{
    static $config = null;
    if ($config === null) {
        $path = APP_ROOT . '/config.php';
        if (!is_file($path)) {
            throw new RuntimeException('Missing config.php — copy config.example.php and fill it in.');
        }
        $config = require $path;
        $config['games'] = require APP_ROOT . '/config/games.php';
        $config['site'] = require APP_ROOT . '/config/site.php';
    }
    if ($key === null) {
        return $config;
    }
    $value = $config;
    foreach (explode('.', $key) as $part) {
        $value = $value[$part] ?? null;
    }
    return $value;
}

function db(): Db
{
    static $db = null;
    return $db ??= new Db(config('db'));
}

function season_start(): string
{
    return config('season_start') . ' 00:00:00';
}

// Logs a line to stdout with a timestamp (cron output goes to the cron email or log file).
function logline(string $msg): void
{
    echo '[' . gmdate('Y-m-d H:i:s') . "] $msg\n";
}
