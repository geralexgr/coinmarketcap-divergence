# Architecture

![Data flow](mockups/architecture-flow.png)

```
cPanel cron (5 min)                cPanel cron (15 min)
      │                                  │
      ▼                                  ▼
  poller/run.php --market          poller/run.php --assets
      │                                  │
      └──────────────┬───────────────────┘
                     ▼
             CoinMarketCap API
             (voice + money endpoints)
                     │
                     ▼
                   MySQL
   ┌─────────────────┴──────────────────┐
   │ raw_samples   raw JSON + real ts   │  source of truth, never discarded
   │ fetch_log     every attempt        │  incl. failures, status, credits
   │ market_metric extracted fields     │  derived, recomputable
   │ asset_metric  extracted per asset  │  derived, recomputable
   │ scores        Voice/Money/Div      │  derived, recomputable
   └─────────────────┬──────────────────┘
                     ▼
              scoring/ (pure functions over stored rows)
                     │
      ┌──────────────┼───────────────┐
      ▼              ▼               ▼
  public/         mcp/            alerts
  web app      MCP server      (transitions)
```

## Components

### `poller/`
The only component that writes source data. Runs from cron via PHP CLI, never over HTTP.

Responsibilities, in order:
1. Fetch each configured endpoint.
2. Write the raw response body verbatim to `raw_samples`, with the **actual** fetch timestamp.
3. Write one `fetch_log` row per attempt — endpoint, HTTP status, credits consumed, error text.
4. Extract typed fields into `market_metric` / `asset_metric`.

Step 2 happens before step 4 and is independent of it. If extraction throws, the raw payload is
already banked and can be re-extracted later.

Each run is short and stateless. Shared hosts kill long-running processes, so there is no queue, no
daemon, and no in-memory state between runs.

### `lib/`
`http.php` (timeouts, retry with backoff, credit header parsing), `db.php` (PDO, prepared
statements only), `config.php` loader reading from outside the webroot.

### `scoring/`
Pure functions: rows in, scores out. No fetching, no side effects beyond writing the `scores`
table. This is what makes recomputation over all history cheap and what makes the normalisation
testable with fixtures.

### `public/`
The only web-served directory. Read-only against the database. No write endpoint exists anywhere in
the web app — the only writer is cron.

### `mcp/`
Thin wrapper over the same queries `public/` uses. Deliberately last: it adds nothing the scores do
not already contain, and it cannot exist before them. See `mcp-tools.md`.

## Cadence

| What | Interval | Rows/day | Notes |
|---|---|---|---|
| Market-wide | 5 min | ~288 | fear and greed, aggregate social, aggregate derivatives |
| Per asset | 15 min | ~9,600 | top ~100 assets, batched |

Roughly 6k market rows and 600k asset rows over three weeks — about 100 MB with indexes. Comfortable
on shared hosting.

## Rate and credit budget

- **30 requests/minute.** A per-asset run must batch: one call covering many assets wherever the
  endpoint supports a comma-separated id list, and a hard cap of 100 assets in the universe.
- **300,000 credits/month.** Credits per call come back in the response; they are logged rather
  than estimated, so usage is observable.
- Both limits are enforced in `lib/http.php`, not in each fetcher.

## Failure behaviour

Nothing about a failure is silent, and nothing about a failure is fatal to the next run.

- A failed fetch writes a `fetch_log` row and the run continues to the next endpoint.
- A gap in `raw_samples` is a real gap and is shown as one. Charts do not interpolate across it.
- The recording-health script (`bin/`) reports rows/hour, longest gap, and failure rate, so a dead
  poller is noticed the same day rather than at submission time.

## Time

Every timestamp stored is the moment the response came back, in UTC. Cron drifts by seconds to
minutes on shared hosting; the scheduled time is not recorded anywhere because it is not a fact
about the data.
