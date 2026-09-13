# Divergence

**What the crypto market is *saying*, plotted against what it has actually *committed money to*.**

CoinMarketCap publishes both halves of this — sentiment and community activity on one set of pages,
volume and exchange flow on another — and never puts them on the same axis. That gap is the product.

Built for the CoinMarketCap API Hackathon.

---

## The screen

![Divergence market view](docs/mockups/ui-market-view.png)

*Design mockup. Replace with a screenshot of the running deployment once it has banked a few days of
history — a picture of the real thing is worth more here than a drawing of it.*

Voice on the vertical axis, Money on the horizontal, each 0–100. The market sits at a point — and
because the recorder has been running, that point sits at the end of a **trail** showing the path it
took to get there. That trail is the part of this that cannot be reconstructed from an API call, by
anyone, at any later date.

---

## What it measures

Two scores, both normalised 0–100, both sampled continuously.

| Score | Question it answers | Inputs actually in use |
|---|---|---|
| **Voice** | How much is the market talking? | fear and greed index |
| **Money** | How much money is actually moving? | turnover, derivative share of activity, stablecoin share, exchange reserve movement |
| **Divergence** | How far apart are they? | `money − voice`, signed |

Four readings, from where the point sits:

| | Money &lt; 50 | Money ≥ 50 |
|---|---|---|
| **Voice ≥ 50** | Chatter without conviction | Loud and leveraged |
| **Voice &lt; 50** | Apathy | Quiet, but leveraged |

The same two scores are computed **per asset**, which turns the tool into a screener with axes that
exist nowhere on CoinMarketCap's own site: sort the market by how much an asset is being looked at
against how much money is moving through it.

---

## Read this first: what this is not

This tool gives **no financial advice and no recommendations**. Not "consider reducing exposure",
not "this looks overheated", not a buy/sell/hold signal, not a risk rating that implies action.

Every output is a **measurement of a condition that exists right now, or existed at a recorded past
moment**. A quadrant is a label for where a point sits; it is not a rating. If a sentence points at
the future, it is a bug — there is a test that greps the readout copy for exactly that.

Two reasons this is a hard constraint rather than a disclaimer:

1. A measurement can be verified against CoinMarketCap in thirty seconds. A prediction cannot be
   verified at all.
2. Recommendations drag in liability and put the entry in the same bucket as most of the field.

---

## Read this second: what the API plan costs the method

The key behind this deployment is on CoinMarketCap's **Basic** plan. Measured against the live API
rather than read off a pricing page: **7 of the 17 endpoints this design was built on are callable,
and 10 answer HTTP 403.**

The Voice axis takes the damage. Trending, most-visited, community and content are all forbidden,
leaving the fear and greed index — one input, updated **once a day**. So Voice steps daily while
Money moves every ten minutes.

This is stated on the market screen, on the method page, and here, because a tool that showed a flat
Voice line without explaining it would be misrepresenting the market rather than the plan. The
forbidden inputs stay declared in the method at their intended weights and light up automatically if
the plan changes — see [D16](docs/decisions.md).

**And there are no derivatives endpoints at all.** The Money axis was designed around funding rates,
open interest and liquidations. 38 candidate paths probed across `/v1/`–`/v4/`; every one absent —
not 403, absent, so no plan upgrade produces them. What replaced them measures money *moving* and
money *at rest*, not money *committed and leveraged*. See [D10](docs/decisions.md) and
[docs/limits.md](docs/limits.md).

---

## How it works

```
cPanel cron ──► poller/run.php ──► CoinMarketCap API
                     │
                     ▼
                  MySQL
   ├── raw_samples      verbatim JSON + real fetch time   ← source of truth
   ├── fetch_log        every attempt, success or failure
   ├── market_metric    extracted fields, recomputable
   ├── asset_metric     the same per asset
   └── scores           Voice / Money / Divergence
                     │
       ┌─────────────┼──────────────┐
       ▼             ▼              ▼
   public/        mcp/         api/*.php
   web app     MCP server      JSON, read-only
```

Three cron entries, three separable jobs: **fetch** (costs credits, cannot be caught up on later),
**extract** (no credits, no network, re-runnable over all history), **score** (no credits, rebuilt
from scratch whenever a weight changes).

Detail: [`docs/architecture.md`](docs/architecture.md) · Tables: [`docs/data-model.md`](docs/data-model.md)

### Why the recorder is the whole product

