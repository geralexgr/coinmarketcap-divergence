# Decision log

Decisions already made, with the reasoning, so they are not silently re-litigated. Newest last.

Format: what was decided, why, and what would have to be true to revisit it.

---

## D1 — Measurements only, never predictions or advice
**Date:** before day 1 · **Status:** load-bearing, do not revisit

Every output describes a condition that exists now or existed at a recorded moment. No buy/sell/hold,
no "looks overheated", no risk rating that implies action.

**Why:** "does it work" is 30% of the score and a measurement is verifiable against CoinMarketCap in
thirty seconds, where a prediction is not verifiable at all. Recommendations also drag in liability
and put the entry in the same crowded category as most of the field.

**Revisit if:** never, within this project.

---

## D2 — The poller ships before the frontend
**Date:** before day 1 · **Status:** load-bearing

**Why:** CoinMarketCap serves snapshots. Price history is retroactively fetchable; sentiment and
positioning history are not. The trail on the main chart, every week-over-week figure, and the
lead/lag view are made entirely of data we recorded ourselves. A day of delay is a day of history
that cannot be recovered.

**Revisit if:** a historical endpoint for social volume and funding turns up on our tier — in which
case check what granularity it actually offers before relaxing anything.

---

## D3 — Store the raw response, not just the extracted score
**Date:** before day 1 · **Status:** load-bearing

Every sample writes the verbatim payload alongside the parsed fields.

**Why:** if the divergence weighting changes on day eighteen, the entire history is recomputed from
what is already stored. Without this, the formula guessed at on day one is locked in permanently.

**Revisit if:** storage becomes a problem, which at ~100 MB for three weeks it will not.

---

## D4 — Log every fetch attempt, including failures
**Date:** before day 1 · **Status:** settled

**Why:** honest gap handling when the host or the API hiccups, and it writes most of the "where the
API got in the way" note the submission requires. Evidence rather than recollection.

---

## D5 — Record the actual sample time, not the scheduled one
**Date:** before day 1 · **Status:** settled

Cron drifts. Charts plot real timestamps. The scheduled time is not a fact about the data and is not
stored.

---

## D6 — PHP + MySQL on cPanel, with real cron via PHP CLI
**Date:** before day 1 · **Status:** settled

**Why:** already available and already paid for; the stack is boring enough to not consume project
time. Cron runs through PHP CLI rather than a `wget` to a URL, because a web-triggered cron inherits
HTTP timeouts and creates a public endpoint that then has to be protected.

**Why not Vercel:** the Hobby plan rejects any cron schedule resolving to more than once per day and
fails at deploy time. A daily sample makes the product pointless.

---

## D7 — Fixed reference ranges for the first seven days, then percentile rank
**Date:** before day 1 · **Status:** settled, values TBC

**Why:** percentile normalisation against trailing history is meaningless when there is no history —
scores would jump around and the plot would look broken during exactly the days when the poller is
being watched. Fixed ranges are stable and honest as long as they are published.

**Consequence:** the switchover date and reason go on the public method page, and every score row
stores which basis produced it.

---

## D8 — Web app first, MCP server second
**Date:** before day 1 · **Status:** settled

**Why:** a judge can open a URL and check numbers themselves, which is what makes the largest
criterion easy to award. The MCP layer is a thin wrapper over queries the web app already needs, so
it costs little once the scores exist and nothing is lost by deferring it.

---

## D9 — Asset universe capped at the top ~100
**Date:** before day 1 · **Status:** settled, revisit after batching is known

**Why:** 30 requests/minute and a credit budget that should be observable rather than guessed. Widen
only if batching turns out to be generous. It is: 100 ids in one call, confirmed against real payloads — see `limits.md`.

---

---

## D10 — The Money axis is built from turnover, not derivatives
**Date:** 12 September 2026 · **Status:** settled by measurement

Funding rate, open interest and liquidations have **no endpoint on the CoinMarketCap API**. 38
candidate paths probed across `/v1/` to `/v4/`; every one absent. This is not a plan-tier
restriction — the paths do not exist, so no upgrade produces them. Evidence and method in
`endpoint-access.md`; reproduce with `php app/bin/probe-paths.php`.

The Money axis therefore measures committed money indirectly, from what does exist:

