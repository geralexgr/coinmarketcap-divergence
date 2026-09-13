# Endpoint access

Two different questions, answered by two different scripts, and they are easy to confuse:

1. **Does the path exist on the API at all?** — `bin/probe-paths.php`. No key needed.
   **Answered 12 September 2026, below.**
2. **Does our plan let us call it?** — `bin/verify-endpoints.php`. Needs the real key.
   **Answered 13 September 2026.** 17 endpoints called, 6 credits spent.

**Plan tier: Basic, not Startup.** `/v1/key/info` reports `credit_limit_monthly: 15000` and
`rate_limit_minute: 50`. Seven endpoints are callable and ten answer HTTP 403. This is the single
most consequential fact discovered about the project so far — see [decisions.md](decisions.md) D14
and D15.

| | Callable | Forbidden |
|---|---|---|
| **Voice** | `fear_and_greed`, `fear_and_greed_historical` | all 5 trending/community endpoints, both content endpoints |
| **Money** | `quotes_latest`, `exchange_assets` | `exchange_listings`, `market_pairs_derivatives`, `price_performance` |
| **Support** | `key_info`, `global_metrics`, `listings_latest` | — |

Two headline consequences:

- **The Voice axis lost six of its seven inputs.** What is left is the fear and greed index: one
  number, updated **once a day**. An axis sampled every ten minutes off a daily number has daily
  resolution, and `docs/method.md` must say so rather than implying otherwise.
- **The Money axis got a positioning number back.** `global_metrics` carries
  `derivatives_volume_24h` — $308bn against $41bn of adjusted spot volume on 13 Sep 2026 — plus
  `derivatives_24h_percentage_change`. Market-wide only, no funding rate, no open interest, no
  per-asset split. D10 stands per asset and is amended market-wide.

`lib/endpoints.php` encodes these results in `endpoint_access_results()`, and `endpoints_to_poll()`
refuses to schedule anything marked forbidden — a 403 costs a round trip, returns nothing, and
buries real failures in `fetch_log`.

The full generated table, one row per real call, is in
[`endpoint-access.generated.md`](endpoint-access.generated.md), rewritten by every verifier run.

---

## How question 1 was answered without a key

`pro-api.coinmarketcap.com` resolves the path **before** it validates the key. So an invalid key
still separates real endpoints from imaginary ones:

| Response to an invalid key | Means |
|---|---|
| `401` invalid key | the path exists |
| `400` missing parameter | the path exists |
| `404` | the path does not exist |
| `200` with `status.error_code: 500`, "The system is busy, please try again later!" | **the path does not exist** |

That last row is a trap worth stating plainly. An unknown path under any version prefix answers
**HTTP 200**. Verified against controls: `/v3/totally-made-up/xyz` and `/v5/anything` both return
it. Reading the HTTP status alone would have recorded six non-existent derivatives endpoints as
working. `cmc_outcome()` in `lib/http.php` encodes the distinction, and `bin/probe-paths.php`
runs the two control paths on every invocation so a routing change surfaces as a failed control
rather than as quietly wrong results.

---

## Money axis — settled, and not the way this design assumed

**There is no derivatives data on the CoinMarketCap API.** 38 candidate paths probed on
12 September 2026 across `/v1/` through `/v4/`: funding rate, open interest, liquidations,
futures, perpetuals, derivatives listings, derivatives quotes, derivatives exchanges. Every one
absent. Not 403 — absent. No plan upgrade produces them.

| Path | Exists | Note |
|---|---|---|
| `/v1..v4/derivatives/listings/latest` | ⛔ | absent at every version |
| `/v1..v4/derivatives/quotes/latest` | ⛔ | absent at every version |
| `/v1..v4/derivatives/exchanges/latest` | ⛔ | absent at every version |
| `/v1..v4/derivatives/funding-rate/latest` | ⛔ | absent at every version |
| `/v1..v4/derivatives/open-interest/latest` | ⛔ | absent at every version |
| `/v1..v4/derivatives/liquidations/latest` | ⛔ | absent at every version |
| `/v1..v4/futures/listings/latest` | ⛔ | absent at every version |
| `/v1..v4/perpetuals/listings/latest` | ⛔ | absent at every version |
| `/v1/{open-interest,funding-rate,liquidations}/latest` | ⛔ | absent |
| `/v1/cryptocurrency/derivatives/latest`, `/v1/exchange/derivatives/latest` | ⛔ | absent |

Recorded as **D10** in `decisions.md`. The Money axis is rebuilt from what does exist:

| Endpoint | Field needed | Exists | Plan access | Notes |
|---|---|---|---|---|
| `/v2/cryptocurrency/quotes/latest` | `volume_24h`, `market_cap`, `volume_change_24h` | ✅ | ✅ | Turnover = volume ÷ market cap. Batched by id list. |
| `/v1/exchange/listings/latest` | per-exchange 24h volume | ✅ | ❌ 403 | Concentration across venues. |
| `/v1/exchange/assets` | exchange wallet balances | ✅ | ✅ | Reserve movement. One call per exchange. |
| `/v2/cryptocurrency/market-pairs/latest` | derivative pair volume, `category=derivatives` | ✅ | ❌ 403 | Forbidden on Basic, so **the open interest question cannot be answered without a plan upgrade.** |
| `/v1/global-metrics/quotes/latest` | `derivatives_volume_24h`, if present | ✅ | ✅ | **Inspected 13 Sep 2026: the field is there and is callable.** $308bn. Also carries `derivatives_24h_percentage_change`, `total_volume_24h_reported` (5.1x adjusted), the defi and stablecoin blocks, and yesterday's deltas for cap, volume and dominance. |

