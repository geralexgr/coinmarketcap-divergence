# Method

How every number in this product is produced.

The authoritative version of this is **`app/scoring/inputs.php`** — the method is declared as data, the
scorer runs from that declaration, and the public method page renders from it. This document is the
prose around it. If the two disagree, the code is right and this file is a bug.

Nothing here predicts anything. Each score describes a condition measured at a recorded moment.

---

## The two scores

Each raw input is scaled to 0–100, then weighted. **An input with no data is dropped and the
surviving weights are renormalised over what remains.** A missing input is never counted as zero:
zero is a measurement of a quiet market, absence is a measurement of nothing, and rendering them
identically would be a lie about the market rather than about the data. Every score row records how
many of its declared inputs it actually saw.

### Voice — what the market is saying

| Input | Endpoint | Range | Weight | Status |
|---|---|---|---|---|
| Fear and greed index | `/v3/fear-and-greed/latest` | 0 → 100 | 0.40 | **in use** |
| Trending rank churn | `/v1/community/trending/token` | 0 → 40 | 0.35 | 403 on this plan |
| Community post volume | `/v1/content/latest` | 0 → 100 | 0.25 | 403 on this plan |

**In practice this axis is one input.** Six of the seven Voice endpoints are forbidden on the Basic
plan, leaving the fear and greed index — which CoinMarketCap updates **once a day**. So Voice steps
daily while Money moves every ten minutes, and the horizontal stretches in the trail are that, not a
market that went quiet. The app says so on the chart itself.

The forbidden inputs stay declared at their intended weights so the gap between the designed method
and the running one is visible, and so a plan change needs no code — see [D16](decisions.md).

### Money — what the market has committed

This axis measures money **committed and at risk**: positions currently held, what they cost to
hold, and what got closed by force.

| Input | Endpoint | Range | Weight | Status |
|---|---|---|---|---|
| Open interest | `derivatives_pairs` | $40bn → $120bn | 0.30 | **in use** |
| Funding rate, OI-weighted | `derivatives_pairs` | −0.0003 → 0.0008 | 0.20 | **in use** |
| Liquidations, 24h | `liquidations` | $50m → $1.5bn | 0.20 | **in use** |
| Open interest ÷ derivative volume | `derivatives_pairs` | 0.3 → 1.5 | 0.15 | **in use** |
| Turnover — `total_volume_24h / total_market_cap` | `global_metrics` | 0.005 → 0.05 | 0.15 | **in use** |
| Derivative share of activity | `global_metrics` | 2 → 12 × | 0.00 | superseded |
| Stablecoin share of volume, inverted | `global_metrics` | 0.5 → 1.2 | 0.00 | superseded |
| Exchange reserve movement | `exchange_assets` | 0 → 0.04 | 0.00 | superseded |
| Derivative venue concentration (HHI) | `derivatives_exchanges` | 0.02 → 0.30 | 0.00 | recorded, range not yet set |
| Spot venue concentration (HHI) | `exchange_listings` | 0.05 → 0.35 | 0.00 | 403 on this plan |

**This axis was rebuilt on 13 September 2026, and the rebuild is worth knowing about.** For its
first day it ran on turnover and three substitutes, because [D10](decisions.md) had recorded — after
probing 38 paths — that the CoinMarketCap API carries no funding rate, open interest or liquidation
data. That was wrong: the probe covered `/v1/` to `/v4/` and the derivatives family lives under
`/v5/`. See [D20](decisions.md). The substitutes are kept in the table at weight zero rather than
deleted, so a version 1 score and a version 2 score can be compared honestly.

**Open interest is a level, not a flow.** Dollars currently committed to BTC derivative positions,
summed across every venue CoinMarketCap tracks — $76.6bn when measured. It is the most direct answer
in the API to "how much money is at risk here", and it is the reason this axis no longer needs a
disclaimer about measuring the wrong thing.

**Funding is weighted by open interest, not averaged flat.** A funding rate is a rate, so summing it
is meaningless and a plain mean lets a dead venue with one contract count as much as Binance.
Weighting by each pair's open interest is what "what is the market paying to hold this position"
actually means. Only perpetuals contribute: a dated future has a basis and no funding rate, and
averaging its structural absence in as a zero would drag the figure toward nothing.

**Liquidations are the least ambiguous number in the product.** Positions closed by the exchange
rather than by their owner. Recorded market-wide and split long from short, because which side got
caught out is a fact about what happened, not a forecast of what follows.

**What the axis still cannot see.** Open interest is BTC only — the derivatives endpoint takes one
symbol per call and a hundred assets would be a hundred credits against a 15,000 credit month. BTC
is the standard benchmark for market-wide leverage, but an altcoin-led leverage build would show up
here late and muted.

### Per asset