| Input | Source | Reading |
|---|---|---|
| Turnover — `volume_24h / market_cap` | `/v2/cryptocurrency/quotes/latest` | money changing hands relative to the size of the thing it moves in |
| Exchange concentration | `/v1/exchange/listings/latest` | whether that flow is broad or sitting in one venue |
| Exchange reserve movement | `/v1/exchange/assets` | what is parked where |
| Derivatives volume share, **if the field exists** | `/v1/global-metrics/quotes/latest` | the one positioning number possibly still reachable |

**Why this is still worth building:** the product was never "we have funding rates" — CoinMarketCap
publishes both halves of this and never puts them on the same axis. Turnover against attention is
still a gap nothing on the site shows, and it is still measured rather than predicted. It is a
weaker Money axis and the method page says so plainly, in those words.

**What it costs:** the axis measures money *moving* rather than money *committed and leveraged*.
Turnover cannot distinguish a large spot rotation from a leveraged build-up. The method page states
this limitation rather than implying a precision the inputs do not have.

**Revisit if:** `/v2/cryptocurrency/market-pairs/latest?category=derivatives` turns out to carry open
interest, or `global_metrics` carries `derivatives_volume_24h`. Both are payload inspections, not new
endpoints, and both are the first thing to check once a real key exists.

---

## D11 — Raw payloads stored as LONGTEXT, not a MySQL JSON column
**Date:** 12 September 2026 · **Status:** settled

`docs/data-model.md` originally proposed a JSON column. A MySQL JSON column reparses and
re-serialises on write: key order and whitespace are not preserved, so what comes back out is not
byte-for-byte what CoinMarketCap sent.

**Why it matters:** D3 says the stored payload is the source of truth, which only holds if it is
verbatim. LONGTEXT also works on MySQL 5.6, which removes one host dependency before the host has
been checked.

**Revisit if:** a query needs to filter inside payloads at speed. Extraction into typed tables is
the answer to that, not changing the column type.

---

## D12 — Path existence is probed separately from plan access
**Date:** 12 September 2026 · **Status:** settled

Two scripts, because they answer different questions and one of them needs no key:
`app/bin/probe-paths.php` maps what exists, `app/bin/verify-endpoints.php` maps what our plan may call.

**Why:** CMC answers an unknown path with **HTTP 200** and `error_code: 500` "The system is busy".
Reading the HTTP status alone recorded six non-existent derivatives endpoints as working. The
prober carries two control paths that must read as absent on every run, so a routing change on
CMC's side shows up as a failed control rather than as silently wrong results.

---

## D13 — Extraction is pure, single-payload, and versioned
**Date:** 13 September 2026 · **Status:** settled

`app/lib/extract.php` turns exactly one stored payload into typed rows. It touches no database, no
clock and no network, and it never looks at another sample. The writer is `app/bin/extract.php`; every
write upserts on a unique key, and `extraction_log` records which payloads have been read at which
`EXTRACTOR_VERSION`.

**Why single-payload:** a rebuild has to be order-independent. The moment extraction can see the
previous sample, re-running it over history in a different order produces different numbers, and
D3 — that every derived figure is recomputable from what was stored — stops being true. So the
three cross-sample inputs the product needs (trending churn, exchange reserve *movement*, and every
percentile) belong to the scoring layer, which is allowed to see a series.

**Why versioned rather than a backfill script:** "we parsed it wrong" is the expected case, not the
exception, because the extractor was written against documented shapes before a key existed. Bump
the constant, run `--rebuild`, and all of history is re-derived from payloads already on disk. A
separate backfill script would be a second code path that has to stay in step with the first.

**Why nothing is defaulted to zero:** a missing field stored as `0` plots as a real reading. A
missing field stored as nothing plots as a gap. On a tool whose entire claim is that it measures
rather than predicts, that distinction is the product.

**Revisit if:** an input turns out to be unusable without its neighbours — in which case it becomes
a scoring-layer input, not an extraction-layer one.

---

## D14 — The plan is Basic, not Startup: 15,000 credits and no Voice endpoints
**Date:** 13 September 2026 · **Status:** settled by measurement, product consequences open

`php app/bin/verify-endpoints.php` with the real key. 17 endpoints called, 6 credits spent.

`/v1/key/info` reports **`credit_limit_monthly: 15000`**, not the 300,000 this repo has assumed
throughout, and `rate_limit_minute: 50` rather than 30. The monthly window resets 1 October 2026 —
after the hackathon closes.

**What the plan permits (7):** `key_info` (free), `global_metrics`, `listings_latest`,
`fear_and_greed`, `fear_and_greed_historical`, `quotes_latest`, `exchange_assets`.

