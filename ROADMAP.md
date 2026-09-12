# Roadmap

Submissions close early October 2026. Today is 12 September 2026, so roughly three weeks.

Phases are ordered by what would hurt most if it were missing. The recorder is first because it is
the only part that cannot be caught up on later.

---

## Phase 0 — Verify (day 1, morning)

Nothing is designed against an unverified endpoint.

- Endpoint access matrix filled in from real calls on the real host
- Derivatives availability settled either way, with the fallback recorded if not
- Host capabilities confirmed: cron interval, PHP CLI path, outbound HTTPS, MySQL

**Exit:** `docs/endpoint-access.md` has no "probably" in it.

## Phase 1 — Record (day 1)

- Crude poller storing raw JSON + real timestamp + HTTP status
- Cron live on the host at 5-minute cadence
- Two consecutive successful runs observed

**Exit:** row count climbing with nobody watching. This is the milestone that cannot slip.

## Phase 2 — Structure (day 2)

- Poller split into fetchers, `lib/`, and a thin entry point
- Real schema, raw payloads still the source of truth
- Re-runnable extraction pass over stored history
- Per-asset polling at 15 min, top ~100, batched under 30 req/min
- Credit accounting per call
- Recording-health script

**Exit:** poller has never stopped, and the data is now queryable by field.

## Phase 3 — Score (days 3–4)

- Fixed reference ranges documented, then implemented
- Voice / Money / Divergence, market-wide and per asset
- Recompute-from-raw path
- Normalisation fixtures under test

**Exit:** a score for every sample in history, reproducible from raw.

## Phase 4 — Show (days 5–8)

- Market view: quadrant, trail, readout
- Per-asset screener sorted by gap
- Method page with real formulas and the switchover date
- Provenance on every number: endpoint + sample minute
- Lead-and-lag view if history supports an honest version

**Exit:** a judge can open a URL, pick a number, and verify it against CoinMarketCap.

## Phase 5 — Extend (days 9–12)

- MCP server over the same queries
- Alerts on quadrant transitions
- Percentile-rank normalisation switched on once history is deep enough, switchover published

**Exit:** an agent can ask for the same scores the web app shows.

## Phase 6 — Submit (final days)

- API friction write-up from `fetch_log` evidence
- README request/response example with a real payload
- Repo audit: no key in history, no predictive language anywhere
- Demo path rehearsed — the quadrant trail is the moment

---

## What gets cut first if time runs out

In this order, most expendable first:

1. Alerts
2. Lead-and-lag view
3. Percentile-rank normalisation (fixed ranges are documented and honest)
4. MCP server

What never gets cut: the recorder, the market view, the method page, provenance on the numbers.
