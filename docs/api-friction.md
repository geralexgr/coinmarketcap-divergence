# Where the API got in the way

The submission asks for this note. It is written from evidence rather than recollection —
`fetch_log` records every attempt, status, and error, so this file is a summary of queryable facts.

Fill it in as it happens, with dates. Do not save it up for the last day; the details are what make
it worth reading and they are the first thing to be forgotten.

The three entries below are the ones that changed the product rather than merely annoyed us. Once
the poller has been running, add whatever `fetch_log` turns up:

```sql
SELECT endpoint, http_status, count(*) n, min(attempted_at), max(attempted_at)
  FROM fetch_log WHERE http_status <> 200 OR error IS NOT NULL
 GROUP BY 1, 2 ORDER BY n DESC;
```

## Template for an entry

### [Date] — short title
**What we wanted:** the measurement we were trying to take.
**What happened:** the actual API behaviour — status codes, payload shape, missing field.
**Evidence:** the `fetch_log` query or sample ids that show it.
**What we did instead:** the workaround, and what it cost in accuracy or coverage.

---

## Entries

### 12 September 2026 — the Money axis had no data behind it

**What we wanted:** funding rates, open interest and liquidations. The Money axis was designed
around them, and they are the half of the product CoinMarketCap's own site cannot show alongside
sentiment.

**What happened:** they do not exist on the API. 38 candidate paths probed across `/v1/` to `/v4/`
— derivatives listings, quotes and exchanges; funding rate; open interest; liquidations; futures;
perpetuals. Every one absent. Not 403, which would have been a plan question with a plan answer.
Absent, which no upgrade fixes.

**Evidence:** `php bin/probe-paths.php` reproduces the whole run in about a minute. Full table in
`endpoint-access.md`.

**What we did instead:** rebuilt the Money axis from turnover (`volume_24h / market_cap`), volume
concentration across exchanges, and exchange reserve movement — decision D10.

**What it cost:** the axis now measures money *moving* rather than money *committed and leveraged*.
Turnover cannot distinguish a large spot rotation from a leveraged build-up, because nothing in the
available data carries leverage. The method page states this in those words rather than implying a
precision the inputs do not have.

### 12 September 2026 — an unknown path answers HTTP 200

**What we wanted:** to know which endpoints exist, which is the first question of the project.

**What happened:** `pro-api.coinmarketcap.com` answers a request to a path it does not recognise
with **HTTP 200**, carrying `status.error_code: 500` and "The system is busy, please try again
later!". Controls: `/v3/totally-made-up/xyz` and `/v5/anything` both return it.

Six non-existent derivatives paths were briefly recorded as *working* because the first pass read
the HTTP status and not the body. A generic "system is busy" message is also actively misleading —
it reads as a transient outage worth retrying, not as "this endpoint has never existed".

**Evidence:** `cmc_outcome()` in `lib/http.php` documents the two control paths and the
classification. `bin/probe-paths.php` re-runs both controls on every invocation.

**What we did instead:** every response in the project is classified by body error code as well as
HTTP status. The prober fails loudly if its controls stop reading as absent.

**What it gave us:** the same quirk turned out to be useful. CMC resolves the path *before* it
validates the key, so an invalid key returns 401 on a real path and 404 on a fake one — the API
surface can be mapped with no key and no credits at all. That is how the derivatives question got
answered on day one, without waiting for a key to arrive.

## Expected entries, still to confirm

These are anticipated rather than observed. Confirm or delete each one.

- **No historical endpoints for sentiment or positioning.** The single largest constraint on the
  product, and the reason the recorder exists at all. Price history is retroactively available;
  social volume, turnover and exchange flow are not. One possible exception:
  `/v3/fear-and-greed/historical` exists, and if the plan reaches it, it is the only input that can
  be backfilled to before recording started.
- **Tier gaps.** Startup gives 23 latest-data endpoints against Standard's 35. 26 paths are
  confirmed to exist; which of them the plan actually permits goes here once
  `bin/verify-endpoints.php` has been run with the real key.
- **Rate limit vs breadth.** 30 requests/minute against a desire to track the top 100 assets. What
  batching support actually exists decided the universe size.
- **Credit accounting.** `status.credit_count` is in every response body, so credits are recorded
  per call rather than estimated. Whether the figure is reliable goes here.

## Running notes

Append as you go, dated, even when it is small.

---

### 13 September 2026 — two thirds of the product is behind a paywall we did not know we were on

**What we wanted:** the Voice axis. Social volume, trending rank churn and community post counts —
the half of the product that measures what the market is *saying*, and the half CoinMarketCap does
not plot against money anywhere on its own site.

**What happened:** the key is on the **Basic** plan, not the Startup tier every planning document in
this repo had been written against. `/v1/key/info` reports `credit_limit_monthly: 15000` — not
300,000 — and `rate_limit_minute: 50`.

Of the 17 endpoints this design was built on, **7 are callable and 10 answer HTTP 403**: both
community trending endpoints, all three cryptocurrency trending endpoints, both content endpoints,
`exchange/listings/latest`, `market-pairs/latest` and `price-performance-stats/latest`.

The Voice axis lost six of its seven inputs in one afternoon. What remains is the fear and greed
index, which updates **once a day**.

**Evidence:** `php bin/verify-endpoints.php --save-fixtures` — 17 calls, 6 credits, saved verbatim.
The measured result is encoded in `endpoint_access_results()` in `lib/endpoints.php` and rendered
live on the method page, so the gap between the designed product and the running one is visible to
anyone who opens it rather than buried in this file.

**What we did instead, and what it cost:**

*The Voice axis ships at daily resolution and says so on the chart.* Not upgrading the plan was a
deliberate choice (D16): a product that needs a paid tier to be demonstrable is one a judge with
their own Basic key cannot evaluate. The cost is real and visible — Voice steps once a day while
Money moves every ten minutes, so the trail is mostly horizontal with daily vertical steps. The
forbidden inputs stay declared in the method at their intended weights and light up with no code
change if access ever widens.

*The credit budget rewrote the cadence.* 15,000 a month against the planned 5-minute/15-minute
sampling is about 1,250 credits a day — the budget exhausted in **twelve days**, mid-hackathon, with
the poller stopping a week before submission. Recalculated to 10 and 30 minutes, 624 a day (D15).

*One thing got better.* `global-metrics` turned out to carry `derivatives_volume_24h`, which partly
reverses the derivatives finding above: derivative volume against spot volume — 7.4x when measured —
is the one genuinely positioning-shaped number reachable on this API, and it is now the second
heaviest input on the Money axis.

**The wider lesson:** the plan was assumed from the documentation for two days of design work before
anyone called `/v1/key/info`. That call is free, takes one second, and would have reshaped the
product before it was designed rather than after. It is now the first endpoint in the catalogue and
the first thing `bin/preflight.php` reports.
