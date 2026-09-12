# lib/

Shared plumbing. Small, boring, no framework.

| File | Job |
|---|---|
| `config.php` | loads the real config from outside the webroot, fails loudly if absent |
| `db.php` | PDO connection, prepared statements only |
| `http.php` | CMC client: timeouts, retry with backoff, rate limiting, credit header parsing |
| `log.php` | append-only logging to the path from config |

The 30 requests/minute limit and the credit accounting live in `http.php`, enforced once, rather
than in each fetcher.
