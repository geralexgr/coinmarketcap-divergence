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
only if batching turns out to be generous — see `open-questions.md` item 6.

---

---

## D10 — The Money axis is built from turnover, not derivatives
**Date:** 12 September 2026 · **Status:** settled by measurement

Funding rate, open interest and liquidations have **no endpoint on the CoinMarketCap API**. 38
candidate paths probed across `/v1/` to `/v4/`; every one absent. This is not a plan-tier
restriction — the paths do not exist, so no upgrade produces them. Evidence and method in
`endpoint-access.md`; reproduce with `php bin/probe-paths.php`.

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
`bin/probe-paths.php` maps what exists, `bin/verify-endpoints.php` maps what our plan may call.

**Why:** CMC answers an unknown path with **HTTP 200** and `error_code: 500` "The system is busy".
Reading the HTTP status alone recorded six non-existent derivatives endpoints as working. The
prober carries two control paths that must read as absent on every run, so a routing change on
CMC's side shows up as a failed control rather than as silently wrong results.

---

## D13 — Extraction is pure, single-payload, and versioned
**Date:** 13 September 2026 · **Status:** settled

`lib/extract.php` turns exactly one stored payload into typed rows. It touches no database, no
clock and no network, and it never looks at another sample. The writer is `bin/extract.php`; every
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

## Pending decisions

These are waiting on `open-questions.md` and must be recorded here once settled:

- Money axis inputs, final — settled in D10, but the weights wait on real distributions
- Voice axis inputs, final — fear and greed exists as an endpoint (question 3); which of the
  Voice candidates our plan may call is still open (question 1)
- Market-wide cadence — depends on the cron floor (question 4)
- Percentile window length (question 7)
