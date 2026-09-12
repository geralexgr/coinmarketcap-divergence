# Method

This document is the source for the public method page in the app. It is written before the code,
and the code follows it. If they disagree, one of them is a bug.

Nothing here predicts anything. Each score describes a condition measured at a recorded moment.

## The two scores

### Voice — what the market is saying

| Input | Source | Direction | Draft weight |
|---|---|---|---|
| Fear and greed index | CMC (endpoint TBC) | higher = louder | 0.40 |
| Social / community volume, market-wide | CMC community endpoints | higher = louder | 0.60 |

### Money — what the market has committed

| Input | Source | Direction | Draft weight |
|---|---|---|---|
| Weighted funding rate | CMC derivatives | further from zero = more committed | 0.40 |
| Open interest, level and 7d change | CMC derivatives | higher = more committed | 0.40 |
| Liquidations, 24h | CMC derivatives | higher = more forced exposure | 0.20 |

Weights are drafts. They will change once real distributions are visible, and when they do the
`method_version` column bumps rather than the history being rewritten.

**If derivatives are unavailable on our tier**, the Money axis falls back to volume concentration
and exchange reserve movement. Weaker, and the method page must say so plainly. See
`open-questions.md` item 2.

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
| Social volume (7d z) | −2σ | +2σ | TBC from first days of data |
| Funding rate | −0.05% | +0.05% | TBC — typical perp funding band |
| Open interest 7d change | −20% | +20% | TBC |
| Liquidations 24h | $0 | $500M | TBC |

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
