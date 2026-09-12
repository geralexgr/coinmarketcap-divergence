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

## Pending decisions

These are waiting on `open-questions.md` and must be recorded here once settled:

- Money axis inputs, final — depends on derivatives availability (question 2)
- Voice axis inputs, final — depends on fear and greed availability (question 3)
- Market-wide cadence — depends on the cron floor (question 4)
- Percentile window length (question 7)