**What it refuses with HTTP 403 (10):** every `trending` endpoint, both `community` endpoints, both
`content` endpoints, `exchange_listings`, `market_pairs_derivatives`, `price_performance`.

Two consequences, and they are not symmetrical.

**The Voice axis lost six of its seven inputs.** Social volume, trending rank churn, page views and
post counts are all behind the paywall. What survives is the fear and greed index — one number,
updated daily. A "Voice" axis sampled every five minutes from a daily number is not a measurement
of anything at five-minute resolution, and saying otherwise would be the first dishonest thing in
this repo. `docs/method.md` has to state the real resolution of each input.

The one consolation is real: `/v3/fear-and-greed/historical` returns 500 daily points, back to
1 May 2025. It is the only input in the product that can be backfilled to before recording started,
and it is now also nearly the whole axis.

**The Money axis got one input back.** `global_metrics` carries `derivatives_volume_24h` —
$307bn against $41bn of spot volume on 13 Sep 2026 — plus `derivatives_24h_percentage_change`.
This is the positioning-shaped number D10 concluded was unreachable. It is market-wide only, with
no funding rate, no open interest and no per-asset breakdown, so D10 stands as written for the
per-asset axis and is **amended** for the market-wide one. The same payload also carries
`total_volume_24h_reported` (5.1x the adjusted figure), the defi and stablecoin blocks, and
yesterday's deltas for cap, volume and dominance.

**Why the endpoint catalogue now gates on this:** `endpoint_access_results()` in
`app/lib/endpoints.php` records the measurement, and `endpoints_to_poll()` drops anything marked
forbidden regardless of its `poll` flag or a config override. A 403 costs a round trip, returns
nothing, and fills `fetch_log` with noise that hides real failures.

**Revisit if:** the plan is upgraded. Nothing in this repo will change these results; only the key
will. Re-run the verifier and edit that one function.

---

## D15 — Cadence is set by the credit budget, not by what would be ideal
**Date:** 13 September 2026 · **Status:** settled

5 minutes market-wide and 15 minutes per asset — the cadence this repo has assumed — costs about
1,250 credits a day against the real 15,000-credit budget. That exhausts it in **twelve days**, and
the poller would stop recording mid-hackathon with the submission still a week away.

A polled market run costs 4 credits (`global_metrics`, `listings_latest`, `fear_and_greed`,
`exchange_assets`; `key_info` is free). An asset run costs 1.

**Settled: 10 minutes market-wide, 30 minutes per asset.** 624 credits a day, about 11,100 for the
remaining cycle, leaving roughly a quarter of the budget as headroom for re-runs, backfills and the
days when something has to be re-fetched.

**Why not the cheaper 15/30 option:** turnover is the headline Money input and it does move within
fifteen minutes. Ten minutes keeps the quadrant trail — the demo moment — visibly alive while still
finishing the month with budget unspent.

**The better fix, not taken yet:** cadence is per *scope*, so every endpoint in a scope is sampled
at the same rate. That is wasteful in an obvious way — the fear and greed index updates **once a
day** and is currently being fetched 144 times a day for 143 identical values, while `global_metrics`
genuinely changes minute to minute. Per-endpoint cadence would cut the bill by roughly half and buy
back the 5-minute market sample. It is the first thing to build if credits get tight.

**Revisit if:** the plan changes, or per-endpoint cadence lands.

---

## D16 — The Voice axis ships on what is reachable, with the resolution stated

**Date:** 13 September 2026 · **Status:** settled · **Supersedes the pending item left by D14**

D14 left one product decision open: with six of the seven Voice endpoints answering 403, either the
plan gets upgraded or the axis is rebuilt on what is reachable. **Settled: rebuilt, and the
limitation is published rather than worked around.**

Voice is the fear and greed index alone, weight 1.00 after renormalisation, updated **once a day**.
The other two designed inputs stay declared in `app/scoring/inputs.php` at their intended weights,
marked unavailable, and shown that way on the method page.

**Why not upgrade the plan:** it makes the product depend on a purchase to be demonstrable, and a
judge running it against their own Basic key would see a broken tool. Shipping on the free tier and
being explicit about what that costs is the more honest artefact, and it is the one that still works
when somebody else clones the repo.

**Why not drop the axis and ship one number:** the quadrant *is* the product. A daily-resolution
axis still separates the four readings correctly; it just steps rather than glides. The app says so
directly on the chart, so the flat stretches read as a daily input rather than as a quiet market.

