# lib/

Shared plumbing. Small, boring, no framework.

| File | Job |
|---|---|
| `config.php` | loads the real config from outside the webroot, fails loudly if absent; `redact()` for anything that might print a key |
| `db.php` | PDO connection pinned to UTC, the two append-only writers, a schema presence check |
| `http.php` | CMC client, response classification, rate limiting |
| `endpoints.php` | the endpoint catalogue — one list shared by the prober, the verifier and the poller |

## `cmc_outcome()` is the important one

Never judge a CMC response by its HTTP status alone. Two behaviours make the naive reading wrong:

1. An unknown path under any version prefix answers **HTTP 200** with `status.error_code: 500`,
   "The system is busy, please try again later!". Reading the status alone recorded six
   non-existent derivatives endpoints as working.
2. Path resolution happens before key validation, so an invalid key returns 401 on a real path and
   404 on a fake one. That is what lets `bin/probe-paths.php` map the API with no key at all.

`cmc_outcome()` reads the body's error code as well as the status and returns one of `ok`,
`unauthorized`, `forbidden`, `not_found`, `bad_request`, `rate_limited`, `server_error`,
`no_response`. `cmc_path_exists()` reduces that to the existence question.

The 30 requests/minute limit lives in `RateLimiter`, enforced once, rather than in each caller. It
spaces calls evenly rather than bursting and sleeping, because a shared host will kill a long
sleeping process.

## Logging

There is no `log.php`. The poller's logger is a closure over the config so that cron output and the
log file are the same lines. If a second component needs logging, that is the point at which it
becomes a file.
