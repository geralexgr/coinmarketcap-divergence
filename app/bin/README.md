# bin/

Operational scripts. Never web-reachable.

| File | Job | Needs a key? |
|---|---|---|
| `probe-paths.php` | which API paths exist at all, by the 401/404 split | no |
| `verify-endpoints.php` | which of those the plan permits, with payload shapes | yes |
| `preflight.php` | can this host run the poller — PHP, extensions, HTTPS, MySQL, grants | optional |
| `health.php` | is it recording *and* is it being extracted: success rate, cadence, longest gap, credits, extraction lag | no |
| `extract.php` | reads stored payloads into the typed tables. Costs no credits, touches no network | no |
| `score.php` | turns typed rows into Voice, Money and Divergence. Costs no credits, touches no network | no |

Order on a fresh host: `preflight` → `verify-endpoints` → migrate → `poller/run.php --once` →
`extract` → `score` → `health`.

`health.php` is the one that matters day to day, and it exits non-zero when nothing has landed
recently *or* when extraction has fallen more than an hour behind, so it doubles as a cron
watchdog for both crons. A dead poller loses history that cannot be recovered; a dead extractor
loses nothing but silently freezes every chart. Both should be noticed the same day, not at
submission time.

`extract.php` is safe to run while the poller is running — it only ever reads `raw_samples`. A
parsing fix does not need a separate backfill script: bump `EXTRACTOR_VERSION` in `app/lib/extract.php`
and run `php app/bin/extract.php --rebuild`, which re-reads every payload ever stored and updates the
derived rows in place rather than doubling the series.

`score.php` is the same shape as `extract.php` and for the same reason. Change a weight or a range
in `app/scoring/inputs.php`, bump `METHOD_VERSION`, run `php app/bin/score.php --rebuild`, and the whole
history is rescored from rows already stored. The old series stays under its own version rather than
being overwritten, so a weighting change is visible in the data instead of quietly rewriting the past.

## Why two separate endpoint scripts

They answer different questions and only one of them needs a key.

`probe-paths.php` maps what exists. CoinMarketCap resolves the path *before* it validates the key,
so an invalid key returns 401 on a real path and 404 on a fake one — the whole surface can be mapped
for free. It carries two control paths that must read as absent on every run, because CMC answers an
unknown path with **HTTP 200** and `error_code: 500` "The system is busy". Judging that response by
its HTTP status alone briefly recorded six non-existent derivatives endpoints as working.

`verify-endpoints.php` then answers the separate question of what the plan permits, and costs about
6 credits. Its results are encoded in `endpoint_access_results()` in `app/lib/endpoints.php`, which is
the single table that stops the poller scheduling a 403 and stops the scoring layer offering an
input it cannot get.
