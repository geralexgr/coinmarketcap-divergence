# Data model

`raw_samples` and `fetch_log` are **built and migrated** — see `sql/001_init.sql`, and the column
comments there are authoritative where this file disagrees. The derived tables below are still a
draft; the constraint that *is* a commitment is that raw payloads are stored and every derived
row can be rebuilt from them.

## Principles

1. **Raw first.** `raw_samples` is the source of truth. Everything else is a cache of an
   interpretation of it.
2. **Every attempt logged.** Including failures. `fetch_log` is both operational visibility and the
   evidence for the API friction write-up the submission requires.
3. **Real timestamps.** The moment the response arrived, UTC, never the cron schedule.
4. **Derived tables are disposable.** If a formula changes, truncate and recompute.

## Tables

### `raw_samples` — source of truth
| Column | Type | Why |
|---|---|---|
| `id` | BIGINT PK | |
| `endpoint` | VARCHAR(120) | logical name, e.g. `fear_and_greed`, `exchange_listings` |
| `scope` | ENUM('market','asset') | which cron wrote it |
| `fetched_at` | DATETIME(3) UTC | actual response time |
| `http_status` | SMALLINT | 200s and non-200s both stored |
| `credits` | INT | from the response, not estimated |
| `payload` | LONGTEXT | verbatim body. **Not** a JSON column: MySQL reparses those on write, so key order and whitespace are lost and the payload stops being byte-for-byte what CMC sent. See D11. |

Index on `(endpoint, fetched_at)`. This table only ever grows; it is never updated.

### `fetch_log` — every attempt
| Column | Type | Why |
|---|---|---|
| `id` | BIGINT PK | |
| `endpoint` | VARCHAR(120) | |
| `attempted_at` | DATETIME(3) UTC | |
| `http_status` | SMALLINT NULL | null = never got a response |
| `duration_ms` | INT | catches the host being slow |
| `credits` | INT NULL | running budget |
| `error` | TEXT NULL | verbatim, not summarised |

### `market_metric` — extracted, market-wide
One row per sample per metric, long format rather than wide, so a new input does not need a
migration.

| Column | Type |
|---|---|
| `id` | BIGINT PK |
| `raw_sample_id` | BIGINT FK → `raw_samples` |
| `metric` | VARCHAR(60) — `fear_greed`, `post_volume`, `trending_churn`, `turnover`, `exchange_hhi`, `reserve_change` |
| `value` | DECIMAL(24,8) |
| `sampled_at` | DATETIME(3) UTC — denormalised from the raw row for query speed |

Index on `(metric, sampled_at)`.

### `asset_metric` — extracted, per asset
As above plus `cmc_id INT` and `symbol VARCHAR(20)`. Index on `(cmc_id, metric, sampled_at)`.
This is the big table: ~600k rows over three weeks.

### `scores` — derived
| Column | Type | Why |
|---|---|---|
| `id` | BIGINT PK | |
| `scope` | ENUM('market','asset') | |
| `cmc_id` | INT NULL | null for market-wide |
| `sampled_at` | DATETIME(3) UTC | |
| `voice` | DECIMAL(6,2) | 0–100 |
| `money` | DECIMAL(6,2) | 0–100 |
| `divergence` | DECIMAL(7,2) | signed |
| `quadrant` | ENUM | the four readings |
| `basis` | ENUM('fixed','percentile') | which normalisation produced this row |
| `method_version` | SMALLINT | bumped whenever weights change |

`basis` and `method_version` exist so the method page can state honestly which rows were computed
which way, and so a weighting change is visible in the data rather than rewriting the past silently.

### `asset_universe` — which assets are tracked
`cmc_id`, `symbol`, `name`, `rank`, `added_at`, `removed_at`. Membership changes over time and the
change itself is data — an asset entering the top 100 is worth being able to see.

## Open shape questions

- Long format for metrics assumes a handful of metrics. If it turns out to be dozens, revisit.
- Whether `scores` stores every sample or only recomputes on read. Storing is chosen for now
  because the web app must be fast and the table is small.
- Retention: nothing is deleted during the hackathon. Three weeks of data fits.
