# bin/

One-off and operational scripts. Never web-reachable.

| File | Job |
|---|---|
| `health.php` | recording health: rows/hour, longest gap, failure rate, credits used this month |
| `verify_endpoints.php` | hits every endpoint once, prints status + credits — fills in `../docs/endpoint-access.md` |
| `backfill_extract.php` | re-runs extraction over stored raw payloads after a parsing fix |
| `recompute_scores.php` | rebuilds `scores` at a new `method_version` |

`health.php` is the one that matters day to day. A dead poller should be noticed the same day, not
at submission time.