CoinMarketCap's API is almost entirely **snapshot data**. Price history can be fetched
retroactively; **positioning and sentiment history cannot**. There is no historical endpoint for
turnover, exchange flow or social volume at usable granularity, and none at all for some of it.

So the trail on the plot, every "up 26 this week" figure, and every quadrant transition exist only
because something was recording at the time. Whoever starts recording on day one owns a dataset
nobody starting later can reconstruct.

### Why raw payloads are stored verbatim

Every sample writes the response body alongside the parsed fields. The parsing was wrong once
already and the weights are provisional — both can be corrected without losing a single sample,
because `bin/extract.php --rebuild` and `bin/score.php --rebuild` re-derive everything from payloads
already on disk. The formula guessed at on day one is not locked in.

---

## Running it

### Requirements

PHP 8.0+ CLI with `curl`, `json` and `pdo_mysql`. MySQL 5.6+. No composer, no framework, no
node, no build step. The only third-party dependency in the project is CoinMarketCap itself — the
quadrant chart is hand-drawn SVG rather than a charting library.

### Setup

```bash
# 1. Does the API surface look the way this repo says it does? No key, no credits.
php app/bin/probe-paths.php

# 2. Point the config at your key and database. Outside the webroot.
cp config.example.php ../config.php && chmod 600 ../config.php

# 3. Can this host actually do the job? Run it ON the host, over SSH.
php app/bin/preflight.php

# 4. Which endpoints does the plan permit? ~6 credits.
php app/bin/verify-endpoints.php --save-fixtures

# 5. Create the tables. One script, safe to re-run.
mysql -u USER -p DB < docs/schema.sql

# 6. One sample, verbose, nothing hidden.
php app/poller/run.php --once

# 7. Read stored payloads into typed rows. No credits, no network.
php app/bin/extract.php --verbose

# 8. Score them.
php app/bin/score.php --verbose

# 9. Is it still recording, and is it still being read?
php app/bin/health.php
```

Then point the document root at `public/` and open it.

**Deploying to cPanel: [DEPLOY.md](DEPLOY.md)** — step by step, written for the download-the-zip
workflow. The reasoning behind each step is in [`docs/deploy.md`](docs/deploy.md).

The tests need no key, no database and no network:

```bash
php tests/run.php
```

### The MCP server

Same scores, as agent tools, over the same queries the web app reads through:

```json
{ "command": "php", "args": ["/home/USER/divergence/app/mcp/server.php"] }
```

Seven tools, none of which takes a write action. Surface: [`docs/mcp-tools.md`](docs/mcp-tools.md).

---

## CoinMarketCap endpoints used

Two different questions, answered by two different scripts. **Does the path exist?** —
`app/bin/probe-paths.php`, no key needed. **May this plan call it?** — `app/bin/verify-endpoints.php`, needs
the key. Both were run; the results are in [`docs/endpoint-access.md`](docs/endpoint-access.md) and
encoded in `endpoint_access_results()` so the poller cannot schedule a forbidden endpoint by
accident.

| Axis | Endpoint | Used for | Access |
|---|---|---|---|
| Voice | `/v3/fear-and-greed/latest` | market-wide sentiment level | ✅ |
| Money | `/v1/global-metrics/quotes/latest` | turnover, derivative share, stablecoin share | ✅ |
| Money | `/v1/exchange/assets` | exchange reserve level, and its movement | ✅ |
| Money | `/v2/cryptocurrency/quotes/latest` | per-asset turnover | ✅ |
| Both | `/v1/cryptocurrency/listings/latest` | the asset universe and its per-asset inputs | ✅ |
| Ops | `/v1/key/info` | credit budget, at no credit cost | ✅ |
| Voice | `/v1/community/trending/{topic,token}` | trending rank and its churn | 403 |
| Voice | `/v1/cryptocurrency/trending/{latest,most-visited,gainers-losers}` | attention before a trade | 403 |
| Voice | `/v1/content/{latest,posts/top}` | community post volume | 403 |
| Money | `/v1/exchange/listings/latest` | concentration of volume across venues | 403 |
| Money | `/v2/cryptocurrency/market-pairs/latest` | derivative pair volume | 403 |

### A real request and response

```bash
curl -s -H "X-CMC_PRO_API_KEY: $KEY" \
  "https://pro-api.coinmarketcap.com/v1/global-metrics/quotes/latest?convert=USD"
```

