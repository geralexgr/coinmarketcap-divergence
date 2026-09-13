# Method

How every number in this product is produced.

The authoritative version of this is **`scoring/inputs.php`** — the method is declared as data, the
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

**Read this before trusting the axis.** It was designed around funding rates, open interest and
liquidations. None of those exist on the CoinMarketCap API at any version — 38 paths probed, all
absent, and not as a plan restriction ([D10](decisions.md)). What follows measures money *moving*
and money *at rest*, not money *committed and leveraged*. Turnover cannot distinguish a large spot
rotation from a leveraged build-up, because nothing in the available data carries leverage.

| Input | Endpoint | Range | Weight | Status |
|---|---|---|---|---|
| Turnover — `total_volume_24h / total_market_cap` | `global_metrics` | 0.005 → 0.05 | 0.35 | **in use** |
| Derivative share — `derivatives_volume_24h / total_volume_24h` | `global_metrics` | 2 → 12 × | 0.30 | **in use** |
| Stablecoin share of volume, **inverted** | `global_metrics` | 0.5 → 1.2 | 0.20 | **in use** |
| Exchange reserve movement, absolute 24h change | `exchange_assets` | 0 → 0.04 | 0.15 | **in use** |
| Exchange concentration (HHI) | `exchange_listings` | 0.05 → 0.35 | 0.00 | 403 on this plan |

**Derivative share is the one positioning-shaped number reachable.** `global-metrics` does carry
derivative volume — measured at 7.4× spot volume on 13 September 2026 — so how much of the day's
activity happened in contracts rather than in the asset is available. It is still a volume figure:
it says how much was traded, never how much is still held.

**Stablecoin share is inverted** because a high stablecoin share of volume is money standing still —
value changing hands without risk being taken.

**Reserve movement is absolute.** The method makes no claim about which direction money leaving an
exchange points; that reading is an interpretation, and interpretation is what this product does not
do. Magnitude of repositioning only.

### Per asset

| Axis | Input | Range | Weight | Status |
|---|---|---|---|---|
| Voice | Trending rank, **inverted** (rank 1 is loudest) | 1 → 100 | 0.60 | 403 on this plan |
| Voice | Size of 24h move, absolute | 0.5 → 15 % | 0.40 | **in use** |
| Money | Turnover — `volume_24h / market_cap` | 0.005 → 0.30 | 0.60 | **in use** |
| Money | Volume change, 24h, signed | −40 → 80 % | 0.40 | **in use** |

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
