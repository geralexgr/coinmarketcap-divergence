# bin/

Operational scripts. Never web-reachable.

## Built

| File | Job | Needs a key? |
|---|---|---|
| `probe-paths.php` | which API paths exist at all, by the 401/404 split | no |
| `verify-endpoints.php` | which of those the plan permits, with payload shapes | yes |
| `preflight.php` | can this host run the poller — PHP, extensions, HTTPS, MySQL, grants | optional |
| `health.php` | is it recording: success rate, cadence, longest gap, credits | no |

Order on a fresh host: `preflight` → `verify-endpoints` → migrate → `poller/run.php --once` →
`health`.

`health.php` is the one that matters day to day, and it exits non-zero when nothing has landed
recently, so it doubles as a cron watchdog. A dead poller should be noticed the same day, not at
submission time.

## Planned

| File | Job |
|---|---|
| `backfill-extract.php` | re-runs extraction over stored raw payloads after a parsing fix |
| `recompute-scores.php` | rebuilds `scores` at a new `method_version` |

## Why two separate endpoint scripts

They answer different questions and only one of them needs a key.

`probe-paths.php` maps what exists. CoinMarketCap resolves the path *before* it validates the key,
so an invalid key returns 401 on a real path and 404 on a fake one — the whole surface can be mapped
for free. It carries two control paths that must read as absent on every run, because CMC answers an
unknown path with **HTTP 200** and `error_code: 500` "The system is busy". Judging that response by
its HTTP status alone briefly recorded six non-existent derivatives endpoints as working.

`verify-endpoints.php` then answers the separate question of what the plan permits, and costs about
20 credits.
