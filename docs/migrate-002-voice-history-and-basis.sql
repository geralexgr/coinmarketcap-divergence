-- Migration 002 — Voice history, and a basis per axis.
--
-- Apply once, to an existing deployment, before deploying the code that needs it.
-- phpMyAdmin -> SQL tab, or:
--
--     mysql -u USER -p DBNAME < docs/migrate-002-voice-history-and-basis.sql
--
-- Both changes are widenings. Nothing is dropped, no row is rewritten, and running it a
-- second time is a no-op — every step checks information_schema first.
--
-- **Why it is written this way.** `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` is MariaDB
-- syntax and is a syntax error on MySQL 8, which is what this very migration did on the
-- first attempt. cPanel hosts run both, so every step below asks information_schema and
-- builds the statement it actually needs. No DELIMITER change, so it pastes into
-- phpMyAdmin as one block.
--
-- ---------------------------------------------------------------------------
-- What changes, and why
-- ---------------------------------------------------------------------------
--
-- `/v3/fear-and-greed/historical` is callable on the Basic plan and returns 500 daily
-- readings for one credit. It was declared in the endpoint catalogue from the first
-- commit and never fetched, so the Voice axis has been scored against a hand-set 0-100
-- range -- which for that index means the score is the raw input restated -- while five
-- hundred days of its own distribution sat one call away. See D22.
--
-- 1. market_metric.uq_sample_metric widens to include sampled_at.
--
--    One historical payload carries 500 dated readings of the same metric. Under
--    (raw_sample_id, metric) only one of them could exist: the extractor would write
--    five hundred rows and MySQL would collapse them to the last one, silently. The key
--    still makes extraction re-runnable -- a second pass over the same payload updates
--    the same 500 rows in place -- it just no longer assumes one reading per metric per
--    payload.
--
-- 2. scores gains voice_basis and money_basis.
--
--    The two axes no longer reach percentile rank at the same time, so one `basis`
--    column can no longer describe a row honestly. `basis` is kept and still carries the
--    row-level summary the charts group on -- now the weaker of the two -- and the
--    per-axis columns are what the method page reads.

-- ---------------------------------------------------------------------------
-- 1. Widen the market_metric unique key.
-- ---------------------------------------------------------------------------

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'market_metric'
        AND INDEX_NAME = 'uq_sample_metric') > 0,
    'ALTER TABLE market_metric DROP INDEX uq_sample_metric',
    'SELECT ''uq_sample_metric already dropped'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'market_metric'
        AND INDEX_NAME = 'uq_sample_metric_time') = 0,
    'ALTER TABLE market_metric ADD UNIQUE KEY uq_sample_metric_time (raw_sample_id, metric, sampled_at)',
    'SELECT ''uq_sample_metric_time already present'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Add the per-axis basis columns.
-- ---------------------------------------------------------------------------

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scores'
        AND COLUMN_NAME = 'voice_basis') = 0,
    'ALTER TABLE scores ADD COLUMN voice_basis ENUM(''fixed'',''percentile'',''cross_section'')
        NOT NULL DEFAULT ''fixed''
        COMMENT ''which normalisation produced the Voice score on this row''
        AFTER basis',
    'SELECT ''scores.voice_basis already present'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scores'
        AND COLUMN_NAME = 'money_basis') = 0,
    'ALTER TABLE scores ADD COLUMN money_basis ENUM(''fixed'',''percentile'',''cross_section'')
        NOT NULL DEFAULT ''fixed''
        COMMENT ''which normalisation produced the Money score on this row''
        AFTER voice_basis',
    'SELECT ''scores.money_basis already present'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing rows were scored under a single basis for both axes, which is exactly what
-- `basis` already records. Backfilling from it keeps history readable under the new
-- columns instead of claiming every past row was 'fixed'. Safe to repeat: the next
-- `bin/score.php --rebuild` overwrites these with freshly computed values anyway.
UPDATE scores SET voice_basis = basis, money_basis = basis;
