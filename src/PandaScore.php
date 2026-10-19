<?php
declare(strict_types=1);

// Minimal PandaScore REST client with a file cache.
// Free Fixtures plan: 1,000 requests/hour, so every GET is cached and paging stops early.
final class PandaScore
{
    private const BASE = 'https://api.pandascore.co';

    // Minimum gap between live requests; PandaScore also rejects short bursts with a 429.
    private const MIN_GAP_US = 1_000_000;
    private const MAX_RETRIES = 4;

    public int $requests = 0;
    public ?int $remaining = null;
    private float $lastRequest = 0.0;

    public function __construct(private string $token, private string $contact)
    {
        if ($token === '') {
            throw new RuntimeException('PandaScore token missing from config.php');
        }
    }

    // GET one page. $ttl = seconds to reuse a cached response (0 = always fetch).
    public function get(string $path, array $query = [], int $ttl = 300): array
    {
        $url = self::BASE . $path . ($query ? '?' . http_build_query($query) : '');
        $cacheFile = STORAGE_DIR . '/cache/ps_' . sha1($url) . '.json';

        if ($ttl > 0 && is_file($cacheFile) && filemtime($cacheFile) > time() - $ttl) {
            return json_decode((string) file_get_contents($cacheFile), true);
        }

        if ($this->remaining !== null && $this->remaining < 20) {
            throw new RuntimeException("PandaScore rate limit nearly used up ({$this->remaining} left); stopping.");
        }

        for ($attempt = 0; ; $attempt++) {
            $wait = (int) (self::MIN_GAP_US - (microtime(true) - $this->lastRequest) * 1_000_000);
            if ($wait > 0) {
                usleep($wait);
            }
            [$status, $body, $err] = $this->fetch($url);
            if ($status !== 429 || $attempt >= self::MAX_RETRIES) {
                break;
            }
            sleep(5 * 2 ** $attempt); // 5s, 10s, 20s, 40s
        }

        if ($body === false || $status >= 400) {
            throw new RuntimeException("PandaScore $status on $path: " . ($err ?: substr((string) $body, 0, 300)));
        }

        if (!is_dir(dirname($cacheFile))) {
            mkdir(dirname($cacheFile), 0775, true);
        }
        file_put_contents($cacheFile, $body);
        return json_decode((string) $body, true);
    }

    // One HTTP GET. Returns [status, body|false, curl error].
    private function fetch(string $url): array
    {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
                'User-Agent: SponsorLens/0.1 (' . $this->contact . ')',
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $this->requests++;

        if (isset($headers['x-rate-limit-remaining'])) {
            $this->remaining = (int) $headers['x-rate-limit-remaining'];
        }
        $this->lastRequest = microtime(true);
        return [$status, $body, $err];
    }

    // GET every page (100 per page) up to $maxPages.
    public function getAll(string $path, array $query = [], int $ttl = 300, int $maxPages = 50): array
    {
        $all = [];
        foreach ($this->pages($path, $query, $ttl, $maxPages) as $rows) {
            array_push($all, ...$rows);
        }
        return $all;
    }

    // Yields one page (up to 100 rows) at a time, so large syncs never hold the whole result in memory.
    public function pages(string $path, array $query = [], int $ttl = 300, int $maxPages = 50): Generator
    {
        for ($page = 1; $page <= $maxPages; $page++) {
            $rows = $this->get($path, $query + ['page[size]' => 100, 'page[number]' => $page], $ttl);
            yield $rows;
            if (count($rows) < 100) {
                break;
            }
        }
    }

    // [platform, channel] for a Twitch or Kick stream URL, e.g. https://www.twitch.tv/esl_csgo -> ['twitch', 'esl_csgo'].
    // Returns null for other platforms (YouTube etc.), which we don't sample.
    public static function streamChannel(?string $url): ?array
    {
        $patterns = [
            'twitch' => '~^https?://(?:www\.|m\.)?twitch\.tv/([A-Za-z0-9_]{3,25})/?$~',
            'kick'   => '~^https?://(?:www\.)?kick\.com/([A-Za-z0-9_-]{2,25})/?$~',
        ];
        foreach ($patterns as $platform => $re) {
            if ($url && preg_match($re, $url, $m)) {
                return [$platform, strtolower($m[1])];
            }
        }
        return null;
    }
}
