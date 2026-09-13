# Deploy runbook

Target: cPanel shared hosting, PHP 8.0+ and MySQL, real cron through PHP CLI.

`app/bin/preflight.php` checks most of what this runbook assumes and prints a markdown table of results.
Run it after step 3, rather than discovering a missing extension at step 6.

## 1. Layout on the host

The webroot serves `public/` only. Everything else — the config, the poller, the scoring layer, the
raw payloads — sits above it and is unreachable over HTTP.

```
/home/USER/
├── config.php                 ← the real config. Never in git. chmod 600.
├── logs/
│   └── divergence.log
└── divergence/                ← the repo
    ├── app/                   ← NEVER web-reachable
    │   ├── poller/
    │   ├── scoring/
    │   ├── lib/
    │   ├── mcp/
    │   └── bin/
    └── public/                ← document root points here
```

The `app/` and `public/` split is the deployment boundary. `app/` carries an `.htaccess` denying
everything, and the web app refuses to start if it finds the config file inside the document root —
both are second lines of defence behind putting the repo above the webroot in the first place.

**If the host cannot point a document root at a subdirectory** — many cPanel accounts cannot, for the
primary domain — put the contents of `public/` into `public_html/` and the repo beside it, then fix
the four `require __DIR__ . '/../lib/...'` paths in `public_html/bootstrap.php` to point at wherever
the repo landed. The config file stays outside either way.

The cleaner alternative on cPanel is to add the site as a **subdomain** and set its document root to
`/home/USER/divergence/public`, which needs no path edits at all.

## 2. Database

- Create the database and a user with `SELECT, INSERT, UPDATE, DELETE`, plus DDL while migrating.
- Raw payloads go in `LONGTEXT`, not a JSON column, so MySQL 5.6 is enough ([D11](decisions.md)).

```bash
mysql -u USER -p DB < docs/schema.sql
```

Or, with no SSH: phpMyAdmin → select the database → SQL tab → paste `docs/schema.sql` → Go.

Seven tables, one script, safe to re-run: every statement is `IF NOT EXISTS`. Two of them —
`raw_samples` and `fetch_log` — can never be rebuilt from anything else. The rest are caches of an
interpretation of those two and are rebuildable at any time.

The schema lives in `docs/` rather than in a `sql/` folder because it is applied once, by hand, and
`docs/` is deleted on the host after extraction. Keeping it in the repo is what lets anyone cloning
this recreate the database.

**Sizing.** At the documented cadence, roughly 2,500 market rows and 250,000 asset rows over three
weeks — around 100–150 MB with indexes. Comfortable on shared hosting, but check the account quota
before assuming it.

## 3. Config

```bash
cp divergence/config.example.php /home/USER/config.php
# fill in the API key and DB credentials
chmod 600 /home/USER/config.php
```

The loader searches `$DIVERGENCE_CONFIG`, then `../config.php` relative to the repo root, then
`./config.php`. The layout above hits the second, which is why the repo sits one level below the
config.

Verify it is not web-reachable: requesting it over HTTP must 404. `app/bin/preflight.php` also asserts
the resolved config path is not under `public/`.

## 4. Verify PHP CLI can reach the API

Before any cron entry exists, from an SSH session **on the host** — a laptop reaching the API proves
nothing about cPanel:

```bash
/usr/local/bin/php /home/USER/divergence/app/bin/preflight.php
/usr/local/bin/php /home/USER/divergence/app/bin/verify-endpoints.php --save-fixtures
/usr/local/bin/php /home/USER/divergence/app/poller/run.php --once
/usr/local/bin/php /home/USER/divergence/app/bin/extract.php --verbose
/usr/local/bin/php /home/USER/divergence/app/bin/score.php --verbose
```

`preflight.php` checks the PHP version and extensions, DNS, a raw TLS connect, an authenticated call,
the MySQL connection and INSERT grant, and the log directory. **If outbound HTTPS is blocked for CLI,
this is where it surfaces** — some hosts firewall CLI differently from the web SAPI.

`verify-endpoints.php` settles which endpoints the plan permits, costs about 6 credits, and with
`--save-fixtures` leaves real payloads in `tests/fixtures/live/`. Run the tests against them:
`php tests/run.php` — each failure is a place where the documentation and the API disagree. If the
plan differs from the one this repo was built against, update `endpoint_access_results()` in
`app/lib/endpoints.php`; everything downstream follows from that one table.

`--once` prints what it fetched and what it wrote. `--dry-run` alongside it fetches and reports
without writing.

## 5. Cron

cPanel → Cron Jobs. **Four entries.**

```
*/10 * * * *    /usr/local/bin/php /home/USER/divergence/app/poller/run.php --market  >> /home/USER/logs/divergence.log 2>&1
*/30 * * * *    /usr/local/bin/php /home/USER/divergence/app/poller/run.php --assets  >> /home/USER/logs/divergence.log 2>&1
7,27,47 * * * * /usr/local/bin/php /home/USER/divergence/app/bin/extract.php --quiet --limit=2000 >> /home/USER/logs/divergence.log 2>&1
12,42 * * * *   /usr/local/bin/php /home/USER/divergence/app/bin/score.php --quiet --limit=500      >> /home/USER/logs/divergence.log 2>&1
```

