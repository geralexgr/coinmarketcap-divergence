-- Adds asset_universe.is_stablecoin.
--
-- ONLY needed if you created the database before this column existed. A fresh
-- docs/schema.sql already includes it, and running that again will NOT add it —
-- CREATE TABLE IF NOT EXISTS does nothing to a table that is already there.
--
-- In cPanel: phpMyAdmin -> your database -> SQL tab -> paste -> Go.
--
-- Then re-run the extractor so the flag is populated from payloads already stored:
--
--     php app/bin/extract.php --rebuild
--
-- Nothing is lost if this is applied twice: the first form errors harmlessly on a
-- column that exists, and MariaDB accepts the IF NOT EXISTS form outright.

-- MariaDB 10.2+ (what cPanel usually runs) — idempotent:
ALTER TABLE asset_universe
    ADD COLUMN IF NOT EXISTS is_stablecoin TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'from the listings tags — see D21'
    AFTER name;

-- MySQL 8 has no ADD COLUMN IF NOT EXISTS. On MySQL, use this instead and ignore
-- the duplicate-column error if you have already run it:
--
-- ALTER TABLE asset_universe
--     ADD COLUMN is_stablecoin TINYINT(1) NOT NULL DEFAULT 0
--     COMMENT 'from the listings tags — see D21'
--     AFTER name;
