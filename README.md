# Sponsor Lens

A free, non-commercial site that shows sponsors which **tier-2 and tier-3 Counter-Strike 2 teams** draw the most viewers, and roughly what a sponsorship fee costs per 1,000 match views. Audience figures are global, and every team is treated the same whatever its country.

No ads, accounts or downloads. Built as a portfolio piece; see [`DECISIONS.md`](DECISIONS.md) for the reasoning behind each choice.

## Pages

- **Team rankings** (`public/index.php`): verdict, tier filter, ranked table.
- **Team page** (`public/team.php?id=…`): verdict, live badge, four metric cards, fee check, viewers and win-rate charts, recent/upcoming matches, sponsors.
- **Methodology** (`public/methodology.php`): every formula, data limit and source.

## How audience is measured

PandaScore gives each match's stream list. For every live tracked match, the sampler reads the live viewer count of its **official Twitch and Kick streams** (all languages) every 10 minutes and sums them. Raw samples are deleted after 24 hours; per-match summaries (average, peak, per-language and per-platform split) are kept in `audience_match_summary`, which can be emptied in one step (`TRUNCATE audience_match_summary`) without breaking the site.

## Stack

PHP 8.1+ and MySQL 5.7+/MariaDB 10.3+, cron-driven fetch scripts, plain HTML/CSS/JS with Chart.js. No framework, no Composer. Designed for shared hosting (GreenGeeks).

```
config.example.php   copy to config.php (secrets; git-ignored; outside the web root)
config/games.php     tracked games and tiers (add games/tiers here, no code changes)
config/site.php      highlighted countries, "not enough data" threshold, verdict band
schema.sql           database tables
src/                 PHP classes (API clients, metrics, verdicts, views)
cron/                scheduled jobs
scripts/             one-off checks
data/                hand-maintained seed files (sponsors, stream fallback)
public/              web root: the only folder served
storage/             cache and logs (created automatically; git-ignored)
```

## Secrets

All keys live in **`config.php` at the project root**, one level above `public/`, so the web server never serves it. It's in `.gitignore`. Only `config.example.php` (blank values) is committed.

| Setting | Where to get it |
|---|---|
| `pandascore.token` | [PandaScore dashboard](https://app.pandascore.co/dashboard) (free Fixtures plan) |
| `twitch.client_id` / `client_secret` | [Twitch developer console](https://dev.twitch.tv/console/apps) → Register application (category: Analytics, OAuth redirect `http://localhost`) |
| `kick.client_id` / `client_secret` | [Kick developer settings](https://kick.com/settings/developer) → Create app |
| `db.*` | Your MySQL database |

## Local setup

1. `cp config.example.php config.php` and fill it in.
2. Create a database and load the schema: `mysql -u USER -p DBNAME < schema.sql`
3. Check what's trackable (API only, no database needed): `php scripts/check_tournaments.php`
4. Load the season: `php cron/sync_pandascore.php --backfill` (about 3 minutes, ~110 PandaScore requests)
5. Serve it: `php -S 127.0.0.1:8080 -t public` and open http://127.0.0.1:8080

## Cron jobs

| Schedule | Command | What it does |
|---|---|---|
| `*/10 * * * *` | `php cron/sample_streams.php` | Samples live viewers on official Twitch/Kick streams of live tracked matches (one PandaScore call; exits early when nothing is live) |
| `5 * * * *` | `php cron/sync_pandascore.php` | Syncs running, upcoming and recently finished tournaments and matches |
| `15 * * * *` | `php cron/build_summaries.php` | Builds per-match audience summaries, deletes raw samples older than 24h, prunes the API cache |

On GreenGeeks (cPanel → Cron Jobs), use full paths and append output to a log, e.g.:

```
*/10 * * * * /usr/local/bin/php /home/USERNAME/sponsor-lens/cron/sample_streams.php >> /home/USERNAME/sponsor-lens/storage/cron.log 2>&1
```

Check the PHP path with `which php` in cPanel's Terminal; it may be an `ea-php8x` path instead.

## Rate limits

- **PandaScore:** 1,000 requests/hour on the free plan, and short bursts are rejected. The client caches every response, spaces requests 1 second apart and backs off on HTTP 429. Normal use is about 6 requests/hour for the sampler plus 10–20 per hourly sync.
- **Twitch / Kick:** one app token each (client credentials), cached in the database and renewed when it expires; channels are looked up in batches (100 per Twitch call, 50 per Kick call).

## Data sources

- [PandaScore](https://pandascore.co): tournaments, teams, schedule, results, stream lists. Only derived statistics are published.
- [Twitch API](https://dev.twitch.tv/docs/api/) and [Kick API](https://docs.kick.com): live viewer counts on official streams.
- [flag-icons](https://github.com/lipis/flag-icons) (MIT): country flags, served from `public/assets/flags`.
- [Simple Icons](https://simpleicons.org) (CC0): Twitch and Kick icons in `public/assets/icons` (monochrome, plus `-color` versions in each brand's own colours), used only to label data sources. The brands' own guidelines still apply.
- [Liquipedia](https://liquipedia.net/counterstrike/): sponsor lists, entered by hand in `data/sponsors.json` with source links (CC-BY-SA 3.0). Liquipedia pages are never scraped.
