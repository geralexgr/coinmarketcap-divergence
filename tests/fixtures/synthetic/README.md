# tests/fixtures/synthetic

**These are not real CoinMarketCap responses.** They were written by hand on 13 September 2026,
before a key existed, from the documented response shapes — so that the extractor could be built
and tested against *something* rather than against nothing.

They are the right shape. They are not proof the real one matches.

## What replaces them

`bin/verify-endpoints.php --save-fixtures` writes real payloads to `../live/` on day 1. When that
happens:

1. Point the extraction tests at `live/` instead of `synthetic/` and run them.
2. Anything that fails is a place where the documented shape and the real one disagree — fix the
   extractor, bump `EXTRACTOR_VERSION`, and re-run `bin/extract.php --rebuild`.
3. Keep these files. A synthetic fixture is still the cheapest way to test an edge case the live
   payload happens not to contain — a zero market cap, an error body, a missing quote block.

`error_system_busy.json` is the trap from `bin/README.md`: CMC answers an unknown path with HTTP
200 and `error_code: 500`. Any code that reads a payload has to reject it on the status block, not
on the HTTP status.
