<?php
declare(strict_types=1);

// Resolves the official Twitch/Kick channels for a match. Falls back to data/stream_seed.json
// (tournaments/leagues mapped by hand) when PandaScore lists no official Twitch or Kick stream.
final class MatchStreams
{
    private function __construct(private array $data) {}

    public static function load(): self
    {
        $path = APP_ROOT . '/data/stream_seed.json';
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        return new self(is_array($data) ? $data : []);
    }

    // Official Twitch/Kick streams for a PandaScore match object: from its streams_list,
    // or from the seed file (tournament id, then league id) when it lists none.
    // Returns ["platform/channel" => [platform, channel, language]].
    public function streamsFor(array $match): array
    {
        $out = [];
        foreach ($match['streams_list'] ?? [] as $s) {
            $ch = PandaScore::streamChannel($s['raw_url'] ?? null);
            if ($ch && !empty($s['official'])) {
                $out[implode('/', $ch)] = [$ch[0], $ch[1], $s['language'] ?? null];
            }
        }
        if ($out) {
            return $out;
        }

        // Seed entries look like {"platform": "kick", "channel": "cct_cs", "language": "en"}.
        $entries = $this->data['tournaments'][(string) $match['tournament_id']]
            ?? $this->data['leagues'][(string) ($match['league_id'] ?? '')]
            ?? [];
        foreach ($entries as $e) {
            $platform = $e['platform'] ?? 'twitch';
            $channel = strtolower($e['channel']);
            $out["$platform/$channel"] = [$platform, $channel, $e['language'] ?? null];
        }
        return $out;
    }
}
