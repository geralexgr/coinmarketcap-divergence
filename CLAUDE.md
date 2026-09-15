# Divergence

Brief for anyone — human or agent — working in this repo. This file is the *why*; the docs table
below is the *what*.

| Question | File |
|---|---|
| What is this product | [README.md](README.md) |
| Why it was built this way | [docs/decisions.md](docs/decisions.md) |
| What it cannot see | [docs/limits.md](docs/limits.md) |
| Components and cadence | [docs/architecture.md](docs/architecture.md) |
| Tables | [docs/data-model.md](docs/data-model.md) |
| How a score is calculated | [docs/method.md](docs/method.md) |
| Which endpoints actually work | [docs/endpoint-access.md](docs/endpoint-access.md) |
| What the UI shows | [docs/ui-spec.md](docs/ui-spec.md) |
| Agent tool surface | [docs/mcp-tools.md](docs/mcp-tools.md) |
| How to deploy | [docs/deploy.md](docs/deploy.md) |
| Where the API got in the way | [docs/api-friction.md](docs/api-friction.md) |

A tool that measures the gap between what the crypto market is **saying** and what it has
**committed money to**. CoinMarketCap publishes both halves on separate pages and never puts them on
the same axis. That gap is the product.

---

## The one hard rule: no predictions, no advice

This tool gives **no financial advice and no recommendations**. Not "consider reducing exposure",
not "this looks overheated", not a buy/sell/hold signal, not a risk rating that implies action.

Every output is a **measurement of a condition that exists right now, or existed at a recorded past
moment**. If a sentence points at the future, it does not belong in this product.

This is a design constraint, not a disclaimer:

1. A measurement can be verified against CoinMarketCap in thirty seconds. A prediction cannot be
   verified at all.
2. Recommendations drag in liability and put the entry in the same category as most of the field.

There is a test that greps the readout copy for future-tense words. If a feature request would need
the tool to express an opinion about what happens next, push back and propose the measured version.

---

## The core concept

Two scores, both 0–100, both sampled continuously.

**Voice** — what the crowd is saying. Designed around social volume, fear and greed, and trending
topics. On the Basic plan **only fear and greed is callable**; trending, community and content all
answer 403. So the axis is one input at daily resolution. Do not describe Voice as a ten-minute
measurement while that is true — the app says "steps daily" on the chart and so should you.

That one input now carries 500 days of its own history, backfilled once from
`/v3/fear-and-greed/historical` (D22), so Voice is scored as a percentile rank against its own
distribution rather than as the raw index restated. **The Voice axis is the only thing in this
product that can be backfilled at all.**

**Money** — how much money is actually committed and at risk. Open interest, funding rate,
liquidations, open interest against volume, and turnover.

D10 recorded that the derivatives endpoints did not exist, after probing 38 paths. **That was
wrong** — they live under `/v5/`, and the original sweep covered `/v1/`–`/v4/` only. D20 is the
correction and `METHOD_VERSION` 2 is the axis rebuilt on them. The turnover-and-substitutes version
of the axis is kept at weight zero rather than deleted, so what the axis used to be stays legible.
If you read a claim in this repo that something is absent from the API, check which versions were
swept before repeating it.

**Divergence** — `money − voice`, signed.

| | Low Money | High Money |
|---|---|---|
| **High Voice** | Chatter without conviction | Loud and leveraged |
| **Low Voice** | Apathy | Quiet, but leveraged |

The same two scores are computed **per asset**, which turns the tool into a screener with axes that
exist nowhere else.

---

## Why the recorder is the whole product

CoinMarketCap's API is almost entirely **snapshot data**. Price history can be fetched
retroactively. **Positioning and sentiment history cannot** — there is no historical endpoint for
turnover, exchange flow or social volume at usable granularity, and none at all for some of it.

So the trail on the plot, every "up 26 this week" figure, and every quadrant transition exist only
because something was recording at the time. Whoever starts recording on day one owns a dataset
nobody starting later can reconstruct.

**Consequence for any change you make here: nothing may put the poller at risk.** The extractor and
the scorer are separate cron jobs on offset minutes precisely so a slow or failing derivation can
never delay a fetch. Keep it that way.

---

## Load-bearing design decisions

Full log with reasoning in [docs/decisions.md](docs/decisions.md). The ones that constrain new work:

**Store the raw response, not just the extracted score (D3).** Every sample writes the verbatim
payload alongside the parsed fields. The parsing was wrong once already and the weights are
provisional; both are correctable without losing a sample. `bin/extract.php --rebuild` and
`bin/score.php --rebuild` re-derive everything from what is already on disk.

