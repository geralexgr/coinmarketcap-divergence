# Divergence

> **If you are picking this repo up, read [TOMORROW.md](TOMORROW.md) first** — it is the ordered
> task list and it assumes you know nothing. This file is the *why*; that file is the *what next*.
>
> | Question | File |
> |---|---|
> | What is this product | [README.md](README.md) |
> | What do I do next | [TOMORROW.md](TOMORROW.md) |
> | Phases to submission | [ROADMAP.md](ROADMAP.md) |
> | Why it was built this way | [docs/decisions.md](docs/decisions.md) |
> | What is still unknown | [docs/open-questions.md](docs/open-questions.md) |
> | Components and cadence | [docs/architecture.md](docs/architecture.md) |
> | Tables | [docs/data-model.md](docs/data-model.md) |
> | How a score is calculated | [docs/method.md](docs/method.md) |
> | Which endpoints actually work | [docs/endpoint-access.md](docs/endpoint-access.md) |
> | What the UI shows | [docs/ui-spec.md](docs/ui-spec.md) |
> | How to deploy | [docs/deploy.md](docs/deploy.md) |
>
> **Status: skeleton.** No code exists yet. Nothing is recording.

A tool that measures the gap between what the crypto market is **saying** and what it has
**committed money to**. Built for the CoinMarketCap API Hackathon (submissions close early
October 2026).

CoinMarketCap publishes both halves of this on separate pages and never puts them on the same
axis. That gap is the product.

---

## Read this first: what this is not

This tool gives **no financial advice and no recommendations**. Ever. Not "consider reducing
exposure", not "this looks overheated", not a buy/sell/hold signal, not a risk rating that
implies action.

Every output is a **measurement of a condition that exists right now, or existed at a recorded
past moment**. If a sentence points at the future, it does not belong in this product.

This is a deliberate design constraint, for two reasons:

1. Judging weights "does it work" at 30%. A measurement can be verified by a judge against
   CoinMarketCap in thirty seconds. A prediction cannot be verified at all.
2. Recommendations drag in disclaimers, liability, and the exact category where most other
   hackathon entries will be sitting.

If a feature request would require the tool to express an opinion about what happens next,
push back and propose the measured version instead.

---

## The core concept

Two scores, both normalised 0–100, both sampled continuously.

**Voice** — what the crowd is saying.
Sources: social/community volume, fear and greed, trending topics.

**Money** — what the crowd has actually committed.
Sources: funding rates, open interest, liquidations.

**Divergence** — the signed gap between them.

Plotted as a quadrant with Voice on one axis and Money on the other. The market sits at a point,
and because we record continuously, it sits at the end of a trail showing the path it took.

Four readings:

| | Low Money | High Money |
|---|---|---|
| **High Voice** | Chatter without conviction | Loud and leveraged |
| **Low Voice** | Apathy | Quiet, but leveraged |

The same two scores are also computed **per asset**, which turns the tool into a screener with
axes that exist nowhere else: sort the market by how much an asset is talked about versus how
much money sits behind it.

---

## Why the recorder is the whole product

CoinMarketCap's API is almost entirely **snapshot data**. There is no historical endpoint for
social volume, funding, or open interest at usable granularity, and none at all for some of it.

Price history can be fetched retroactively. **Positioning and sentiment history cannot.**

This means:

- Whoever starts recording on day one owns a dataset nobody starting later can reconstruct.
- The trail on the plot, every "up 26 this week" figure, and the lead/lag analysis all depend
  entirely on having recorded.
- **The poller ships before the frontend.** It is not a later phase. A day of delay is a day of
  history that cannot be recovered.

If you are picking up this repo and the poller is not yet running, build the crudest possible
version first: hit the endpoints, dump raw JSON with a timestamp into one table, deploy the cron.
No schema design, no scoring, no normalisation. Get it recording, then build everything else.

---

## Stack

Deliberately boring and already available:

- **PHP** on cPanel shared hosting
- **MySQL**
- **Real cron** via cPanel, invoked through PHP CLI (not a wget to a URL — web-triggered cron
  inherits HTTP timeouts and creates a public endpoint that has to be protected)
- Vanilla JS + a charting library for the frontend
- No framework unless it earns its place

