# Data model

All of these tables are **built** — `sql/001_init.sql` for the recording core and
`sql/002_derived.sql` for everything derived from it. The column comments in those files are
authoritative where this page disagrees.

`scores` is migrated but not yet written to: the scoring layer is the next thing to build. Every
other table is populated, by `poller/run.php` and `bin/extract.php` respectively.

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
| `raw_sample_id` | BIGINT → `raw_samples` |
| `endpoint` | VARCHAR(120) — denormalised, so "where did this number come from" is one query |
| `metric` | VARCHAR(60) |
| `value` | DECIMAL(24,8) |
| `sampled_at` | DATETIME(3) UTC — denormalised from the raw row for query speed |

Unique on `(raw_sample_id, metric)`, index on `(metric, sampled_at)`.

The unique key is load-bearing rather than tidiness: it is what lets `bin/extract.php --rebuild`
re-read every payload ever stored and update the series in place instead of doubling it.

What `lib/extract.php` writes today:

| Metric | From | Axis |
|---|---|---|
| `market_turnover` | `global_metrics` — total volume ÷ total market cap | money |
| `total_market_cap`, `total_volume_24h`, `altcoin_volume_24h` | `global_metrics` | money |
| `stablecoin_volume_24h`, `stablecoin_volume_share` | `global_metrics` | money |
| `derivatives_volume_24h` | `global_metrics`, **if the field exists** — see D10 | money |
| `btc_dominance`, `eth_dominance`, `active_cryptocurrencies` | `global_metrics` | context |
| `exchange_hhi`, `exchange_top5_share`, `exchange_volume_total`, `exchange_count` | `exchange_listings` | money |
| `fear_greed` | `fear_and_greed` | voice |
| `post_count`, `post_comments`, `post_likes`, `post_engagement` | `content_latest` | voice |
| `trending_asset_count`, `trending_topic_count` | the trending endpoints | voice |
| `listings_count`, `quotes_asset_count` | listings and quotes — batch-size evidence | support |
| `credits_used_month`, `credits_left_month`, `credits_used_today` | `key_info` | support |

Three things are deliberately **not** here, because each needs two samples and extraction sees
exactly one: trending churn, exchange reserve *movement*, and every percentile. They belong to the
scoring layer, which can see the series. Keeping extraction single-payload is what makes a rebuild
order-independent.

### `asset_metric` — extracted, per asset
As above plus `cmc_id INT` and `symbol VARCHAR(32)`, unique on `(raw_sample_id, cmc_id, metric)`,
index on `(cmc_id, metric, sampled_at)`. This is the big table: ~600k rows over three weeks.

Metrics: `turnover` (the per-asset Money input), `volume_24h`, `market_cap`, `volume_change_24h`,
`percent_change_24h`, `price`, `cmc_rank`, and `trend_rank` from each trending list.

The symbol is stored alongside the id because the screener orders by metric and prints a symbol,
and that should not need a join per row. The **id** is the identity; symbols get reused.

### `extraction_log` — which payloads have been read
`raw_sample_id`, `extractor_version`, `extracted_at`, `status` (`ok`/`skipped`/`error`),
`rows_written`, `note`. Primary key `(raw_sample_id, extractor_version)`.

This is what makes re-parsing history a one-line operation. `bin/extract.php` processes any raw
sample with no row here at the current `EXTRACTOR_VERSION`, so bumping that constant in
`lib/extract.php` and running `--rebuild` re-reads every payload ever stored.

`note` holds the verbatim reason a payload was not read — an API error body, a plan refusal, a
shape with no extractor. `bin/health.php` prints the top reasons, because a new one appearing is
usually CoinMarketCap changing a response shape and should be read the same day.

### `scores` — derived
| Column | Type | Why |
|---|---|---|
| `id` | BIGINT PK | |
| `scope` | ENUM('market','asset') | |
| `cmc_id` | INT NOT NULL | **0** for market-wide, not NULL: MySQL permits repeated NULLs in a UNIQUE index, so a nullable column would let every recompute insert a duplicate instead of updating |
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
`cmc_id`, `symbol`, `name`, `rank_last`, `first_seen`, `last_seen`. Membership changes over time
and the change itself is data — an asset entering the top 100 is worth being able to see.

`first_seen`/`last_seen` rather than `added_at`/`removed_at`: a departure is not an event the API
reports, it is an absence, so it is recorded as `last_seen` ceasing to move. The upsert uses
`LEAST`/`GREATEST` so that a rebuild, which may process samples in any order, cannot move an
asset's arrival forward in time.

## Open shape questions

- Long format for metrics assumes a handful of metrics. If it turns out to be dozens, revisit.
- Whether `scores` stores every sample or only recomputes on read. Storing is chosen for now
  because the web app must be fast and the table is small.
- Retention: nothing is deleted during the hackathon. Three weeks of data fits.
