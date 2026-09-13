# tests/

Not a coverage exercise. Tests exist here for the parts that fail silently.

    php tests/run.php            # all of them
    php tests/run.php extract    # just the extraction ones

No framework, no composer, no network, no database — because these have to be runnable on the
shared host, which is where it matters, and a dependency is one more thing that host has to
satisfy first. `tests/run.php` is the whole harness; tests register cases with `test()`.

## What is worth testing

1. **Normalisation** — fixed-range and percentile paths, boundary and out-of-range inputs. This is
   the code most likely to be quietly wrong while looking plausible on a chart. **Not written yet**;
   the scoring layer does not exist.
2. **Extraction** — ✅ 21 cases in `extract_test.php`. The ones that earn their place are the
   silent failures: an error body that is valid JSON and would otherwise parse as data, a division
   by a zero market cap, an absent field being quietly stored as a zero. A zero plots as a reading;
   a gap must plot as a gap.
3. **Quadrant boundaries** — exactly at 50 on either axis.
4. **Gap handling** — a series with a hole in it must come out with the hole intact, not smoothed.

## Fixtures

`tests/fixtures/synthetic/` holds hand-written payloads in the documented response shapes, so the
extractor could be built before a key existed. They are the right shape; they are **not** proof the
real one matches.

`tests/fixtures/live/` is written by `bin/verify-endpoints.php --save-fixtures` on day 1 — free at
that moment and annoying to obtain later. Re-point the extraction tests at it as the first thing
after the key arrives: every failure is a place where the documentation and the API disagree.

Keep both. A synthetic fixture is still the cheapest way to test an edge case the live payload
happens not to contain.
