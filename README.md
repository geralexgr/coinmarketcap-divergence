# Divergence

**What the crypto market is *saying*, plotted against what it has actually *committed money to*.**

CoinMarketCap publishes both halves of this — community/social activity on one set of pages,
derivatives positioning on another — and never puts them on the same axis. That gap is the product.

Built for the CoinMarketCap API Hackathon (submissions close early October 2026).

> **Status: skeleton.** Nothing is implemented yet. This repo currently contains the design,
> the open questions, and the plan. Start at [TOMORROW.md](TOMORROW.md).

---

## The screen

![Divergence market view](docs/mockups/ui-market-view.png)

*Mockup, not live data. Source: [`docs/mockups/dashboard-mockup.html`](docs/mockups/dashboard-mockup.html).*

---

## What it measures

Two scores, both normalised 0–100, both sampled continuously.

| Score | Question it answers | Inputs |
|---|---|---|
| **Voice** | How much is the market talking? | social / community volume, fear and greed, trending |
| **Money** | How much has the market actually committed? | funding rates, open interest, liquidations |
| **Divergence** | How far apart are they? | the signed gap between the two |

Plotted as a quadrant — Voice on one axis, Money on the other. The market sits at a point, and
because we record continuously, that point sits at the end of a **trail** showing the path it took
to get there.

| | Low Money | High Money |
|---|---|---|
| **High Voice** | Chatter without conviction | Loud and leveraged |
| **Low Voice** | Apathy | Quiet, but leveraged |

The same two scores are computed **per asset**, which turns the tool into a screener with axes that
exist nowhere else: sort the market by how much an asset is talked about versus how much money sits
behind it.

---

## Read this first: what this is not

This tool gives **no financial advice and no recommendations**. Ever. Not "consider reducing
exposure", not "this looks overheated", not a buy/sell/hold signal, not a risk rating that implies
action.

Every output is a **measurement of a condition that exists right now, or existed at a recorded past
moment**. If a sentence points at the future, it does not belong in this product.

Two reasons this is a hard constraint and not a disclaimer:

1. Judging weights "does it work" at 30%. A measurement can be verified by a judge against
   CoinMarketCap in thirty seconds. A prediction cannot be verified at all.
2. Recommendations drag in liability and put the entry in the same bucket as most of the field.

---

## Why the recorder is the whole product

CoinMarketCap's API is almost entirely **snapshot data**. Price history can be fetched
retroactively. **Positioning and sentiment history cannot** — there is no historical endpoint for
social volume, funding, or open interest at usable granularity, and none at all for some of it.

Which means:

- Whoever starts recording on day one owns a dataset nobody starting later can reconstruct.
- The trail on the plot, every "up 26 this week" figure, and the whole lead/lag analysis depend
  entirely on having recorded.
- **The poller ships before the frontend.** Not a later phase. A day of delay is a day of history
  that cannot be recovered.

If you are picking this up and the poller is not running: build the crudest version that works —
hit the endpoints, dump raw JSON with a timestamp into one table, deploy the cron. No schema
design, no scoring, no normalisation. Get it recording, then build everything else.

---

## Architecture

![Data flow](docs/mockups/architecture-flow.png)

```
cPanel cron (every 5 min)
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
      ├──► web app (read-only)
      ├──► MCP server (thin wrapper over the same queries)
      └──► alerts
```

Detail: [`docs/architecture.md`](docs/architecture.md) · Tables: [`docs/data-model.md`](docs/data-model.md)

**Why a web app and not only an agent.** A judge can open a URL and check the numbers against
CoinMarketCap themselves. That is what makes the largest scoring criterion easy to award.

**Why the MCP layer comes last.** Once the scores sit in a database, exposing them as agent tools
is a thin wrapper over queries already written. Same codebase, second track.

---

## Stack

Deliberately boring and already paid for:

- **PHP** on cPanel shared hosting
- **MySQL**
- **Real cron** via cPanel, invoked through PHP CLI — not a `wget` to a URL, which inherits HTTP
  timeouts and creates a public endpoint that then has to be protected
- Vanilla JS + one charting library on the frontend
- No framework unless it earns its place

Not Vercel: the Hobby plan rejects any cron schedule resolving to more than once per day, and it
fails at deploy time. A daily sample makes the product pointless.

---

## Repo layout

