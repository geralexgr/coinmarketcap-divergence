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

## Open interest is BTC only

The derivatives endpoint takes **one symbol per call** — a comma-separated list is rejected — so
covering a hundred assets would cost a hundred credits per sample against a 15,000 credit month.
BTC alone stands for market-wide leverage.

That is the standard benchmark and it is what the funding-rate literature uses, but it is a real
limit: a leverage build concentrated in altcoins would show up here late and muted. Liquidations do
not have this problem — that endpoint returns 100 assets for a single credit, so the liquidation
inputs are genuinely market-wide.

**This section used to say there were no derivatives endpoints at all.** That was wrong for one day:
the original probe swept `/v1/` to `/v4/` and the family lives under `/v5/`. The correction, and how
the mistake happened, is [D20](decisions.md). The prober now sweeps past the versions in use so the
same class of miss cannot recur.

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

## The host enforces a 15-minute cron floor

The deployment host runs a `cron-frequency-monitor` that rewrites any schedule it considers too
frequent. Submitted `*/5`, it became `*/15`, and then `1-59/15` with a per-job offset so the two
pollers do not start in the same minute:

```
# [cron-frequency-monitor] ... "*/5 ..." -> "*/15 ..." (fires 12x/hour, tightest gap 5 min
#                                          (minimum allowed is 15 min))
```

**This costs the product nothing, because per-endpoint cadence absorbs it.** The poller is a cheap
tick that decides what is due; with the tick at 15 minutes every endpoint still gets exactly the
interval it declares — 15 minutes for the leverage inputs, 30 for the universe, 120 for reserves,
180 for sentiment. 404 credits a day, unchanged.

It would have cost a great deal under the original design, where one interval applied to a whole
scope and the cron schedule *was* the sampling rate.

**One consequence worth knowing about.** The floor is equal to the shortest endpoint interval, so a
tick and the endpoint it should fetch come due at the same moment — and the measured age is always
fractionally under 15 minutes, because `fetched_at` is recorded seconds after a run begins. Without
slack in the due-check, every one of those would skip and the real cadence would silently halve.
`POLL_SLACK_MINUTES` in `app/poller/run.php` is what stops that, and it is load-bearing rather than
cosmetic.

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
| What is the host's minimum cron interval? | **15 minutes**, enforced by rewriting the crontab | the host did it to us; see below |
| Can PHP CLI on the host reach the API? | Yes — HTTP 200 from the cPanel host | `app/bin/preflight.php` over SSH |
| Which PHP binary does cron need? | `/opt/alt/php83/usr/bin/php`, not the web server's | preflight reports the resolved path |

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
