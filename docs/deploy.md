# Deploy runbook

Target: cPanel shared hosting, PHP + MySQL, real cron via PHP CLI.

Written before first deployment, so every step is an expectation until it has been done once —
correct this file the first time it is followed, and note anything the host does differently.

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
- Confirm MySQL 5.7+ for JSON column support; if older, raw payloads go in `LONGTEXT`
- `mysql -u USER -p DB < sql/001_init.sql`

## 3. Config

```bash
cp divergence/config.example.php /home/USER/config.php
# fill in the API key and DB credentials
chmod 600 /home/USER/config.php
```

Verify it is not web-reachable: requesting it over HTTP must 404.

## 4. Verify PHP CLI can reach the API

Before the cron entry exists. From an SSH session on the host, not a laptop:

```bash
/usr/local/bin/php -v
/usr/local/bin/php /home/USER/divergence/poller/run.php --once
```

The `--once` run prints what it fetched and what it wrote. If outbound HTTPS is blocked for CLI,
this is where it surfaces — see `open-questions.md` item 5.

## 5. Cron

cPanel → Cron Jobs. Two entries.

```
*/5  * * * * /usr/local/bin/php /home/USER/divergence/poller/run.php --market >> /home/USER/logs/divergence.log 2>&1
*/15 * * * * /usr/local/bin/php /home/USER/divergence/poller/run.php --assets >> /home/USER/logs/divergence.log 2>&1
```

Notes:
- Absolute paths for both the binary and the script. Cron's `PATH` is not a login shell's.
- `>> ... 2>&1` so failures land in the log rather than in an email nobody reads.
- Not a `wget` to a URL: that inherits HTTP timeouts and creates a public endpoint needing
  protection.
- Confirm the host's minimum interval before assuming `*/5` is honoured.

## 6. Confirm it is recording

Wait ten minutes, then:

```sql
SELECT count(*) AS samples, min(fetched_at) AS first, max(fetched_at) AS latest FROM raw_samples;
SELECT endpoint, http_status, count(*) FROM fetch_log GROUP BY 1, 2;
```

Two consecutive runs landing, with no unexpected statuses, is the day-1 milestone.

## 7. Ongoing checks

- `bin/health.php` — rows/hour, longest gap, failure rate
- Credit consumption against the 300,000/month budget, from `fetch_log`
- Log size; rotate if the host does not

## Rollback

The poller is append-only and the web app is read-only, so a bad deploy cannot corrupt history. If
the poller breaks, the previous version is restored and the gap it left shows up as a gap. Derived
tables are always rebuildable from `raw_samples`, so there is no state worth backing up separately
beyond the database itself.
