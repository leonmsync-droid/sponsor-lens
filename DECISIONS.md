# Decisions log

Newest at the bottom. Each entry: what was decided, and why.

## 2026-10-19 — Stack: PHP 8 + MySQL, no framework
The existing Steam tracker (`steam-tracker`) is a long-running Node.js server writing JSON files. That pattern needs a process that stays up, which GreenGeeks shared hosting doesn't reliably offer. The build prompt's default (PHP 8 + MySQL + cron + plain HTML/CSS/JS with Chart.js) fits shared hosting natively, so we use that.

## 2026-10-19 — Secrets live in `config.php` at the repo root, outside the web root
Only `public/` is served (mapped to `public_html` on GreenGeeks). `config.php` sits one level above it and is git-ignored. `config.example.php` is committed.

## 2026-10-19 — Games and tiers are config, not code
`config/games.php` lists each game with its PandaScore path prefix and tracked tiers. CS2 uses PandaScore's `csgo` prefix (to be confirmed by `scripts/check_tournaments.php`).

## 2026-10-19 — "This season" = since `season_start` in config
Defaults to 1 January of the current year. One setting, so it can follow a circuit's calendar later.

## 2026-10-19 — Sync by tournament, not by match feed
The hourly sync pulls running, upcoming and recently finished tier b–d tournaments, then fetches their matches in batches of 50 tournament ids. That keeps requests well under the free plan's 1,000/hour and avoids filtering the whole CS match feed client-side. `--backfill` pulls everything since `season_start` once.

## 2026-10-19 — Stream fallback is per match, not global
If a match's PandaScore `streams_list` has no official Twitch stream, the sync looks up `data/stream_seed.json` (by tournament id, then league id). The seed is empty until we know whether the free plan includes stream lists.

## 2026-10-19 — CS2 slug confirmed
PandaScore still lists CS2 as videogame id 3, slug `cs-go`, name "Counter-Strike"; the API path prefix `csgo` works. Kept in `config/games.php`.

## 2026-10-19 — Local dev uses prebuilt PHP and MySQL, not Homebrew
Homebrew no longer ships bottles for macOS 14, so PHP and MariaDB would compile from source (LLVM, OpenJDK/Xcode). Local dev instead uses a static PHP 8.4 build (static-php-cli) and Oracle's MySQL 8.4.3 tarball (the last 8.4 build for macOS 14), both in `~/.local`. Production is GreenGeeks' own PHP/MySQL.

## 2026-10-19 — Stream list is on the free plan, but most tier-2 official streams are on Kick
Checkpoint (36 running/upcoming tier b–d tournaments, 320 matches): 234 matches have a `streams_list`, but only 83 have an official Twitch stream. The official broadcasts of CCT, Stake Ranked, NODWIN Clutch Series, United21 and European Pro League are on kick.com; ESEA and VOID list no streams. Twitch-official coverage is mainly Dust2.dk, DACH CS Masters, Gamers Club, Circuit X and a few one-offs. Decision on adding Kick pending.

## 2026-10-19 — Kick added alongside Twitch
Because most official tier-2 broadcasts are on Kick, audience = sum of viewers across a match's official Twitch **and** Kick streams. Kick's public API (`GET /public/v1/channels?slug=…`, app token via client credentials) returns live viewer counts for up to 50 channels per call. Tables renamed to be platform-neutral: `stream_samples` (raw, 24h) and `audience_match_summary` (replaces the prompt's `twitch_match_summary`; still its own table so it can be wiped in one step). If one platform's credentials are missing, matches that need it are marked "audience not tracked" rather than undercounted.

## 2026-10-19 — Audience is global; highlighted countries are UK and Denmark
Audience figures always count every viewer on every official stream, in every language. The country setting only adds a flag and a rankings filter for teams based there (PandaScore team `location`). UK kept at the owner's request. Denmark picked as the second because it has the best measurable data among tracked teams: 14 teams, 62 current matches, 60 with an official stream (Germany was a close second; Brazil and Russia have more teams but less coverage). Spain dropped (2 teams, almost no official Twitch/Kick coverage). Configured in `config/site.php`.

## 2026-10-19 — Live status comes from PandaScore each sampler run
The sampler asks PandaScore for running matches every 10 minutes (1 request) instead of trusting the hourly sync, so a match that starts between syncs is still sampled from its first 10 minutes.

## 2026-10-19 — Summaries built hourly, not daily
The prompt asks for a daily summary + cleanup job. Running it hourly costs nothing and means a match's audience shows up within the hour instead of the next day. The 24h deletion rule for raw samples is unchanged.

## 2026-10-19 — Win rate excludes forfeits
About 1,360 of this season's tracked matches are forfeits. Counting them would reward teams for opponents not turning up, so win rate = wins ÷ decided matches (finished, has a winner, not a forfeit).

## 2026-10-19 — Teams that span tiers are compared with a tier-weighted average
A team's "tier" (for the filter and the header) is the tier it played most decided matches in. For "vs tier average", its expected audience is the mean of the tier averages of the tiers its tracked matches were played in. For a single-tier team this is exactly the prompt's formula; for mixed teams it stops a Tier B appearance being compared with Tier D numbers.

## 2026-10-19 — Rankings show teams active in the last 120 days, 100 rows by default
About 1,080 teams played this season. The table lists teams that played in the last 120 days (or have audience data), with a "Show all" link past 100 rows.

## 2026-10-19 — Sponsors skipped for the afternoon cut
`data/sponsors.json` has the structure and an example; team pages show "No sponsors recorded yet" until entries are added by hand from Liquipedia.

## 2026-10-19 — Visual redesign: dark-first, Kick-inspired, own accent
Inspired by Kick's homepage (owner's reference): dark surfaces, heavy headings with one accent word, pill tags, round avatars, a green live dot and compact numbers ("1.2K"). Deliberately not Kick's neon green: Kick is a data source, so looking like it could imply affiliation, and green already means "above tier average". Accent is electric cyan; green/red are reserved for above/below. Light theme kept via `prefers-color-scheme`.

## 2026-10-19 — Team logos cached on our server
`public/logo.php` downloads PandaScore's small thumbnail once per team into `storage/logos/` and serves it from our domain, so the strict CSP (`img-src 'self'`) stays and pages never hotlink. Only the URL stored for that team id (PandaScore CDN host) is fetched. Teams without a logo get an initials avatar (SVG).

## 2026-10-19 — No inline styles or scripts
Bars are SVG attributes, filters are plain links, chart data is a JSON block. This keeps the CSP free of `unsafe-inline`.

## 2026-10-19 — CS2 key art in the rankings hero
Owner-supplied Counter-Strike 2 artwork (Valve's), shown at its native size on the right of the rankings hero and faded into the panel (stacked above the text on phones). It's Valve's trademark and artwork, used non-commercially; the footer states Sponsor Lens is not affiliated with or endorsed by Valve. Converted to JPEG (36 KB).
