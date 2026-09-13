-- Divergence — complete database schema.
--
-- Run this once against an empty database, then never again. It is the whole schema:
-- the recording core and every derived table.
--
-- In cPanel: phpMyAdmin -> select your database -> SQL tab -> paste -> Go.
-- Over SSH:  mysql -u USER -p DATABASE < divergence-schema.sql
--
-- Safe to re-run: every statement is IF NOT EXISTS, so running it twice changes
-- nothing and destroys nothing.
--
-- Two tables here can never be rebuilt from anything else — raw_samples and fetch_log.
-- Everything below them is a cache of an interpretation of those two, rebuildable with
-- `php app/bin/extract.php --rebuild` and `php app/bin/score.php --rebuild`.

SET NAMES utf8mb4;


-- ---------------------------------------------------------------------------
-- raw_samples — source of truth. Append only; never updated, never deleted.
-- ---------------------------------------------------------------------------
--
-- payload is LONGTEXT rather than JSON on purpose. A MySQL JSON column reparses
-- and re-serialises on write: key order and whitespace are not preserved, so what
-- comes back out is not byte-for-byte what CoinMarketCap sent. D3 says the stored
-- payload is the source of truth, which only holds if it is verbatim. LONGTEXT also
-- works on MySQL 5.6 hosts, which removes one host dependency.

