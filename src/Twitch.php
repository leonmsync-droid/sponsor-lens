<?php
declare(strict_types=1);

// Twitch Helix: Get Streams (up to 100 logins per request; only live channels are returned).
final class Twitch extends StreamPlatform
{
    public function name(): string
    {
        return 'twitch';
    }

    protected function tokenUrl(): string
    {
        return 'https://id.twitch.tv/oauth2/token';
    }

    protected function authHeaders(string $token): array
    {
        return ['Authorization: Bearer ' . $token, 'Client-Id: ' . $this->clientId];
    }

    public function liveViewers(array $channels): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($channels)), 100) as $chunk) {
            $data = $this->getJson('https://api.twitch.tv/helix/streams?first=100&' . self::repeatQuery('user_login', $chunk));
            foreach ($data['data'] ?? [] as $s) {
                if (($s['type'] ?? 'live') === 'live') {
                    $out[strtolower($s['user_login'])] = ['viewers' => (int) $s['viewer_count'], 'language' => $s['language'] ?? null];
                }
            }
        }
        return $out;
    }
}