**Log every fetch attempt, including failures (D4).** `fetch_log` records endpoint, HTTP status,
credits and error text. Operational visibility, honest gap handling, and the evidence behind
`docs/api-friction.md`.

**Record the actual sample time, not the scheduled one (D5).** Cron drifts. Charts plot what
happened.

**Extraction is pure and single-payload (D13).** No clock, no network, no cross-sample logic.
Anything needing two samples belongs to the scoring layer. This is what makes a rebuild
order-independent.

**The method is declared as data (`app/scoring/inputs.php`).** The scorer runs from it, the public
method page renders from it, `docs/method.md` is written from it. Add an input there, not in three
places.

**Missing inputs are dropped, not zeroed.** Surviving weights renormalise and the score records how
many inputs it saw. A zero reads as a quiet market; absence is a measurement of nothing.

**Publish the method.** The page states exactly how each input is normalised and weighted, which
endpoints are forbidden, and when the normalisation basis changed. This is what separates the tool
from a black box.

**The normalisation basis is per axis (D22).** Voice reaches percentile rank immediately because its
one input was backfilled 500 days; Money cannot until the recorder has run a week, because none of
its inputs can be backfilled at all. `scores.basis` reports the weaker of the two so a
half-established row is never advertised as fully established.

**Rank movement as well as size (D23).** A gap-ranked table returns much the same assets every day,
because a permanent property of an asset is not news about it. `asset_divergence_movers()` ranks the
change between two recorded cross-sections instead. Both ends are measurements, both are on the row,
and every asset is compared over the same interval — an asset missing from the baseline yields no
row rather than a change measured against whatever was nearest.

**The comparison set is part of the measurement (D24).** A per-asset score is a rank against the
universe at that instant, so two cross-sections taken over universes of different size cannot be
compared — raising `asset_universe` from 100 to 200 produced a live movers table reporting a
31-point median move that was entirely the boundary. `asset_movers_window()` refuses the comparison
and prints the reason. If you add anything that differences two per-asset scores, check the window
first.

**Anything reading `raw_samples` reads one payload at a time (D19).** A batch of LONGTEXT bodies
exceeds the memory limit on a shared host. This was found the hard way.

---

## Stack

Deliberately boring and already available:

- **PHP** on cPanel shared hosting, **MySQL**
- **Real cron** via cPanel through PHP CLI — not a `wget` to a URL, which inherits HTTP timeouts and
  creates a public endpoint needing protection
- Vanilla JS, hand-drawn SVG for the chart
- **No composer, no framework, no build step, no third-party runtime dependency.** Adding one needs
  to earn its place against the fact that a shared host has to satisfy it.

Not Vercel: the Hobby plan rejects any cron resolving to more than once per day, and fails at deploy
time. A daily sample makes the product pointless.

---

## Constraints

- **Rate limit 50 requests/minute**, measured.
- **15,000 credits/month** on the Basic plan. This is the binding constraint on the whole product.
  Credits are read from each response and logged, never estimated. Current burn is **356/day —
  10,680/month** (D23).
- **Cadence is per endpoint**, declared in `app/lib/endpoints.php`; the cron entries are a cheap
  15-minute tick that decides what is due. Do not raise a cadence without redoing the arithmetic;
  `app/bin/health.php` prints remaining headroom in days.
- **The universe is the top 200 by market cap**, in one `listings_latest` call. CMC prices that
  endpoint per 200 data points, so 200 costs the same credit as 100 (D23). Past 200 the constraint
  is `asset_metric` row volume on a shared host, not credits.
- **Derivation is free and should not lag the recorder.** `extract.php` and `score.php` make no
  network call and spend no credit, so they run on the same 15-minute tick as the poller, offset
  behind it. They are separate cron entries so a slow derivation can never delay a fetch — that is
  the rule, not the interval.
- Keep each cron run short and stateless. Shared hosts kill long-running processes.

---

## Repo hygiene

- The API key lives in a config file **outside the webroot** and never enters git. `config.example.php`
  shows the shape. A key in git history is a direct hit on the code quality score.
- `public/` is the only web-served directory, and it is read-only — there is no write path anywhere
  in the web app. The only writer in the system is cron.
- Tests run with no key, no database and no network: `php tests/run.php`.
- Before verifying an endpoint's behaviour, remember CMC answers **unknown paths with HTTP 200** and
  `error_code: 500`. Never judge a response by its HTTP status alone — use `cmc_outcome()`.
