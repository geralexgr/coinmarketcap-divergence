# poller/

The only component that writes source data. Runs from cron via PHP CLI, never over HTTP.

## Planned files

| File | Job |
|---|---|
| `run.php` | entry point. Flags: `--market`, `--assets`, `--once` (fetch, print, exit) |
| `fetch_fear_greed.php` | one fetcher per logical source, each returning a raw response |
| `fetch_community.php` | |
| `fetch_derivatives.php` | funding, OI, liquidations |
| `fetch_universe.php` | top ~100 listing, maintains `asset_universe` |
| `extract.php` | reads stored raw payloads → typed metric rows. Re-runnable over all history |

## Rules

1. Write the raw payload **before** attempting extraction. If parsing throws, the sample is already
   banked.
2. One `fetch_log` row per attempt, successes and failures alike.
3. Store the actual response timestamp, UTC. Never the cron schedule.
4. A failed endpoint does not abort the run; it logs and moves to the next one.
5. Short and stateless. No queue, no daemon, nothing held between runs — shared hosts kill
   long-running processes.

Day 1 builds the crudest version of this that records. See `../TOMORROW.md`.
