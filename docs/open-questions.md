# Open questions

These block real design decisions. Check them by making actual calls with the real key from the
real host, not by reading documentation. Answers go into `endpoint-access.md`; decisions that follow
from them go into `decisions.md`.

Status as of 12 September 2026: questions 2 and 3 answered, the rest open.

---

## 1. Which endpoints the plan permits — ✅ answered 13 September 2026, badly

Moved to the answered section below, because the answer changed the product rather than confirming
it. **The key is on Basic, not Startup: 15,000 credits a month and 7 of 17 endpoints callable.**
See the answered section, `endpoint-access.md`, and decisions D14 and D15.

---

## 4. cPanel cron minimum interval — ❓ open

Most shared hosts allow every minute; some enforce a 5 or 15 minute floor.

**What it changes:** the market-wide cadence, and therefore the density of the trail on the main
chart. A 15-minute floor is still fine. An hourly floor would need a rethink.

**Note:** the poller takes a per-scope lock, so if the host fires ticks faster than a run finishes,
the extra ticks skip rather than pile up.

---

## 5. Outbound HTTPS from PHP CLI — ❓ open

Confirm from the server that PHP CLI can reach `pro-api.coinmarketcap.com`. Some shared hosts
firewall outbound connections from CLI differently than from the web SAPI, and this would be a bad
thing to discover after building the poller.

**How to settle it:** `php bin/preflight.php` on the host. It checks DNS, a raw TLS connect, and a
real authenticated call, alongside the PHP version, extensions, MySQL grants and log directory.

---

## 6. Batching support per endpoint — ❓ open

Whether the per-asset endpoints accept comma-separated id lists, and the maximum ids per call.

**What it changes:** whether 100 assets costs 1 request or 100, which determines whether the
30 requests/minute limit constrains the asset universe at all.

**How it gets answered, as of 13 Sep 2026:** by query, not by watching a terminal once.
`lib/extract.php` records `quotes_asset_count` for every `quotes_latest` sample — the number of
assets the payload actually carried. The poller requests 100 ids per call, so:

```sql
SELECT MIN(value), MAX(value), COUNT(*) FROM market_metric WHERE metric = 'quotes_asset_count';
```

A max below 100 is the cap, and it is then visible for every sample rather than for one.

---

## 7. Normalisation window length — ❓ open, not blocking

Once percentile ranking switches on, how long is the trailing window? 30 days is the instinct, but
the hackathon only runs three weeks, so the window can never exceed the recording length. Decide
once there is data to look at.

---

## Answered

### 1. Which endpoints the plan permits — ✅ answered 13 September 2026

`php bin/verify-endpoints.php --save-fixtures`, 17 calls, 6 credits.

**Basic plan: `credit_limit_monthly: 15000`, `rate_limit_minute: 50`.** Not the Startup tier this
repo assumed, and not the 300,000 credits every planning document was written against.

Callable (7): `key_info`, `global_metrics`, `listings_latest`, `fear_and_greed`,
`fear_and_greed_historical`, `quotes_latest`, `exchange_assets`.

Forbidden with HTTP 403 (10): all five trending/community endpoints, both content endpoints,
`exchange_listings`, `market_pairs_derivatives`, `price_performance`.

**What it changed:** the Voice axis lost six of its seven inputs and is down to a daily index. The
Money axis gained `derivatives_volume_24h` from `global_metrics`, which partly reverses D10. The
cadence dropped to 10/30 minutes because the old one exhausted the real budget in twelve days. All
three are written up in D14 and D15.

**What it left open:** whether the Voice axis survives as an axis, or is rebuilt on what is
reachable with the resolution stated plainly. That is a product decision, not a measurement.

### 2. Derivatives access — ✅ answered 12 September 2026: there is none

38 candidate paths probed across `/v1/` to `/v4/` — funding rate, open interest, liquidations,
futures, perpetuals, derivatives listings/quotes/exchanges. Every one absent. Not 403: the paths do
not exist, so no plan upgrade produces them. Reproduce with `php bin/probe-paths.php`.

The Money axis is rebuilt from turnover, exchange concentration and reserve movement. Recorded as
**D10**, and the method page states the substitution and what it costs in those words.

Two payload inspections could still partly reverse this and are the first thing to do with a real
key: whether `global_metrics` carries `derivatives_volume_24h`, and whether
`market-pairs/latest?category=derivatives` carries open interest.

### 3. Fear and greed via API — ✅ answered 12 September 2026: it is an endpoint

`/v3/fear-and-greed/latest` and `/v3/fear-and-greed/historical` both resolve — they answer 401 to an
invalid key rather than 404, which is the signal that the path exists. It is not only a chart on the
site.

Whether our plan may call it is question 1, still open. The historical endpoint is the more
interesting half: it is the one input that might have real history behind it, which would let the
Voice axis be backfilled to before recording started. Nothing else can be.

---

When an item is settled, move it here with the answer and the date, and add the resulting decision
to `decisions.md`.
