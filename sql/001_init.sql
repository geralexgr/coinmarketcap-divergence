-- 001_init.sql — the recording core.
--
-- This migration exists to get the poller writing on day 1. It creates only the two
-- tables that cannot be rebuilt later: the verbatim payloads and the record of every
-- attempt to fetch one. Derived tables (market_metric, asset_metric, scores) arrive in
-- 002 and are disposable by design — see docs/data-model.md.
--
--   mysql -u USER -p DB < sql/001_init.sql
--
-- Safe to re-run: every statement is IF NOT EXISTS.

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
