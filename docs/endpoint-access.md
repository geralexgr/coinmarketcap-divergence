# Endpoint access

Two different questions, answered by two different scripts, and they are easy to confuse:

1. **Does the path exist on the API at all?** — `bin/probe-paths.php`. No key needed.
   **Answered 12 September 2026, below.**
2. **Does our plan let us call it?** — `bin/verify-endpoints.php`. Needs the real key, run from
   the host. **Still open — the ❓ column below.**

Plan tier: Startup — 23 latest-data endpoints against Standard's 35.

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
| `/v2/cryptocurrency/quotes/latest` | `volume_24h`, `market_cap`, `volume_change_24h` | ✅ | ❓ | Turnover = volume ÷ market cap. Batched by id list. |
| `/v1/exchange/listings/latest` | per-exchange 24h volume | ✅ | ❓ | Concentration across venues. |
| `/v1/exchange/assets` | exchange wallet balances | ✅ | ❓ | Reserve movement. One call per exchange. |
| `/v2/cryptocurrency/market-pairs/latest` | derivative pair volume, `category=derivatives` | ✅ | ❓ | **The last route to anything derivative-shaped.** Whether the payload carries open interest is unconfirmed — inspect it first. |
| `/v1/global-metrics/quotes/latest` | `derivatives_volume_24h`, if present | ✅ | ❓ | **Inspect this payload before anything else.** If that field is in there, it is the one positioning number still reachable. |

## Voice axis — paths confirmed, plan access still open

Open question 3 is answered on the first half: fear and greed **is** an API endpoint, not only a
chart on the site.

| Endpoint | Field needed | Exists | Plan access | Notes |
|---|---|---|---|---|
| `/v3/fear-and-greed/latest` | current index 0–100 | ✅ | ❓ | |
| `/v3/fear-and-greed/historical` | index history | ✅ | ❓ | The one input that may have real history behind it — would backfill Voice before recording began. |
| `/v1/community/trending/topic` | trending topics and rank | ✅ | ❓ | |
| `/v1/community/trending/token` | trending tokens and rank | ✅ | ❓ | |
| `/v1/cryptocurrency/trending/most-visited` | page views per asset | ✅ | ❓ | Attention that has not yet become a trade. |
| `/v1/cryptocurrency/trending/latest` | trending search list | ✅ | ❓ | |
| `/v1/cryptocurrency/trending/gainers-losers` | largest movers | ✅ | ❓ | Price-derived; recorded, probably not an input. |
| `/v1/content/latest` | community post volume | ✅ | ❓ | |
| `/v1/content/posts/top` | per-asset post engagement | ✅ | ❓ | Needs an `id`, so one call per asset. |

## Universe and support

| Endpoint | Field needed | Exists | Plan access | Notes |
|---|---|---|---|---|
| `/v1/key/info` | credits used and remaining | ✅ | ❓ | Costs no credits. |
| `/v1/cryptocurrency/listings/latest` | top 100: id, symbol, rank, volume | ✅ | ❓ | One call covers the universe. |
| `/v1/global-metrics/quotes/latest` | total cap, total volume, dominance | ✅ | ❓ | |
| `/v1/cryptocurrency/categories`, `/v1/cryptocurrency/airdrops` | — | ✅ | ❓ | Exist; no use identified yet. |
| `/v4/dex/listings/quotes`, `/v4/dex/networks/list`, `/v4/dex/spot-pairs/latest` | on-chain volume | ✅ | ❓ | Unexplored. On-chain flow would be a genuine Money input if the plan reaches it. |

---

## Host checks — ❓ not yet run on the host

`bin/preflight.php` fills this in. It must be run **on the cPanel host over SSH**, not on a laptop:
the point is partly to prove the host itself can make these calls (open question 5).

| Check | Result |
|---|---|
| Outbound HTTPS from PHP CLI to `pro-api.coinmarketcap.com` | ❓ |
| PHP CLI binary path and version | ❓ |
| cPanel cron minimum interval | ❓ |
| MySQL version and INSERT grant | ❓ |
| Writable log dir outside webroot | ❓ |
| Plan tier, credit limit, rate limit (from `/v1/key/info`) | ❓ |

The only environment checked so far is a local `php:8.3-cli` container, which proves the code runs
and reaches the API from somewhere — it says nothing about the host.

## Batching — ❓ open

Settled by reading a real payload, so it needs the key.

| Endpoint | Accepts id list | Max ids/call |
|---|---|---|
| `/v2/cryptocurrency/quotes/latest` | expected yes | ❓ — poller assumes 100 |
| `/v2/cryptocurrency/market-pairs/latest` | expected no, one id | ❓ |
| `/v1/content/posts/top` | no, one id | 1 |
