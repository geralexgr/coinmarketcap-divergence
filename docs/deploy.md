# Deploy runbook

Target: cPanel shared hosting, PHP + MySQL, real cron via PHP CLI.

Written before first deployment, so every step is an expectation until it has been done once —
correct this file the first time it is followed, and note anything the host does differently.

`bin/preflight.php` checks most of what this runbook assumes, and prints a markdown table of results
for `docs/endpoint-access.md`. Run it after step 3 and again after step 2 is fixed, rather than
discovering a missing extension at step 5.

## 1. Layout on the host

The webroot serves `public/` only. Everything else sits above it, unreachable over HTTP.

```
/home/USER/
├── config.php                 ← the real config. Never in git.
├── logs/
│   └── divergence.log
└── divergence/                ← the repo
    ├── poller/
    ├── scoring/
    ├── lib/
    ├── sql/
    └── public/                ← document root points here
```

If the host cannot point a document root at a subdirectory, `public/` contents go to
`public_html/` and the repo sits beside it — but the config file stays outside either way.

## 2. Database

- Create the database and a user with only the privileges needed (`SELECT, INSERT, UPDATE, DELETE`,
  plus DDL while migrating)
- Raw payloads go in `LONGTEXT`, not a JSON column, so MySQL 5.6 is enough (D11)
- `mysql -u USER -p DB < sql/001_init.sql` — the recording core. Enough to start recording
- `mysql -u USER -p DB < sql/002_derived.sql` — the derived tables. Only `bin/extract.php` needs
  these, so if anything about them goes wrong, apply 001 and start recording anyway

Both are safe to re-run: every statement is `IF NOT EXISTS`.

## 3. Config

```bash
cp divergence/config.example.php /home/USER/config.php
# fill in the API key and DB credentials
chmod 600 /home/USER/config.php
```

The config loader searches `$DIVERGENCE_CONFIG`, then `../config.php` relative to the repo root,
then `./config.php`. The layout above hits the second, which is why the repo sits one level below
the config.

Verify it is not web-reachable: requesting it over HTTP must 404. `bin/preflight.php` also asserts
the resolved config path is not under `public/`.

## 4. Verify PHP CLI can reach the API

Before the cron entry exists. From an SSH session on the host, not a laptop:

```bash
/usr/local/bin/php /home/USER/divergence/bin/preflight.php
/usr/local/bin/php /home/USER/divergence/bin/verify-endpoints.php --save-fixtures
/usr/local/bin/php /home/USER/divergence/poller/run.php --once
/usr/local/bin/php /home/USER/divergence/bin/extract.php --verbose
```

`preflight.php` checks the PHP version and extensions, DNS, a raw TLS connect, an authenticated
call, the MySQL connection and INSERT grant, and the log directory. If outbound HTTPS is blocked for
CLI, this is where it surfaces — see `open-questions.md` item 5.

`verify-endpoints.php` then settles which endpoints the plan permits, costs about 20 credits, and
with `--save-fixtures` leaves real payloads in `tests/fixtures/live/`. The extraction layer is
already built against the documented shapes, so the thing to do with those payloads is run the
tests against them: `php tests/run.php extract`, re-pointed at `live/`. Each failure is a place
where the documentation and the API disagree.

The `extract.php --verbose` run then shows, per payload, what was read and what was skipped and
why. It writes no derived rows for a payload it does not understand — it records the reason instead,
and `bin/health.php` surfaces it.

The `--once` run prints what it fetched and what it wrote. `--dry-run` alongside it fetches and
reports without writing.

## 5. Cron

cPanel → Cron Jobs. Three entries.

```
*/10 * * * * /usr/local/bin/php /home/USER/divergence/poller/run.php --market >> /home/USER/logs/divergence.log 2>&1
*/30 * * * * /usr/local/bin/php /home/USER/divergence/poller/run.php --assets >> /home/USER/logs/divergence.log 2>&1
7,27,47 * * * * /usr/local/bin/php /home/USER/divergence/bin/extract.php --quiet --limit=2000 >> /home/USER/logs/divergence.log 2>&1
```

**The cadence is set by the credit budget, not by preference.** The Basic plan allows 15,000
credits a month (D14). A market run costs 4 credits and an asset run 1, so 10-minute/30-minute
sampling costs about 624 a day — roughly 11,100 for the cycle, a quarter of the budget spare. The
originally documented 5-minute/15-minute cadence costs ~1,250 a day and runs the budget dry in
twelve days, mid-hackathon. Do not raise it without redoing that arithmetic; `bin/health.php`
prints the remaining headroom in days on every run.

The extractor's entry is offset (`7,27,47`) rather than `*/20` so it never lands on the same minute
as either poller tick.

The extractor is separate from the poller on purpose. It costs no credits and touches no network,
so a slow or failing extraction must never be able to delay a fetch — the fetch is the part that
cannot be caught up on later. Running it at an interval that does not divide the poller's also keeps
the two off the same tick on a host that limits concurrent processes.

`--limit` caps one run's work so a tick stays short on shared hosting; anything left over is picked
up by the next one, oldest first.

Notes:
- Absolute paths for both the binary and the script. Cron's `PATH` is not a login shell's.
- The poller takes a per-scope lock in the system temp directory, so if a run overruns its tick the
  next one skips with exit code 3 rather than piling up behind it.
- Exit codes: 0 all recorded · 1 some failed · 2 nothing recorded · 3 could not start.
- `>> ... 2>&1` so failures land in the log rather than in an email nobody reads.
- Not a `wget` to a URL: that inherits HTTP timeouts and creates a public endpoint needing
  protection.
- Confirm the host's minimum interval before assuming `*/5` is honoured.
- `bin/extract.php` takes its own lock, so a long rebuild and a scheduled run cannot collide.

## 6. Confirm it is recording

Wait ten minutes, then:

```sql
SELECT count(*) AS samples, min(fetched_at) AS first, max(fetched_at) AS latest FROM raw_samples;
SELECT endpoint, http_status, count(*) FROM fetch_log GROUP BY 1, 2;
```

Two consecutive runs landing, with no unexpected statuses, is the day-1 milestone.

## 7. Ongoing checks

- `bin/health.php` — per-endpoint success rate, cadence, longest gap, extraction lag, credits
  against the real limit reported by `/v1/key/info`, and the
  budget. Exits non-zero when nothing has landed recently, so it also works as a watchdog:
  `*/30 * * * * /usr/local/bin/php /home/USER/divergence/bin/health.php --stale=1800 || mail ...`
- Credit consumption against the 15,000/month budget, from `fetch_log`
- Log size; rotate if the host does not

## Rollback

The poller is append-only and the web app is read-only, so a bad deploy cannot corrupt history. If
the poller breaks, the previous version is restored and the gap it left shows up as a gap. Derived
tables are always rebuildable from `raw_samples`, so there is no state worth backing up separately
beyond the database itself.