```
divergence/
├── README.md              you are here
├── CLAUDE.md              brief for agents working in this repo
├── TOMORROW.md            the ordered task list — start here
├── ROADMAP.md             phases from today to submission
├── config.example.php     shape of the real config, which lives outside the webroot
├── poller/                cron entry point, one fetcher per endpoint
├── scoring/              normalisation + Voice / Money / Divergence
├── lib/                   db, http client, credit accounting
├── public/                the web app — the only web-served directory
├── mcp/                   MCP server exposing the same scores as agent tools
├── sql/                   migrations
├── bin/                   one-off operational scripts
├── tests/                 scoring fixtures, mostly
└── docs/
    ├── architecture.md    components, cadence, failure behaviour
    ├── data-model.md      tables and why each column exists
    ├── method.md          exactly how each input is normalised and weighted
    ├── endpoint-access.md which CMC endpoints actually respond on our tier
    ├── open-questions.md  blockers to verify before building against them
    ├── decisions.md       decision log, with the reasoning
    ├── api-friction.md    where the API got in the way (submission requires this)
    ├── ui-spec.md         the four screens and what each one shows
    ├── mcp-tools.md       planned agent tool surface
    ├── deploy.md          cPanel runbook
    └── mockups/           the HTML mockup and rendered images
```

---

## How to run

Not runnable yet. When it is, this section says exactly this much and no more:

```bash
cp config.example.php ../config.php   # outside the webroot; fill in the API key
mysql -u USER -p DB < sql/001_init.sql
php poller/run.php --once             # one sample, prints what it wrote
```

Then a cPanel cron entry, every 5 minutes:

```
*/5 * * * * /usr/local/bin/php /home/USER/divergence/poller/run.php >> /home/USER/logs/poller.log 2>&1
```

Full setup, including the parts specific to this host: [`docs/deploy.md`](docs/deploy.md).

---

## CoinMarketCap endpoints used

Every row here is **unverified** until someone makes a real call with the real key on our tier.
Filling this table in is the first task of day one — see
[`docs/endpoint-access.md`](docs/endpoint-access.md).

| Axis | Endpoint | Used for | Status |
|---|---|---|---|
| Voice | community / content endpoints | social volume per asset | ❓ unverified |
| Voice | fear and greed | market-wide sentiment level | ❓ may not be an API at all |
| Money | derivatives / funding rate | the core of the Money axis | ❓ **highest-priority check** |
| Money | open interest | positioning size | ❓ unverified |
| Money | liquidations | forced-exit pressure | ❓ unverified |
| Both | listings / quotes latest | the asset universe and volume fallback | ❓ assumed available |

If derivatives are not on our tier, the fallback for Money is volume concentration plus exchange
reserve movement — weaker, but survivable. That decision is recorded in
[`docs/decisions.md`](docs/decisions.md).

---

## Method

The scoring is published, not hidden — a page in the app states exactly how each input is
normalised and weighted, and when the normalisation basis changed.

The one honest wrinkle, documented rather than buried: percentile-rank normalisation is meaningless
for the first several days because there is no distribution to rank against. So the first seven days
use **fixed reference ranges**, then the switch to percentile ranking happens once enough history is
banked. The switchover date and the reason go on the method page.

Full spec: [`docs/method.md`](docs/method.md).

---

## Constraints worth remembering

- **30 requests/minute.** Per-asset polling needs batching. Cap the universe at the top ~100.
- **300,000 call credits/month.** Generous, but log credits per call from the response so usage is
  observable rather than guessed.
- **Cadence:** 5 minutes market-wide, 15 minutes per asset. Roughly 6k market rows and 600k asset
  rows over three weeks — about 100 MB with indexes.
- Keep each cron run short and stateless. Shared hosts kill long-running processes.
- API key lives **outside the webroot**, never in git. A leaked key is a direct hit on the code
  quality score.

---

## Judging criteria, for prioritisation

| Weight | Criterion | What it means here |
|---|---|---|
| 30% | Does it work | A judge opens the URL and checks numbers against CMC |
| 25% | Usefulness | Answers a question CMC's own site cannot |
| 20% | Interesting API use | Unusual endpoint mix, derived metrics rather than re-display |
| 15% | Code quality and docs | Clean repo, method page, no leaked key |
| 10% | Presentation | The quadrant trail is the demo moment |

When trading off, protect "does it work" first. A smaller product that demonstrably runs beats a
larger one with a broken panel.

---

## Licence

MIT — see [LICENSE](LICENSE).