## Voice axis — paths confirmed, and then mostly taken away

Open question 3 is answered on the first half: fear and greed **is** an API endpoint, not only a
chart on the site. Question 1 is answered on the second half, and the answer is that **seven of the
nine Voice endpoints are forbidden on this plan.** Everything below with a ❌ exists, works, and is
not ours.

| Endpoint | Field needed | Exists | Plan access | Notes |
|---|---|---|---|---|
| `/v3/fear-and-greed/latest` | current index 0–100 | ✅ | ✅ | |
| `/v3/fear-and-greed/historical` | index history | ✅ | ✅ | **500 daily points, back to 1 May 2025.** The only backfillable input in the product — and now nearly the whole Voice axis. Payload arrives **newest-first**. |
| `/v1/community/trending/topic` | trending topics and rank | ✅ | ❌ 403 | |
| `/v1/community/trending/token` | trending tokens and rank | ✅ | ❌ 403 | |
| `/v1/cryptocurrency/trending/most-visited` | page views per asset | ✅ | ❌ 403 | Attention that has not yet become a trade. |
| `/v1/cryptocurrency/trending/latest` | trending search list | ✅ | ❌ 403 | |
| `/v1/cryptocurrency/trending/gainers-losers` | largest movers | ✅ | ❌ 403 | Price-derived; recorded, probably not an input. |
| `/v1/content/latest` | community post volume | ✅ | ❌ 403 | |
| `/v1/content/posts/top` | per-asset post engagement | ✅ | ❌ 403 | Needs an `id`, so one call per asset. |

## Universe and support

| Endpoint | Field needed | Exists | Plan access | Notes |
|---|---|---|---|---|
| `/v1/key/info` | credits used and remaining | ✅ | ✅ | Costs no credits. |
| `/v1/cryptocurrency/listings/latest` | top 100: id, symbol, rank, volume | ✅ | ✅ | One call covers the universe. |
| `/v1/global-metrics/quotes/latest` | total cap, total volume, dominance | ✅ | ✅ | |
| `/v1/cryptocurrency/categories`, `/v1/cryptocurrency/airdrops` | — | ✅ | ❓ | Exist; no use identified yet. |
| `/v4/dex/listings/quotes`, `/v4/dex/networks/list`, `/v4/dex/spot-pairs/latest` | on-chain volume | ✅ | ❓ | Unexplored. On-chain flow would be a genuine Money input if the plan reaches it. |

---

## Host checks — ✅ run on the cPanel host, 13 September 2026

`php app/bin/preflight.php`, over SSH on the deployment host. Every blocking check passed.

**This is what closed the last open question.** Everything above was measured from a laptop, which
answers what the *plan* permits and says nothing about what the *host* can reach — and some shared
hosts firewall outbound connections from PHP CLI differently from the web SAPI. The
`Authenticated call from PHP CLI` row is the one that mattered, and it is a 200.

| Check | Result |
|---|---|
| PHP CLI binary path | ✅ /opt/alt/php83/usr/bin/php |
| PHP version | ✅ 8.3.33 (need 8.1+) |
| SAPI is CLI | ✅ cli |
| Extension: curl | ✅ loaded |
| Extension: pdo_mysql | ✅ loaded |
| Extension: json | ✅ loaded |
| Extension: openssl | ✅ loaded |
| Extension: zlib | ✅ loaded |
| max_execution_time | ✅ unlimited (CLI default) |
| Config file found | ✅ at the deployment root |
| API key present | ✅ redacted in output, as it is everywhere |
| Config is outside the webroot | ✅ yes |
| DNS resolves `pro-api.coinmarketcap.com` | ✅ 13.227.192.77 |
| TLS connect to `pro-api.coinmarketcap.com:443` | ✅ connected |
| **Authenticated call from PHP CLI** | **✅ HTTP 200** |
| Credits / month | ✅ 14 used of 15,000 |
| Rate limit / minute | ✅ 50 |
| MySQL connect | ✅ 10.11.19-MariaDB-cll-lve |
| MySQL 5.7+ | ✅ 10.11.19-MariaDB-cll-lve |
| Schema applied | ✅ raw_samples + fetch_log exist |
| Derived tables applied | ✅ market_metric + asset_metric + scores exist |
| DB user can INSERT | ✅ yes |
| Log directory writable | ✅ inside the deployment folder |

Two things worth carrying into any redeployment:

**The CLI binary is `/opt/alt/php83/usr/bin/php`.** Not `/usr/local/bin/php`, and not the binary the
web server uses — this host is CloudLinux with per-account PHP selection. Cron's `PATH` is not a
login shell's, so every cron entry needs that absolute path or it silently runs nothing.

**`Plan tier` reports "unknown".** `/v1/key/info` does not return a tier name on this plan, so the
tier is inferred from the credit limit rather than read: 15,000/month is Basic. The limit itself is
reported directly and is what the budget arithmetic uses, so nothing depends on the name.

## Batching — ❓ open

Settled by reading a real payload, so it needs the key.

| Endpoint | Accepts id list | Max ids/call |
|---|---|---|
| `/v2/cryptocurrency/quotes/latest` | expected yes | ✅ — poller assumes 100 |
| `/v2/cryptocurrency/market-pairs/latest` | expected no, one id | ❌ 403 |
| `/v1/content/posts/top` | no, one id | 1 |