| Axis | Input | Weight | Status |
|---|---|---|---|
| Voice | Trending rank, **inverted** (rank 1 is loudest) | 0.60 | 403 on this plan |
| Voice | Size of 24h move, absolute | 0.40 | **in use** |
| Money | Turnover — `volume_24h / market_cap` | 0.60 | **in use** |
| Money | Volume change, 24h, signed | 0.40 | **in use** |

**No ranges, deliberately.** Per-asset inputs are never min-max scaled — each is ranked against the
same input across the rest of the universe at that instant (basis C below), which reads no floor and
no ceiling. `app/scoring/inputs.php` carries values for them so both scopes share one shape, and the
method page prints "rank vs universe" rather than a number that does not drive the score.

**The per-asset Voice proxy is the weakest number in the product and is labelled as such wherever it
appears.** There is no per-asset attention data on this plan at all. The size of the day's move
stands in for attention on the reasoning that an asset that moved 30% is being looked at whichever
way it moved — but it is derived from price, so it is partly contaminated by the Money axis. That is
a real problem with a screener whose whole point is that the two axes measure different things, and
the honest mitigation is disclosure rather than cleverness. See [D18](decisions.md).

---

## Divergence

```
divergence = money − voice
```

Signed, −100 to +100.

- **positive** — money committed is running ahead of narrative
- **negative** — narrative is running ahead of money committed

The headline figure is `abs(divergence)`, with the direction stated in the sentence beside it. Below
5 the app says the two are reading close to level rather than naming a direction the number does not
support.

## Quadrants

Midlines at 50 on both axes, on the same basis as the scores themselves. The midline itself belongs
to the upper quadrant, consistently, so the label does not flicker at the boundary.

| | Money &lt; 50 | Money ≥ 50 |
|---|---|---|
| **Voice ≥ 50** | Chatter without conviction | Loud and leveraged |
| **Voice &lt; 50** | Apathy | Quiet, but leveraged |

A quadrant is a label for where the point currently sits. It is not a rating and implies no action.

---

## Normalisation

Which basis produced a row is stored **on the row** and never inferred. Scores from different bases
are not strictly comparable, and the app says so rather than mixing them on one chart.

### Basis A — fixed reference ranges (market-wide, days 1–7)

Percentile rank against trailing history is meaningless when there is no history: the first day's
scores would be ranked against a handful of samples and the plot would jump between 0 and 100 for no
reason.

So each input is min-max scaled against the reference range in the tables above, **clamped at both
ends** — the quadrant is defined on 0–100 and a point at 140 has nowhere to sit.

The ranges come from the live payloads captured on 13 September 2026 and from the first days of
recording. They are provisional and should be re-derived from a fortnight of real distributions,
with `METHOD_VERSION` bumped when they are.

### Basis B — percentile rank (market-wide, day 8 onward)

Each input scores as its percentile rank within a trailing 30-day window — or all of recorded
history, whichever is shorter, which during this deployment is the latter.

Ties count half, so a series of identical readings scores 50 rather than 0 or 100. That is what "no
information" should look like; counting ties as "below" would score a flat market at 100 and put it
in the wrong quadrant on every sample.

### The switchover

Measured from the deployment's own first sample, not from a calendar date, so a deployment that
started recording late gets its own fixed week. **The date is published on the method page** with the
reason, and the `basis` column on every row records which method produced it.

### Basis C — cross-section (per asset, from the first sample)

Per-asset inputs are ranked against **the rest of the tracked universe at the same instant** rather
than against that asset's own past: turnover in the 92nd percentile of the top 100 right now.

That is the question a screener is actually asked, and it needs no banked history, so the table
works from day one. It is a different measurement from the market-wide series and the two are never
plotted together. See [D17](decisions.md).

---

## Provenance

Every number shown carries the logical endpoint it came from and the minute it was sampled, UTC,
real fetch time. The `raw_sample_id` behind each figure is stored beside it in the database.

This is what makes the tool verifiable: pick a number, see where it came from, check it against
CoinMarketCap.

## Gaps

If the poller missed a window, the gap is shown as a gap. **Charts break the line; they do not
interpolate.** A gap is detected from the data — any interval more than 2.5× the median — rather than
from the cron schedule, because the schedule is an intention and the samples are what happened.

Recording health (samples, longest gap, failure rate, credits consumed) is live on the method page
and in the app header, from `raw_samples` and `fetch_log` rather than written down anywhere.

## Versioning

Scores are keyed on `method_version`. Changing a weight, a range or the input list bumps it, which
adds a parallel series rather than rewriting the one already recorded — the trail does not silently
become a different measurement halfway along.

Because raw payloads are stored verbatim, `bin/score.php --rebuild` re-derives the entire history
under a new method from data already on disk.
