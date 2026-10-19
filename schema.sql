-- Sponsor Lens schema (MySQL 5.7+ / MariaDB 10.3+)

CREATE TABLE IF NOT EXISTS tournaments (
    id           INT UNSIGNED PRIMARY KEY,          -- PandaScore id
    game         VARCHAR(16)  NOT NULL,
    name         VARCHAR(255) NOT NULL,
    league_name  VARCHAR(255) NULL,
    serie_name   VARCHAR(255) NULL,
    tier         CHAR(1)      NOT NULL,
    begin_at     DATETIME     NULL,
    end_at       DATETIME     NULL,
    updated_at   DATETIME     NOT NULL,
    KEY idx_game_tier (game, tier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS teams (
    id          INT UNSIGNED PRIMARY KEY,           -- PandaScore id
    game        VARCHAR(16)  NOT NULL,
    name        VARCHAR(255) NOT NULL,
    acronym     VARCHAR(32)  NULL,
    location    CHAR(2)      NULL,                  -- ISO country code, e.g. GB, ES
    image_url   VARCHAR(512) NULL,
    updated_at  DATETIME     NOT NULL,
    KEY idx_location (location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tournament_teams (
    tournament_id INT UNSIGNED NOT NULL,
    team_id       INT UNSIGNED NOT NULL,
    PRIMARY KEY (tournament_id, team_id),
    KEY idx_team (team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS matches (
    id             INT UNSIGNED PRIMARY KEY,        -- PandaScore id
    game           VARCHAR(16)  NOT NULL,
    tournament_id  INT UNSIGNED NOT NULL,
    tier           CHAR(1)      NOT NULL,
    name           VARCHAR(255) NULL,
    status         VARCHAR(16)  NOT NULL,           -- not_started | running | finished | canceled | postponed
    scheduled_at   DATETIME     NULL,
    begin_at       DATETIME     NULL,
    end_at         DATETIME     NULL,
    team1_id       INT UNSIGNED NULL,
    team2_id       INT UNSIGNED NULL,
    team1_score    TINYINT UNSIGNED NULL,
    team2_score    TINYINT UNSIGNED NULL,
    winner_id      INT UNSIGNED NULL,
    forfeit        TINYINT(1)   NOT NULL DEFAULT 0,
    audience_note  VARCHAR(32)  NULL,               -- set by the sampler: no_official_stream | shared_channel
    updated_at     DATETIME     NOT NULL,
    KEY idx_status (status),
    KEY idx_team1 (team1_id),
    KEY idx_team2 (team2_id),
    KEY idx_tournament (tournament_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Official Twitch and Kick streams per match, derived from PandaScore streams_list
-- (or from data/stream_seed.json when PandaScore lists none).
CREATE TABLE IF NOT EXISTS match_streams (
    match_id  INT UNSIGNED NOT NULL,
    platform  VARCHAR(8)   NOT NULL,                -- twitch | kick
    channel   VARCHAR(64)  NOT NULL,                -- channel login/slug, lower-case
    language  VARCHAR(8)   NULL,
    PRIMARY KEY (match_id, platform, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Raw 10-minute viewer samples. Deleted after 24 hours by cron/build_summaries.php.
-- Broadcaster channel + total viewer count only: nothing about individual viewers.
CREATE TABLE IF NOT EXISTS stream_samples (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    match_id    INT UNSIGNED NOT NULL,
    sampled_at  DATETIME     NOT NULL,              -- same value for every row in one sampler run
    platform    VARCHAR(8)   NOT NULL,
    channel     VARCHAR(64)  NOT NULL,
    language    VARCHAR(8)   NULL,
    viewers     INT UNSIGNED NOT NULL,
    KEY idx_match (match_id, sampled_at),
    KEY idx_sampled (sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-match audience summaries. Kept in their own table so they can be wiped in one step
-- (TRUNCATE audience_match_summary); the site keeps working without it.
CREATE TABLE IF NOT EXISTS audience_match_summary (
    match_id      INT UNSIGNED PRIMARY KEY,
    samples       SMALLINT UNSIGNED NOT NULL,
    avg_viewers   INT UNSIGNED NOT NULL,
    peak_viewers  INT UNSIGNED NOT NULL,
    by_language   TEXT NOT NULL,                    -- JSON: {"en": 1234, "pt": 567} average viewers per language
    by_platform   TEXT NOT NULL,                    -- JSON: {"twitch": 1500, "kick": 301} average viewers per platform
    built_at      DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Small key/value store (app tokens, last run times).
CREATE TABLE IF NOT EXISTS kv (
    k  VARCHAR(64) PRIMARY KEY,
    v  TEXT NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
