<?php
declare(strict_types=1);

// Every 10 minutes: sample live viewers on the official Twitch and Kick streams of tracked matches that are live.
// A match's viewers at a sample = sum across its official streams. Matches are skipped ("audience not tracked") when:
//   - they have no official Twitch/Kick stream                        -> audience_note = no_official_stream
//   - one of their channels is showing another live match at the same time -> audience_note = shared_channel
//   - one of their channels is on a platform we have no credentials for  -> audience_note = platform_unavailable
// Usage: php cron/sample_streams.php

require __DIR__ . '/../src/bootstrap.php';

$db = db();
$ps = new PandaScore((string) config('pandascore.token'), (string) config('contact'));
$resolver = MatchStreams::load();
$sampledAt = gmdate('Y-m-d H:i:s');

// 1. Which tracked matches are live right now (straight from PandaScore, so we don't wait for the hourly sync).
$live = [];
foreach (config('games') as $game) {
    foreach ($ps->get("/{$game['api_prefix']}/matches/running", ['page[size]' => 100], 0) as $m) {
        $live[$m['id']] = $m;
    }
}
$tracked = $live ? array_column($db->all(
    'SELECT id FROM matches WHERE id IN (' . implode(',', array_map('intval', array_keys($live))) . ')'
), 'id') : [];
$live = array_intersect_key($live, array_flip($tracked));

if (!$live) {
    logline('No tracked matches live; nothing to sample.');
    exit(0);
}

// 2. Official streams per match; find channels shared between live matches.
$matchStreams = [];
$channelUse = [];
foreach ($live as $id => $m) {
    $db->run("UPDATE matches SET status = 'running', begin_at = COALESCE(begin_at, ?) WHERE id = ?", [gmdate('Y-m-d H:i:s', strtotime($m['begin_at'] ?? 'now')), $id]);
    $streams = $resolver->streamsFor($m);
    $matchStreams[$id] = $streams;
    foreach (array_keys($streams) as $key) {
        $channelUse[$key] = ($channelUse[$key] ?? 0) + 1;
    }
}

// 3. One batched lookup per platform.
$platforms = [];
$liveChannels = [];
foreach (['twitch', 'kick'] as $name) {
    $wanted = [];
    foreach ($matchStreams as $streams) {
        foreach ($streams as [$platform, $channel]) {
            if ($platform === $name) {
                $wanted[] = $channel;
            }
        }
    }
    if (!$wanted) {
        continue;
    }
    try {
        $platforms[$name] = StreamPlatform::forName($name, $db);
        foreach ($platforms[$name]->liveViewers($wanted) as $channel => $info) {
            $liveChannels["$name/$channel"] = $info;
        }
    } catch (Throwable $e) {
        logline("$name unavailable: " . $e->getMessage());
        unset($platforms[$name]);
    }
}

// 4. Store one sample per live channel for each cleanly attributable match.
$sampled = 0;
foreach ($matchStreams as $id => $streams) {
    $note = null;
    if (!$streams) {
        $note = 'no_official_stream';
    } elseif (array_filter(array_keys($streams), fn($k) => $channelUse[$k] > 1)) {
        $note = 'shared_channel';
    } elseif (array_filter($streams, fn($s) => !isset($platforms[$s[0]]))) {
        $note = 'platform_unavailable';
    }
    $db->run('UPDATE matches SET audience_note = ? WHERE id = ?', [$note, $id]);
    if ($note) {
        logline("Match $id: not tracked ($note)");
        continue;
    }

    $rows = 0;
    foreach ($streams as $key => [$platform, $channel, $lang]) {
        if (isset($liveChannels[$key])) {
            $db->run(
                'INSERT INTO stream_samples (match_id, sampled_at, platform, channel, language, viewers) VALUES (?, ?, ?, ?, ?, ?)',
                [$id, $sampledAt, $platform, $channel, $lang ?? $liveChannels[$key]['language'], $liveChannels[$key]['viewers']]
            );
            $rows++;
        }
    }
    if ($rows) {
        $sampled++;
    }
}

$requests = implode(', ', array_map(fn($p) => $p->name() . ' ' . $p->requests, $platforms));
logline(sprintf('%d live tracked matches, %d sampled. API requests: pandascore %d%s', count($live), $sampled, $ps->requests, $requests ? ", $requests" : ''));
