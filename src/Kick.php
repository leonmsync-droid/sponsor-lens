<?php
declare(strict_types=1);

// Kick public API: Get Channels by slug (up to 50 per request), reading stream.is_live / stream.viewer_count.
final class Kick extends StreamPlatform
{
    public function name(): string
    {
        return 'kick';
    }

    protected function tokenUrl(): string
    {
        return 'https://id.kick.com/oauth/token';
    }

    protected function authHeaders(string $token): array
    {
        return ['Authorization: Bearer ' . $token];
    }

    public function liveViewers(array $channels): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($channels)), 50) as $chunk) {
            $data = $this->getJson('https://api.kick.com/public/v1/channels?' . self::repeatQuery('slug', $chunk));
            foreach ($data['data'] ?? [] as $c) {
                if (!empty($c['stream']['is_live'])) {
                    $out[strtolower($c['slug'])] = ['viewers' => (int) $c['stream']['viewer_count'], 'language' => $c['stream']['language'] ?? null];
                }
            }
        }
        return $out;
    }
}
