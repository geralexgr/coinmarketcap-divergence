# Architecture

![Data flow](mockups/architecture-flow.png)

```
cPanel cron (10 min)               cPanel cron (30 min)
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
        bin/extract.php  (cron, no credits, no network)
       bin/score.php    (cron, no credits, no network)
                     │
      ┌──────────────┴───────────────┐
      ▼                              ▼
  public/                         mcp/server.php
  web app + JSON api/             MCP over stdio
```

Four cron entries, three separable jobs. **Fetch** costs credits and cannot be caught up on later.
**Extract** and **score** cost nothing and are re-runnable over the whole of history, which is why
they are separate processes on offset minutes: a slow or failing derivation must never be able to
delay a fetch.

## Components

### `poller/`
The only component that writes source data. Runs from cron via PHP CLI, never over HTTP.

Responsibilities, and deliberately nothing else:
1. Fetch each endpoint the plan permits — `endpoints_to_poll()` refuses to schedule a 403.
2. Write the raw response body verbatim to `raw_samples`, with the **actual** fetch timestamp.
3. Write one `fetch_log` row per attempt — endpoint, HTTP status, credits consumed, error text.

No parsing, no scoring, no normalisation. Those read from what this wrote and can be rewritten on
day eighteen; this cannot.

Each run is short and stateless. Shared hosts kill long-running processes, so there is no queue, no
daemon, and no in-memory state between runs.

### `app/lib/`
`http.php` (timeouts, retry with backoff, credit parsing, `cmc_outcome()`), `db.php` (PDO, prepared
statements only), `config.php` loading from outside the webroot, `endpoints.php` (the one catalogue
the prober, verifier and poller all share), `extract.php` (pure, single-payload), and `queries.php`
— every read the surfaces make.

### `app/scoring/`
`inputs.php` declares the method as data; `normalise.php` and `score.php` are pure functions over
it; `recompute.php` is the only part that touches the database. Nothing here fetches. This is what
makes rescoring the whole of history a one-line operation and the normalisation testable against
fixtures.

### `public/`
The only web-served directory. Read-only against the database — there is no write path anywhere in
the web app. Three pages and four JSON endpoints, all reading through `app/lib/queries.php`.

### `mcp/`
A JSON-RPC server over stdio, wrapping the same `app/lib/queries.php` functions the web app reads
through. That shared file is what makes the two surfaces answer identically rather than
approximately. No tool takes a write action. See `mcp-tools.md`.

## Cadence

| What | Interval | Credits/day | Notes |
|---|---|---|---|
| Market-wide | 10 min | 576 | global metrics, listings, fear and greed, exchange assets |
| Per asset | 30 min | 48 | top ~100 assets in one batched call |
| Extract | 20 min, offset | 0 | reads stored payloads only |
| Score | 30 min, offset | 0 | reads typed rows only |

About 624 credits a day against a 15,000/month budget. Roughly 2,500 market rows and 250,000 asset
rows over three weeks — 100–150 MB with indexes.

**The cadence is set by the budget, not by preference** (D15). The 5/15-minute cadence this repo was
originally designed around costs ~1,250 a day and exhausts the plan in twelve days.

## Rate and credit budget

- **50 requests/minute**, measured. A per-asset run batches: one call covering 100 assets via the
  comma-separated id list, confirmed against real payloads.
- **15,000 credits/month** — the Basic plan, measured 13 Sep 2026 (D14), not the 300,000 originally
  assumed. Credits per call come back in the response; they are logged rather
  than estimated, so usage is observable.
- Both limits are enforced in `app/lib/http.php`, not in each fetcher.

## Failure behaviour

Nothing about a failure is silent, and nothing about a failure is fatal to the next run.

- A failed fetch writes a `fetch_log` row and the run continues to the next endpoint.
- A gap in `raw_samples` is a real gap and is shown as one. Charts do not interpolate across it.
- An input the scoring layer cannot find is **dropped, not zeroed**, and the score records how many
  inputs it saw. A sample with no Voice input at all writes no score row, so the trail shows a gap
  rather than a point that was never measured.
- The recording-health script (`app/bin/health.php`) reports rows/hour, longest gap, extraction lag and
  failure rate, so a dead poller is noticed the same day rather than at submission time.
- Every web page has an honest empty state naming what is missing. An empty chart would read as a
  measurement of a market where nothing is happening.

## Time

Every timestamp stored is the moment the response came back, in UTC. Cron drifts by seconds to
minutes on shared hosting; the scheduled time is not recorded anywhere because it is not a fact
about the data.