**What it costs, stated plainly:** Voice moves once a day while Money moves every ten minutes. The
trail is therefore mostly horizontal with daily vertical steps. That is a real weakness of the
deployment and it is written on the market screen, not only in this file.

**Revisit if:** the plan changes. Nothing in the code needs to — `endpoint_access_results()` gates
availability and the inputs are already declared.

---

## D17 — Per-asset scores are ranked against the universe, not against their own past

**Date:** 13 September 2026 · **Status:** settled

Market-wide scores rank each input against its own history (D7). Applying that per asset would mean
no screener until a week of history existed per asset, and a percentile per asset per metric per
sample is also the most expensive query in the product.

**Settled: per-asset inputs are ranked cross-sectionally — against the rest of the tracked universe
at the same instant.** "Turnover in the 92nd percentile of the top 100 right now." Recorded on the
row as `basis = 'cross_section'`, a third value alongside `fixed` and `percentile`.

**Why it is better rather than merely cheaper:** it is the question a screener is actually asked.
Sorting assets by how unusual each is *against the others* is what makes the table a screener;
sorting by how unusual each is against its own last week is a different tool. It also works from the
first sample, so the screener is populated on day one.

**What it costs:** market-wide and per-asset scores are not comparable and must never share a chart.
The method page says this and the two views are kept separate in the UI.

---

## D18 — The per-asset Voice proxy is price-derived, and labelled as the weakest number in the product

**Date:** 13 September 2026 · **Status:** settled, uncomfortably

There is no per-asset attention data on the Basic plan: trending, most-visited and community are the
endpoints that carry it and all three are 403. The per-asset Voice axis therefore has nothing clean
to measure.

**Settled: `abs_percent_change_24h` — the size of the day's move, direction discarded — as a proxy,
on the reasoning that an asset that moved 30% is being looked at whichever way it moved.**

**Why this is uncomfortable:** it is derived from price, so it is partly contaminated by the Money
axis, and the whole point of the product is that the two axes measure different things. A screener
built on it will correlate the axes more than the design intends.

**Why it ships anyway:** the alternative is a screener with one axis, which is not a screener. The
mitigation is disclosure rather than cleverness — the method page names it as the weakest input in
the product, and `trend_rank` stays declared at weight 0.60 so the moment attention data is
reachable the proxy drops to a minority input on its own.

**Revisit if:** any trending endpoint becomes callable. Nothing needs rewriting; the weights already
describe the intended axis.

---

## D19 — The extractor streams payloads instead of fetching a batch

**Date:** 13 September 2026 · **Status:** settled, after it broke

`app/bin/extract.php` selected `raw_samples.payload` alongside the id and fetched the batch with
`fetchAll()`. At the documented cron limit of 2000 that is up to 2000 LONGTEXT bodies in memory at
once — a listings payload is around 150KB, so roughly 300MB — and it died on PHP's default 128MB
limit on the first full run against real volumes.

**Settled: select the ids only, then load each payload individually inside the loop and release it.**
Peak memory becomes a property of the largest single payload rather than of `--limit`, so the cron
entry is safe at any batch size on a shared host.

**Why it matters more than it looks:** the failure mode was a fatal error partway through a run, on
the component whose whole job is to be re-runnable. It would have surfaced on the host as an
extractor that silently stopped keeping up while the poller kept recording — visible only as
extraction lag in `app/bin/health.php`, days later.

**The general lesson, applied elsewhere in the codebase:** anything that reads `raw_samples` reads
one payload at a time. The scoring layer loads `market_metric`, which is small typed rows, not
payloads.

---

## Still undecided

Nothing blocking. These are refinements that need data the deployment has not yet produced:

- **Reference ranges.** The fixed-basis floors and ceilings in `app/scoring/inputs.php` are set from the
  first days of measurement and from the live payloads captured on 13 September 2026. They should be
  re-derived from a fortnight of real distributions and `METHOD_VERSION` bumped when they are.
- **Percentile window length.** 30 days is declared; the deployment will never have that much during
  the hackathon, so in practice the window is "all of recorded history". Worth revisiting only if
  this runs past a month.
- **Per-endpoint cadence.** Cadence is per scope, so the once-a-day fear and greed index is fetched
  144 times a day for 143 identical values (D15). Per-endpoint intervals would roughly halve the
  credit bill and buy back the 5-minute market sample. The first thing to build if credits get tight.