CREATE TABLE IF NOT EXISTS raw_samples (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    endpoint      VARCHAR(120)    NOT NULL COMMENT 'logical name, e.g. fear_and_greed — not the URL path',
    scope         ENUM('market','asset') NOT NULL DEFAULT 'market',
    fetched_at    DATETIME(3)     NOT NULL COMMENT 'UTC, when the response actually arrived — never the cron schedule',
    http_status   SMALLINT UNSIGNED NULL   COMMENT 'NULL = no response (timeout, DNS, connection refused)',
    credits       INT UNSIGNED    NULL     COMMENT 'status.credit_count from the response body, not estimated',
    payload       LONGTEXT        NULL     COMMENT 'verbatim response body, success or error',
    PRIMARY KEY (id),
    KEY idx_endpoint_time (endpoint, fetched_at),
    KEY idx_time (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- fetch_log — every attempt, success or failure.
-- ---------------------------------------------------------------------------
--
-- Operational visibility now, and the evidence for the "where the API got in the
-- way" note the submission requires (D4). A failed attempt writes a row here and,
-- if any body came back at all, a row in raw_samples too.

CREATE TABLE IF NOT EXISTS fetch_log (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id        CHAR(22)        NOT NULL COMMENT 'groups the endpoints fetched by one cron invocation: YYYYmmdd_HHMMSS_hex6',
    endpoint      VARCHAR(120)    NOT NULL,
    scope         ENUM('market','asset') NOT NULL DEFAULT 'market',
    url           VARCHAR(500)    NOT NULL COMMENT 'path and query actually requested, key never included',
    attempted_at  DATETIME(3)     NOT NULL COMMENT 'UTC, when the request went out',
    http_status   SMALLINT UNSIGNED NULL   COMMENT 'NULL = never got a response',
    duration_ms   INT UNSIGNED    NULL     COMMENT 'catches the host being slow before it starts timing out',
    credits       INT UNSIGNED    NULL,
    raw_sample_id BIGINT UNSIGNED NULL     COMMENT 'the row this attempt produced, if any',
    error         TEXT            NULL     COMMENT 'verbatim, not summarised',
    PRIMARY KEY (id),
    KEY idx_endpoint_time (endpoint, attempted_at),
    KEY idx_time (attempted_at),
    KEY idx_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- market_metric — one row per metric per sample, market-wide.
-- ---------------------------------------------------------------------------
--
-- Long format, not wide. A new Voice or Money input is then an extractor change
-- and nothing else; a wide table would need a migration on a host where migrations
-- are a manual mysql invocation over SSH.
--
-- sampled_at is denormalised from raw_samples because every chart query filters on
-- it, and the join to a LONGTEXT table to read one DATETIME is the difference
-- between a fast page and a slow one.
--
-- The unique key is what makes extraction re-runnable: a second pass over the same
-- payload updates in place rather than doubling the series.

CREATE TABLE IF NOT EXISTS market_metric (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    raw_sample_id BIGINT UNSIGNED NOT NULL COMMENT 'the payload this was read out of',
    endpoint      VARCHAR(120)    NOT NULL COMMENT 'denormalised: which endpoint supplied it',
    metric        VARCHAR(60)     NOT NULL COMMENT 'fear_greed, market_turnover, exchange_hhi, ...',
    value         DECIMAL(24,8)   NOT NULL,
    sampled_at    DATETIME(3)     NOT NULL COMMENT 'UTC, copied from raw_samples.fetched_at',
    PRIMARY KEY (id),
    UNIQUE KEY uq_sample_metric (raw_sample_id, metric),
    KEY idx_metric_time (metric, sampled_at),
    KEY idx_time (sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- asset_metric — the same, per asset. The big table.
-- ---------------------------------------------------------------------------
--
-- Roughly 600k rows over three weeks at the planned cadence, which is why the
-- symbol is stored alongside the id: the per-asset screener orders by metric and
-- prints a symbol, and that should not need a join to asset_universe per row.

CREATE TABLE IF NOT EXISTS asset_metric (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    raw_sample_id BIGINT UNSIGNED NOT NULL,
    endpoint      VARCHAR(120)    NOT NULL,
    cmc_id        INT UNSIGNED    NOT NULL COMMENT 'CoinMarketCap asset id, the only stable handle — symbols are reused',
    symbol        VARCHAR(32)     NOT NULL,
    metric        VARCHAR(60)     NOT NULL COMMENT 'turnover, volume_24h, trend_rank, ...',
    value         DECIMAL(24,8)   NOT NULL,
    sampled_at    DATETIME(3)     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sample_asset_metric (raw_sample_id, cmc_id, metric),
    KEY idx_asset_metric_time (cmc_id, metric, sampled_at),
    KEY idx_metric_time (metric, sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- asset_universe — which assets are tracked, and when they entered or left.
-- ---------------------------------------------------------------------------
--
-- Membership is data. An asset entering the top 100 is a fact worth being able to
-- see, and without removed_at a screener silently rewrites its own past.

CREATE TABLE IF NOT EXISTS asset_universe (
    cmc_id     INT UNSIGNED NOT NULL,
    symbol     VARCHAR(32)  NOT NULL,
    name       VARCHAR(120) NOT NULL,
    is_stablecoin TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'from the listings tags. A stablecoin has enormous turnover and no narrative by construction, so it dominates a gap-ranked screener with an artefact rather than a finding — see D21',
    rank_last  SMALLINT UNSIGNED NULL COMMENT 'most recent CMC rank seen',
    first_seen DATETIME(3)  NOT NULL,
    last_seen  DATETIME(3)  NOT NULL COMMENT 'still in the universe if this is recent',
    PRIMARY KEY (cmc_id),
    KEY idx_rank (rank_last),
    KEY idx_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- extraction_log — which payloads have been read, and by which extractor.
-- ---------------------------------------------------------------------------
--
-- This is what makes "re-run the parsing over all of history" a one-line operation
-- rather than a guess. bin/extract.php processes any raw sample with no row here at
-- the current extractor_version, so bumping EXTRACTOR_VERSION in lib/extract.php
-- re-reads every payload ever stored. Failures are recorded, not retried forever:
-- a payload that is an API error body is marked 'skipped' with the reason.

CREATE TABLE IF NOT EXISTS extraction_log (
    raw_sample_id     BIGINT UNSIGNED NOT NULL,
    extractor_version SMALLINT UNSIGNED NOT NULL,
    extracted_at      DATETIME(3)     NOT NULL,
    status            ENUM('ok','skipped','error') NOT NULL,
    rows_written      INT UNSIGNED    NOT NULL DEFAULT 0,
    note              TEXT            NULL COMMENT 'verbatim reason when skipped or failed',
    PRIMARY KEY (raw_sample_id, extractor_version),
    KEY idx_status (status, extracted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- scores — Voice, Money and the gap between them.
-- ---------------------------------------------------------------------------
--
-- Stored rather than computed on read, because the web app must open fast and this
-- table is small. basis and method_version exist so the method page can state which
-- rows were produced which way: the first seven days are normalised against fixed
-- reference ranges and later rows against percentile rank, and hiding that
-- switchover inside the code would make the trail dishonest.
--
-- voice_inputs and money_inputs record how much of the declared method a row actually
-- saw. A Voice score built from one of three inputs is a weaker claim than one built from
-- three, and on the Basic plan it is always one — so the number is stored on the row and
-- printed beside the score rather than left for a reader to assume.

CREATE TABLE IF NOT EXISTS scores (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope          ENUM('market','asset') NOT NULL DEFAULT 'market',
    cmc_id         INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '0 for market-wide. Not NULL: MySQL permits repeated NULLs in a UNIQUE index, so a nullable column here would let every market-wide recompute insert a duplicate instead of updating',
    sampled_at     DATETIME(3)     NOT NULL,
    voice          DECIMAL(6,2)    NOT NULL COMMENT '0-100',
    money          DECIMAL(6,2)    NOT NULL COMMENT '0-100',
    divergence     DECIMAL(7,2)    NOT NULL COMMENT 'signed: money - voice, matching docs/method.md',
    quadrant       ENUM('chatter_without_conviction','loud_and_leveraged','apathy','quiet_but_leveraged') NOT NULL,
    basis          ENUM('fixed','percentile','cross_section') NOT NULL COMMENT 'which normalisation produced this row. cross_section is the per-asset one: ranked against the rest of the universe at the same instant rather than against its own past',
    method_version SMALLINT UNSIGNED NOT NULL,
    voice_inputs    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'how many declared Voice inputs actually had data',
    money_inputs    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    inputs_possible TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'how many were declared. Ten of seventeen endpoints are 403 on this plan, so used < possible is the normal case and the app prints the ratio rather than hiding it',
    computed_at    DATETIME(3)     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_scope_asset_time_version (scope, cmc_id, sampled_at, method_version),
    KEY idx_scope_time (scope, sampled_at),
    KEY idx_asset_time (cmc_id, sampled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
