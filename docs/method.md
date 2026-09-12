# Method

This document is the source for the public method page in the app. It is written before the code,
and the code follows it. If they disagree, one of them is a bug.

Nothing here predicts anything. Each score describes a condition measured at a recorded moment.

## The two scores

### Voice — what the market is saying

| Input | Source | Direction | Draft weight |
|---|---|---|---|
| Fear and greed index | `/v3/fear-and-greed/latest` | higher = louder | 0.40 |
| Community and search attention — trending rank churn, most-visited | `/v1/community/trending/*`, `/v1/cryptocurrency/trending/most-visited` | higher = louder | 0.35 |
| Community post volume | `/v1/content/latest` | higher = louder | 0.25 |

All three paths are confirmed to exist. Whether the Startup plan may call them is open question 1.

### Money — what the market has committed

**Read this first.** This axis was designed around funding rates, open interest and liquidations.
None of those exist on the CoinMarketCap API — 38 candidate paths probed on 12 September 2026, all
absent, and not as a plan restriction (see `endpoint-access.md` and decision D10). What follows is
the substitute, and it is weaker. That sentence appears on the public method page too.

| Input | Source | Direction | Draft weight |
|---|---|---|---|
| Turnover — `volume_24h / market_cap` | `/v2/cryptocurrency/quotes/latest` | higher = more money moving per unit of size | 0.50 |
| Exchange concentration — HHI of 24h volume across venues | `/v1/exchange/listings/latest` | higher = flow concentrated in fewer venues | 0.25 |
| Exchange reserve movement — change in held balances | `/v1/exchange/assets` | larger movement = more repositioning | 0.25 |

**What this axis can and cannot see.** Turnover measures money *moving*. It cannot distinguish a
large spot rotation from a leveraged build-up, because nothing in the available data carries
leverage. The original axis would have measured money *committed and at risk*; this one measures
money *changing hands*. Those are different things and the app must not imply otherwise.

**Two inputs that would strengthen it**, both payload inspections rather than new endpoints, and
both unresolved until a real key exists:

- `derivatives_volume_24h` on `/v1/global-metrics/quotes/latest`, if that field is present — the
  one positioning number that might still be reachable.
- open interest on `/v2/cryptocurrency/market-pairs/latest?category=derivatives`, if it carries it.

If either lands, it takes weight from turnover and `method_version` bumps rather than the history
being rewritten.

### Divergence

```
divergence = money − voice
```

Signed, range −100 to +100, displayed as a magnitude with a direction label:

- **positive** — money committed is running ahead of narrative
- **negative** — narrative is running ahead of money committed

The displayed "38 of 100" figure is `abs(divergence)`, with the direction stated in the sentence
beside it.

## Quadrants

Thresholds at the midpoint of each axis, drawn from the same normalisation basis as the scores.

| | Money < 50 | Money ≥ 50 |
|---|---|---|
| **Voice ≥ 50** | Chatter without conviction | Loud and leveraged |
| **Voice < 50** | Apathy | Quiet, but leveraged |

A quadrant is a label for where the point currently sits. It is not a rating and implies no action.

## Normalisation

Each raw input is mapped to 0–100 before weighting. Two bases, and which one produced a given row
is stored on the row.

### Basis A — fixed reference ranges (days 1–7)

Percentile ranking against trailing history is meaningless when there is no history: the first
days' scores would jump around and the plot would look broken.

So for the first seven days, each input is min-max scaled against a hand-set reference range:

| Input | Floor (0) | Ceiling (100) | Where the range came from |
|---|---|---|---|
| Fear and greed | 0 | 100 | already a 0–100 index |
| Trending rank churn | 0 | TBC | TBC from the first days of data |
| Community post volume | TBC | TBC | TBC from the first days of data |
| Turnover (volume ÷ market cap) | TBC | TBC | TBC — market-wide turnover sits in single-digit percent, but the range is set from measurement, not instinct |
| Exchange concentration (HHI) | TBC | TBC | TBC |
| Reserve movement, 24h | TBC | TBC | TBC |

Every "TBC" here gets filled in from the first days of recorded data, and the value used is written
on the public method page.

### Basis B — percentile rank (day 8 onward)

Once enough history is banked, each input is scored as its percentile rank within a trailing
window (window length TBC — likely 30 days, which means this basis only becomes fully meaningful
after that much recording).

### The switchover

The switchover date is **published on the method page**, along with the reason. Scores either side
of it are not strictly comparable, and the app says so rather than hiding it. The `basis` column on
every score row records which method produced it.

## Provenance

Every number shown in the app carries:
- the logical endpoint it came from,
- the minute it was sampled (real fetch time, UTC),
- a link to the raw sample id.

This is what makes the "does it work" criterion verifiable: a judge picks a number, sees where it
came from, and checks it against CoinMarketCap.

## Gaps

If the poller missed a window, the gap is shown as a gap. Charts break the line; they do not
interpolate. The recording-health figures (samples, longest gap, failure rate) are shown in the app
header — "recording since 9 Sep, 4,312 samples" in the mockup is that.
