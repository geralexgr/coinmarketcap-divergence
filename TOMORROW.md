# Start here

Written 12 September 2026. This is the file to open first. It assumes you have read nothing else.

**Context in one line:** we are building a tool that measures the gap between what the crypto
market says and what it has committed money to, for the CoinMarketCap API Hackathon, submissions
closing early October 2026.

**Where it stands.** The recording layer *and* the extraction layer are built and tested end-to-end
against a real MySQL: the schema, the poller, the extractor, an endpoint verifier, a host preflight
check and a health check. Two open questions are answered by measurement. **Nothing is recording
yet**, because that needs the API key and the host, and neither has been touched.

Day 2 was pulled forward because it needed neither. It is done against synthetic fixtures written
from the documented response shapes, which means the extractor is the right shape but is not yet
proof the real payloads match. Confirming that is step 1 below, and it is now nearly free: point the
tests at the saved live payloads and run them.

**The one thing that matters today:** the poller must be recording. CoinMarketCap does not serve
historical sentiment or positioning data. Every hour the poller is not running is an hour of history
that cannot be recovered later, and the trail on the main chart — the demo moment — is made entirely
of recorded history.

---

## What is done

- [x] `sql/001_init.sql` — `raw_samples` (verbatim payloads) and `fetch_log` (every attempt)
- [x] `lib/` — config loading from outside the webroot, the CMC client, the DB writers, the
      endpoint catalogue
- [x] `poller/run.php` — `--market`, `--assets`, `--once`, `--dry-run`, per-scope locking,
      real fetch timestamps, failures stored not just logged
- [x] `bin/probe-paths.php` — which paths exist. No key needed
- [x] `bin/verify-endpoints.php` — which ones the plan permits. Needs the key
- [x] `bin/preflight.php` — can this host run the poller at all
- [x] `bin/health.php` — is it recording, what is the cadence, what did it cost
- [x] **Open question 2 answered:** there are no derivatives endpoints. Money axis rebuilt around
      turnover — decision D10
- [x] **Open question 3 answered:** fear and greed is a real endpoint, not only a site chart
- [x] `sql/002_derived.sql` — `market_metric`, `asset_metric`, `asset_universe`, `extraction_log`,
      `scores`
- [x] `lib/extract.php` — pure, single-payload, no clock and no network, so it is testable and a
      rebuild is order-independent
- [x] `bin/extract.php` — `--rebuild`, `--limit`, `--dry-run`, `--endpoint`, locked like the poller.
      Verified idempotent against a real MySQL: a rebuild re-derives the same rows, it does not
      double the series
- [x] `tests/run.php` + 21 extraction tests, no framework and no network
- [x] `bin/health.php` widened with extraction lag and skip reasons
- [x] Found and fixed: preflight passed a PHP 7.4 host, but `lib/http.php` uses `match()`, so such
      a host would have parse-errored instead of reporting anything. Floor is 8.0 now

## What is blocked on you

Both blockers are credentials, not code.

1. **A CoinMarketCap API key.** Put it in `../config.php` (copy `config.example.php`). Nothing
   downstream can be verified without it.
2. **The cPanel host** — SSH access, a MySQL database and user, and the PHP CLI path.

## Day 1 — get something recording

### 1. Verify the API with the real key ~20 min

```bash
php bin/verify-endpoints.php --save-fixtures
```

- [ ] Run it **on the host**, so it doubles as proof the host can reach the API (open question 5)
- [ ] Paste the generated tables into `docs/endpoint-access.md`, replacing the ❓ column
- [ ] **Read two payloads before anything else.** Both could partly reverse D10:
      does `global_metrics` carry `derivatives_volume_24h`, and does
      `market-pairs/latest?category=derivatives` carry open interest?
- [ ] `--save-fixtures` writes the real payloads to `tests/fixtures/live/`, which is what the
      extraction layer gets built against without spending credits

### 2. Confirm the host can do this ~15 min

```bash
php bin/preflight.php
```

Checks the PHP version and extensions, outbound HTTPS from CLI, the MySQL connection and INSERT
grant, whether the config sits outside the webroot, and the plan's real credit and rate limits. It
prints a markdown table for the "Host checks" section of `docs/endpoint-access.md`.

- [ ] Every blocking check passes
- [ ] cPanel cron minimum interval confirmed — open question 4, and the one thing preflight cannot
      check for you

### 3. Start recording ~20 min

```bash
mysql -u USER -p DB < sql/001_init.sql
php poller/run.php --once
```

- [ ] Then the two cron entries from `docs/deploy.md`
- [ ] Watch two consecutive runs land

**Day 1 is done when** `php bin/health.php` says "Recording cleanly" and the sample count climbs
without anyone touching anything.

---

## Day 2 — mostly done, ahead of schedule

Built on 13 Sep 2026 while Day 1 was blocked on credentials. What is left needs the key:

- [ ] Re-point the extraction tests at `tests/fixtures/live/` and run them. **Every failure is a
      place where the documented shape and the real one disagree.** Fix the extractor, bump
      `EXTRACTOR_VERSION` in `lib/extract.php`, run `php bin/extract.php --rebuild`
- [ ] Confirm batching from a real `quotes_latest` payload — open question 6. The extractor already
      records `quotes_asset_count` per sample, so the check is a query: does a 100-id request come
      back as 100 assets?
- [ ] Add the extractor's cron entry (`docs/deploy.md`) and watch `bin/health.php` report 0 pending

## Day 3–4 — scoring

Everything the scoring layer reads is now in `market_metric` and `asset_metric`, and the three
cross-sample inputs it owns are listed in `docs/data-model.md`: trending churn, exchange reserve
movement, and every percentile.

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
