<?php
declare(strict_types=1);

// Base for live-viewer APIs (Twitch, Kick): one app access token per platform (client credentials),
// cached in the kv table and refreshed when it expires or is rejected.
abstract class StreamPlatform
{
    abstract public function name(): string;

    // [channel => ['viewers' => int, 'language' => ?string]] for the channels that are live right now.
    abstract public function liveViewers(array $channels): array;

    abstract protected function tokenUrl(): string;

    abstract protected function authHeaders(string $token): array;

    public int $requests = 0;

    public function __construct(protected Db $db, protected string $clientId, protected string $clientSecret, protected string $contact)
    {
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException(static::class . ' credentials missing from config.php');
        }
    }

    public static function forName(string $name, Db $db): self
    {
        $cfg = config($name) ?? [];
        $class = ['twitch' => Twitch::class, 'kick' => Kick::class][$name];
        return new $class($db, (string) ($cfg['client_id'] ?? ''), (string) ($cfg['client_secret'] ?? ''), (string) config('contact'));
    }

    // GET JSON; on a 401 the token is renewed and the request retried once.
    protected function getJson(string $url): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            [$status, $body] = $this->http('GET', $url, $this->authHeaders($this->token($attempt > 0)));
            if ($status !== 401) {
                break;
            }
        }
        if ($status >= 400) {
            throw new RuntimeException("{$this->name()} $status on $url: " . substr($body, 0, 300));
        }
        return json_decode($body, true) ?? [];
    }

    private function token(bool $forceRefresh): string
    {
        $key = $this->name() . '_token';
        $cached = json_decode((string) $this->db->getKv($key), true);
        if (!$forceRefresh && $cached && $cached['expires_at'] > time() + 300) {
            return $cached['token'];
        }

        [$status, $body] = $this->http('POST', $this->tokenUrl(), ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'client_credentials',
        ]));
        $data = json_decode($body, true);
        if ($status >= 400 || empty($data['access_token'])) {
            throw new RuntimeException("{$this->name()} token request failed ($status): " . substr($body, 0, 300));
        }
        $this->db->setKv($key, json_encode([
            'token'      => $data['access_token'],
            'expires_at' => time() + (int) ($data['expires_in'] ?? 3600),
        ]));
        return $data['access_token'];
    }

    private function http(string $method, string $url, array $headers, ?string $body = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => array_merge($headers, ['Accept: application/json', 'User-Agent: SponsorLens/0.1 (' . $this->contact . ')']),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $this->requests++;
        if ($response === false) {
            throw new RuntimeException("{$this->name()} request failed: $err");
        }
        return [$status, (string) $response];
    }

    // Repeated query keys: ['slug' => ['a', 'b']] -> slug=a&slug=b
    protected static function repeatQuery(string $key, array $values): string
    {
        return implode('&', array_map(fn($v) => $key . '=' . rawurlencode($v), $values));
    }
}
