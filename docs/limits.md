# Limits

What this tool cannot see, and why. Written down because a measurement tool that hides its blind
spots is worth less than one that names them.

Everything here was established by making calls against the live API, not by reading documentation.

---

## The Voice axis is one input, updated once a day

Six of the seven Voice endpoints answer HTTP 403 on the Basic plan: both community trending
endpoints, all three cryptocurrency trending endpoints, and both content endpoints. What survives is
`/v3/fear-and-greed/latest`, which CoinMarketCap updates daily.

**What it means for the product:** Voice steps once a day while Money moves every ten minutes. The
quadrant still separates the four readings correctly, but the trail is mostly horizontal with daily
vertical steps, and a reader who did not know that would misread the flat stretches as a market that
had gone quiet. So the market screen says it, on the chart.

**What would change it:** a plan upgrade, and nothing else. The inputs are already declared in
`app/scoring/inputs.php` at their intended weights and gated on `endpoint_access_results()` in
`app/lib/endpoints.php` — one verification run turns them on. See [D16](decisions.md).

## There are no derivatives endpoints at all

38 candidate paths probed across `/v1/` to `/v4/` — funding rate, open interest, liquidations,
futures, perpetuals, derivatives listings, quotes and exchanges. **Every one absent.** Not 403: the
paths do not resolve, so no plan upgrade produces them. Reproduce with `php app/bin/probe-paths.php`,
which needs no key and costs no credits.

**What it means for the product:** the Money axis measures money *moving* and money *at rest*, not
money *committed and leveraged*. Turnover cannot distinguish a large spot rotation from a leveraged
build-up, because nothing in the available data carries leverage. The axis is weaker than the one
this product was designed around, and the method page says so in those words.

**The partial exception:** `global-metrics` carries `derivatives_volume_24h`, so the share of the
day's activity happening in contracts rather than in the asset is reachable — measured at 7.4× spot
volume on 13 September 2026. It is still a volume figure: it says how much was traded, never how much
is held. See [D10](decisions.md).

## The per-asset Voice axis is a price-derived proxy

There is no per-asset attention data on this plan. `abs_percent_change_24h` — the size of the day's
move, direction discarded — stands in for attention, on the reasoning that an asset that moved 30% is
being looked at whichever way it moved.

**What it means for the product:** the proxy is derived from price, so the per-asset axes are more
correlated than the design intends. This is the weakest number in the product and it is labelled as
such wherever it appears. The alternative was a screener with one axis, which is not a screener. See
[D18](decisions.md).

## Scores from different bases are not comparable

Three normalisation bases, recorded on every row:

- `fixed` — market-wide, first seven days, min-max against hand-set reference ranges
- `percentile` — market-wide thereafter, rank within a trailing window
- `cross_section` — per asset, ranked against the universe at the same instant

The switchover date is published. Market-wide and per-asset scores are never plotted on the same
chart. See [D7](decisions.md) and [D17](decisions.md).

## The reference ranges are provisional

The fixed-basis floors and ceilings come from the live payloads captured on 13 September 2026 and
from the first days of recording. They are the least evidenced numbers in the method and should be
re-derived from a fortnight of real distributions, with `METHOD_VERSION` bumped when they are.

A range that is too narrow clamps a live input at 0 or 100 and flattens that axis; the clamping is
deliberate, but a permanently pinned input is a sign the range is wrong rather than the market
extreme.

## Recording gaps are real and are shown

The trail exists only because something was recording. If the poller missed a window — a host
restart, a failed cron, an API outage — that window is a gap, the chart breaks the line, and no value
is interpolated across it.

Gaps are detected from the data (any interval more than 2.5× the median) rather than from the cron
schedule, because the schedule is an intention and the samples are what happened. Every fetch
attempt, including the failures, is in `fetch_log`.

## The credit budget is the binding constraint

15,000 credits a month on the Basic plan. The cadence — 10 minutes market-wide, 30 per asset — was
chosen to fit it, not because it is ideal. About 624 credits a day.

A known inefficiency: cadence is per *scope*, so the once-a-day fear and greed index is fetched 144
times a day for 143 identical values. Per-endpoint cadence would roughly halve the bill. It is the
first thing to build if credits get tight. See [D15](decisions.md).

## Things confirmed rather than assumed

| Question | Answer | How |
|---|---|---|
| Does fear and greed have an API endpoint? | Yes — `/v3/fear-and-greed/{latest,historical}` | Both resolve; 401 to a bad key, not 404 |
| Are there derivatives endpoints? | No, at any version | 38 paths probed |
| What plan is the key on? | Basic — 15,000 credits, 50 req/min | `/v1/key/info` |
| Does `global-metrics` carry derivative volume? | Yes | Live payload inspection |
| Does `market-pairs/latest` carry open interest? | Unanswerable — 403 on this plan | Verifier |
| Do the per-asset endpoints accept id batches? | Yes, 100 ids in one call | `quotes_asset_count` per sample |

The historical fear-and-greed endpoint returns its list **newest first**. An earlier version of the
extractor took the last element, which is the *oldest* point — sixteen months stale and entirely
plausible-looking on a chart. It is now chosen by comparing timestamps, and there is a test for it.

## The trap worth knowing about

`pro-api.coinmarketcap.com` answers an unknown path with **HTTP 200**, carrying
`status.error_code: 500` and "The system is busy, please try again later!". Reading the HTTP status
alone recorded six non-existent endpoints as working.

Every response in this repo is classified by `cmc_outcome()` in `app/lib/http.php`, which reads the
body's error code as well as the status. `app/bin/probe-paths.php` runs two known-fake control paths on
every invocation, so a change in CMC's routing surfaces as a failed control rather than as silently
wrong results.