**The cadence is set by the credit budget, not by preference.** The Basic plan allows 15,000 credits a
month ([D14](decisions.md)). A market run costs 4 credits and an asset run 1, so 10/30-minute sampling
is about 624 a day — roughly 11,100 for a three-week cycle, a quarter of the budget spare. The
originally documented 5/15-minute cadence costs ~1,250 a day and runs the budget dry in twelve days.
Do not raise it without redoing that arithmetic; `app/bin/health.php` prints the remaining headroom in
days on every run.

**Why three jobs and not one.** The extractor and the scorer cost no credits and touch no network, so
a slow or failing one must never be able to delay a fetch — the fetch is the part that cannot be
caught up on later. Their minutes are offset (`7,27,47` and `12,42`) rather than `*/20` so they never
land on the same tick as a poller run, which matters on a host that limits concurrent processes.

**Notes:**

- Absolute paths for both the binary and the script. Cron's `PATH` is not a login shell's. Find the
  CLI binary with `which php` over SSH — it is often `/usr/local/bin/php` but may be an `ea-php83`
  path, and it is frequently **not** the same binary the web server uses.
- Every job takes a lock, so an overrunning run makes the next tick skip rather than pile up.
- Exit codes: `0` fine · `1` finished with problems · `2` nothing recorded · `3` could not start.
- `>> ... 2>&1` so failures land in the log rather than an email nobody reads.
- Not a `wget` to a URL: that inherits HTTP timeouts and creates a public endpoint needing protection.
- Confirm the host's minimum cron interval before assuming `*/10` is honoured. Most allow every
  minute; some enforce a 5 or 15 minute floor. A 15-minute floor is still fine for this product.

### Memory

`app/bin/extract.php` streams payloads one at a time precisely so `--limit` does not drive memory
([D19](decisions.md)) — the batching version needed ~300MB at `--limit=2000` and died on the default
128MB. Verified to complete a full 2000-row batch under `memory_limit=128M`. If the host is tighter
than that, lower `--limit`; it changes throughput, not peak memory.

## 6. The web app

Point the document root at `public/` and open it. Nothing else is required — no build step, no asset
pipeline, no `npm`.

`public/.htaccess` sets a strict Content-Security-Policy, blocks direct requests for `bootstrap.php`
and any stray `.sql`/`.md`/`.log`, disables directory listings, and refuses anything but GET and
HEAD. **On nginx none of that applies** — put the equivalents in the server block, and at minimum
deny `bootstrap.php` and everything outside `public/`.

Until the first scores land, every page shows an honest empty state naming what is missing rather
than an empty chart.

## 7. Confirm it is recording

Wait twenty minutes, then:

```sql
SELECT count(*) samples, min(fetched_at) first, max(fetched_at) latest FROM raw_samples;
SELECT endpoint, http_status, count(*) FROM fetch_log GROUP BY 1, 2;
SELECT count(*) FROM scores WHERE scope = 'market';
```

Two consecutive runs landing with no unexpected statuses, and a score row for each, is the milestone.
`app/bin/health.php` says the same thing in one command.

## 8. The MCP server

Nothing to deploy — it runs on demand over stdio. Point a client at it:

```json
{ "mcpServers": { "divergence": { "command": "php", "args": ["/home/USER/divergence/app/mcp/server.php"] } } }
```

It reads the same config file and the same queries as the web app. No tool takes a write action.

## 9. Ongoing checks

- `app/bin/health.php` — per-endpoint success rate, cadence, longest gap, extraction lag, and credits
  against the real limit reported by `/v1/key/info`. Exits non-zero when nothing has landed recently,
  so it doubles as a watchdog:

  ```
  */30 * * * * /usr/local/bin/php /home/USER/divergence/app/bin/health.php --stale=1800 || mail -s "divergence stalled" you@example.com
  ```

- Credit consumption against the 15,000/month budget, from `fetch_log`.
- Log size; rotate if the host does not.

## Changing the method after deployment

Weights, ranges and inputs live in `app/scoring/inputs.php`. To change one:

1. Edit the declaration and bump `METHOD_VERSION`.
2. `php app/bin/score.php --rebuild`

The whole history is rescored from payloads already stored, the old series stays under its own
version, and the method page updates itself because it renders from the same declaration. Changing
the *parsing* works the same way: bump `EXTRACTOR_VERSION` and run `php app/bin/extract.php --rebuild`.

## Rollback

The poller is append-only and the web app is read-only, so a bad deploy cannot corrupt history. If
the poller breaks, restore the previous version; the gap it left shows up as a gap. Derived tables
are always rebuildable from `raw_samples`, so there is no state worth backing up beyond the database
itself.