Why not Vercel: the Hobby plan rejects any cron schedule that resolves to more than once per day,
and it fails at deploy time. A daily sample makes the product pointless.

---

## Architecture

```
cPanel cron (5 min)
      │
      ▼
  poller.php  ──────►  CoinMarketCap API
      │                (voice endpoints + money endpoints)
      ▼
   MySQL
   ├── raw response JSON + timestamp    ← source of truth
   ├── extracted fields                 ← derived, recomputable
   └── fetch log                        ← every attempt, success or failure
      │
      ▼
  scoring layer  ──►  Voice / Money / Divergence, market-wide and per asset
      │
      ▼
  ├── web app (read-only)
  ├── MCP server (thin wrapper over the same queries)
  └── alerts
```

---

## Load-bearing design decisions

### Store the raw response, not just the extracted score

Every sample writes the raw JSON payload alongside the parsed fields. If the divergence weighting
changes on day eighteen, the entire history is recomputed from what is already stored. Without
this, the formula guessed at on day one is locked in permanently.

### Log every fetch attempt, including failures

A `fetch_log` table recording endpoint, HTTP status, credits consumed, and error text. This gives
honest gap handling when the host or the API hiccups, and it writes most of the "where the API got
in the way" note the submission requires.

### Record the actual sample time, not the scheduled one

Cron drifts. Charts plot real timestamps.

### The cold-start scoring problem

If each input is normalised by percentile rank against trailing history, scores are meaningless for
the first several days because there is no distribution to rank against — the plot will jump around
and look broken.

Approach: use **fixed reference ranges for the first seven days**, then switch to percentile
ranking once enough history is banked. Document the switchover date and the reason on the method
page. Do not hide it.

### Publish the method

A page that states exactly how each input is normalised and weighted. This is what separates the
tool from a black box, and it feeds the code quality and documentation criterion directly.

---

## Unresolved — verify before building against them

These block real design decisions. Check them by making actual calls with the real key, not by
reading the docs.

1. **Which endpoints respond on Startup tier.** The plan gives 23 latest-data endpoints against
   Standard's 35. Some of what this design assumes may 403. Verify endpoint by endpoint and write
   the results into `docs/endpoint-access.md`.

2. **Derivatives access.** Funding rates, open interest, and liquidations are the best inputs to
   the Money axis. If they are not on the tier, the fallback is volume concentration and exchange
   reserve movement — weaker, but survivable. **This is the highest-priority check.**

3. **Fear and greed via API.** It is a chart page on the site; confirm whether it is an endpoint at
   all. If not, social/community volume alone can carry the Voice axis.

4. **cPanel cron minimum interval.** Most shared hosts allow every minute; some enforce a 5 or 15
   minute floor.

5. **Outbound HTTPS from PHP CLI** to `pro-api.coinmarketcap.com` — confirm from the server, not
   from a laptop.

---

## Constraints

- **Rate limit: 30 requests/minute.** Per-asset polling across a large universe needs batching.
  Cap the asset universe at the top ~100 and widen only if the budget allows.
- **300,000 call credits/month.** Generous, but count them: log credits per call from the response
  so usage is observable rather than guessed.
- **Sampling cadence:** 5 minutes market-wide, 15 minutes per asset. Roughly 6,000 market rows and
  600,000 asset rows over three weeks — around 100 MB with indexes.
- Keep each cron run short and stateless. Shared hosts kill long-running processes.

---

## Repo hygiene

The submission requires a public repo, and judges read it.

- API key lives in a config file **outside the webroot**. Commit a `config.example.php` showing the
  shape. A key in git history is a direct hit on the code quality score.
- README must state which CMC endpoints are used and why, and include a real request/response
  example.
- Include the note on where the API got in the way — the fetch log supplies the evidence.

---

## Judging criteria, for prioritisation

| Weight | Criterion | What it means here |
|---|---|---|
| 30% | Does it work | A judge can open the URL and check numbers against CMC |
| 25% | Usefulness | Answers a question CMC's own site cannot |
| 20% | Interesting API use | Unusual endpoint mix, derived metrics rather than re-display |
| 15% | Code quality and docs | Clean repo, method page, no leaked key |
| 10% | Presentation | The quadrant trail is the demo moment |

When trading off, protect "does it work" first. A smaller product that demonstrably runs beats a
larger one with a broken panel.
