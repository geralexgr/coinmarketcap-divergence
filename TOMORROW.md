# Start here

Written 12 September 2026. This is the file to open first. It assumes you have read nothing else.

**Context in one line:** we are building a tool that measures the gap between what the crypto
market says and what it has committed money to, for the CoinMarketCap API Hackathon, submissions
closing early October 2026. Nothing is implemented yet.

**The one thing that matters today:** the poller must be recording before you build anything else.
CoinMarketCap does not serve historical sentiment or positioning data. Every hour the poller is not
running is an hour of history that cannot be recovered later, and the trail on the main chart — the
demo moment — is made entirely of recorded history.

So the order below is not a preference. Do not reorder it.

---

## Day 1 — get something recording

Target: by end of day, a cron job on the real host is writing rows into MySQL every five minutes.
Ugly is fine. Wrong-shaped is fine. Not running is not fine.

### 1. Verify the API before designing against it ~45 min

Make real calls with the real key from the real server. Not from a laptop, not by reading the docs.

- [ ] Confirm outbound HTTPS from PHP CLI to `pro-api.coinmarketcap.com` works on the host
- [ ] Hit each endpoint in the table in `docs/endpoint-access.md`, record status, shape, credits
- [ ] **Derivatives first** — funding, open interest, liquidations. This is the highest-priority
      check because the Money axis depends on it and the fallback is materially weaker
- [ ] Confirm whether fear and greed exists as an endpoint at all, or only as a chart on the site
- [ ] Write every result into `docs/endpoint-access.md`. Leave nothing as "probably"

**Decision gate:** if derivatives are unavailable on our tier, stop and record the fallback choice
in `docs/decisions.md` before writing any fetcher. See open question 2 in `docs/open-questions.md`.

### 2. Confirm the host can actually do this ~20 min

- [ ] cPanel cron minimum interval — some shared hosts enforce a 5 or 15 minute floor
- [ ] PHP CLI binary path (`/usr/local/bin/php` or otherwise) and its version
- [ ] MySQL database created, user created, credentials in a config file **outside the webroot**
- [ ] A writable log directory outside the webroot

### 3. The crudest possible poller ~2 hr

Deliberately not the good version. One file. No abstraction.

- [ ] One table: `raw_samples(id, endpoint, fetched_at, http_status, credits, payload JSON)`
- [ ] One script that loops over the confirmed endpoints, stores the raw response verbatim,
      and records the **actual** fetch time, not the scheduled one
- [ ] Failures are stored too — status and error text, same table or `fetch_log`
- [ ] `--once` flag that fetches, prints what it wrote, and exits
- [ ] Deploy it. Add the cron entry. Watch two consecutive runs land

**Day 1 is done when** `SELECT count(*), max(fetched_at) FROM raw_samples` shows numbers going up
without anyone touching anything.

---

## Day 2 — structure, without stopping the recording

The poller keeps running untouched while you do this. Never take it offline to refactor.

- [ ] Split the crude script: `lib/http.php`, `lib/db.php`, `poller/run.php`, one fetcher per source
- [ ] Real schema — `docs/data-model.md` has the draft. Raw payloads stay the source of truth
- [ ] Extraction pass that reads stored raw payloads and populates the typed tables, so it can be
      re-run over all history when the parsing is wrong
- [ ] Per-asset polling at 15 min, universe capped at top ~100, batched against the 30/min limit
- [ ] Credit accounting from the response headers into `fetch_log`
- [ ] `bin/` script that prints recording health: rows/hour, gaps, failure rate

---

## Day 3–4 — scoring

- [ ] Fixed reference ranges for each input, written down in `docs/method.md` first, code second
- [ ] Voice score, Money score, Divergence — market-wide
- [ ] Same three per asset
- [ ] Recompute-from-raw path, so weightings can change on day eighteen without losing history
- [ ] Fixtures in `tests/` for the normalisation, because this is the part that silently goes wrong

---

## Day 5–8 — the web app

- [ ] Market view: quadrant + trail + the readout panel (mockup is in `docs/mockups/`)
- [ ] Per-asset table sorted by gap
- [ ] Method page — publish the actual formulas, including the normalisation switchover date
- [ ] Every number links to the endpoint it came from and the minute it was sampled
- [ ] Lead-and-lag view, if the recorded history is long enough to say anything honest

---

## Day 9+ — second track and submission

- [ ] MCP server over the same queries (`docs/mcp-tools.md`)
- [ ] Alerts on quadrant transitions
- [ ] `docs/api-friction.md` written up from `fetch_log` evidence — the submission asks for this
- [ ] README request/response example with a real payload
- [ ] Final pass: no key in git history, no sentence anywhere that predicts anything

---

## Standing rules

1. **No predictions, no advice.** Every output describes a condition that exists or existed. If a
   feature would need an opinion about the future, propose the measured version instead.
2. **Raw payloads are never discarded.** Every derived number must be recomputable from them.
3. **Log every fetch attempt, including failures.** It is both honest gap handling and the evidence
   for the API friction write-up.
4. **Record real sample times.** Cron drifts; charts plot what actually happened.
5. **Protect "does it work."** 30% of the score. A smaller thing that runs beats a bigger thing
   with a broken panel.

## Where to look for what

| Question | File |
|---|---|
| What is this product | `README.md` |
| What do I do next | this file |
| Why was it built this way | `docs/decisions.md` |
| What is still unknown | `docs/open-questions.md` |
| What do the tables look like | `docs/data-model.md` |
| How is a score calculated | `docs/method.md` |
| What does the UI show | `docs/ui-spec.md` + `docs/mockups/` |
| How do I deploy it | `docs/deploy.md` |
