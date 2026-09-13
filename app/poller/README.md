# poller/

The only component that writes source data. Runs from cron via PHP CLI, never over HTTP.

## Built

`run.php` — the whole recorder, one file.

```bash
php app/poller/run.php --market          # every 10 minutes, via cron
php app/poller/run.php --assets          # every 30 minutes, via cron
php app/poller/run.php --once            # market scope, verbose, for a human
php app/poller/run.php --once --dry-run  # fetch and report, write nothing
```

Exit codes: 0 all recorded · 1 some failed · 2 nothing recorded · 3 could not start.

It calls each endpoint in the catalogue for its scope, stores the response body verbatim with the
time it actually arrived, and logs the attempt either way. No parsing, no scoring, no normalisation:
those read from what this wrote and can be rewritten later. This cannot.

There is no file per fetcher. Every endpoint the recorder needs is described by data in
`app/lib/endpoints.php`, and one loop calls them — a fetcher per source would be five files that each
call `cmc_get` with a different path. The split happens when an endpoint needs handling the others
do not.

Reading what it stored is somebody else's job: `app/bin/extract.php` turns payloads into typed rows and
`app/bin/score.php` turns those into scores. Both are separate cron entries on offset minutes, because
neither costs credits and a slow derivation must never be able to delay a fetch.

## Rules

1. Write the raw payload **before** attempting extraction. If parsing throws, the sample is already
   banked. (This is why extraction is a separate pass over storage, not a step in the fetch.)
2. One `fetch_log` row per attempt, successes and failures alike.
3. Store the actual response timestamp, UTC. Never the cron schedule.
4. A failed endpoint does not abort the run; it logs and moves to the next one. A failed *write*
   does not either — the other endpoints in that tick are still recoverable history.
5. Short and stateless. No queue, no daemon, nothing held between runs — shared hosts kill
   long-running processes. A per-scope lock means an overrunning run makes the next tick skip
   rather than pile up.