```json
{
  "data": {
    "quote": { "USD": {
      "total_market_cap": 2642374254402.301,
      "total_volume_24h": 41470272862.18,
      "total_volume_24h_reported": 213171617521.92,
      "stablecoin_volume_24h": 40784975505.60018,
      "derivatives_volume_24h": 307986299285.3649,
      "last_updated": "2026-09-13T04:37:59.999Z"
    } },
    "btc_dominance": 58.682385998691,
    "active_cryptocurrencies": 8172
  },
  "status": { "error_code": "0", "credit_count": 1, "elapsed": 12 }
}
```

Two of the Money inputs are derived from that single response:

```
market_turnover     = 41470272862.18 / 2642374254402.30  = 0.0157
derivatives_to_spot = 307986299285.36 / 41470272862.18   = 7.43
```

Derivative volume was **7.4× spot volume** at that moment. That ratio is the closest this API gets
to a leverage reading, and it is not on a chart anywhere on CoinMarketCap's site.

### The trap that made this worth checking twice

`pro-api.coinmarketcap.com` answers an **unknown path with HTTP 200**, carrying
`status.error_code: 500` and "The system is busy, please try again later!". Controls:
`/v3/totally-made-up/xyz` and `/v5/anything` both do it.

Reading the HTTP status alone recorded six non-existent derivatives endpoints as working. Every
response in this repo is classified by `cmc_outcome()` in [`lib/http.php`](lib/http.php), which
reads the body's error code as well as the status, and `app/bin/probe-paths.php` runs two control paths
on every invocation so a change in CMC's routing surfaces as a failed control rather than as
silently wrong results.

The same quirk is what makes the prober work at all: CMC resolves the path *before* it validates the
key, so an invalid key returns 401 on a real path and 404 on a fake one. The whole API surface can be
mapped with no key and no credits.

---

## Method

Published, not hidden. The method page in the app renders directly from `scoring/inputs.php` — the
same declaration the scorer runs from — so the page cannot describe a method the code does not
implement.

Three things it states that a black box would not:

- **Missing inputs are dropped, not zeroed**, and the surviving weights renormalise. Each score
  records how many of its declared inputs it actually saw. A zero would read as a measurement of a
  quiet market; absence is a measurement of nothing.
- **The normalisation switchover.** The first seven days use fixed reference ranges, because
  percentile rank against no history is meaningless. After that, percentile rank within a trailing
  window. The switchover date is on the page and the basis is stored on every row.
- **Per-asset scores use a third basis** — ranked against the rest of the universe at the same
  instant rather than against their own past ([D17](docs/decisions.md)). Different measurement,
  never plotted on the same chart.

Full spec: [`docs/method.md`](docs/method.md) · What it cannot see: [`docs/limits.md`](docs/limits.md)

---

## Repo layout

```
divergence/
├── DEPLOY.md              cPanel, step by step
├── config.example.php     shape of the real config, which lives outside the webroot
│
├── app/                   ← everything that must NOT be web-reachable
│   ├── poller/run.php       the recorder — the only writer of source data
│   ├── lib/                 config, http client, db writers, endpoint catalogue, queries
│   ├── scoring/             the method as data, normalisation, and the recompute pass
│   ├── mcp/server.php       MCP server over the same queries
│   └── bin/                 probe-paths · verify-endpoints · preflight · extract · score · health
│
├── public/                ← the document root, and the ONLY web-served directory
│
├── tests/                 58 tests, no framework, no network, no database
└── docs/                  schema.sql · method · data model · decisions · limits · API friction
```

The `app/` and `public/` split is the deployment boundary, not decoration: `app/` holds the API key
loader and the poller, and a browser must never reach it. `app/.htaccess` denies everything as a
second line of defence, and the web app refuses to start if it finds the config inside the document
root.

---

## Constraints

- **50 requests/minute**, measured. Per-asset polling is batched: 100 assets is one call.
- **15,000 credits/month** on the Basic plan, measured rather than assumed. This is the binding
  constraint on the whole product. Credits are read from each response and logged, not estimated.
- **Cadence: 10 minutes market-wide, 30 minutes per asset** — set by the credit budget, not by
  preference. About 624 credits a day. See [D15](docs/decisions.md) for the arithmetic.
- Every cron run is short and stateless, and takes a lock so a slow run makes the next tick skip
  rather than pile up behind it. Shared hosts kill long-running processes.
- The API key lives **outside the webroot** and never enters git.

---

## Licence

MIT — see [LICENSE](LICENSE).
