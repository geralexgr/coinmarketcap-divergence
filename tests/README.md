# tests/

Not a coverage exercise. Tests exist here for the parts that fail silently.

    php tests/run.php            # all of them
    php tests/run.php extract    # just the extraction ones

No framework, no composer, no network, no database — because these have to be runnable on the
shared host, which is where it matters, and a dependency is one more thing that host has to
satisfy first. `tests/run.php` is the whole harness; tests register cases with `test()`.

## What is worth testing

1. **Normalisation** — fixed-range and percentile paths, boundaries, out-of-range inputs, and the
   basis switchover. The code most likely to be quietly wrong while looking plausible on a chart.
   `scoring_test.php`, and most of its cases test edges rather than the happy path.
2. **Extraction** — `extract_test.php`. The cases that earn their place are the silent failures: an
   error body that is valid JSON and would otherwise parse as data, a division by a zero market cap,
   an absent field being stored as a zero. A zero plots as a reading; a gap must plot as a gap.
3. **A missing input must never be scored as zero.** Ten of seventeen endpoints are 403 on this plan,
   so this path runs on every sample the product will ever record.
4. **Quadrant boundaries** — exactly 50 on either axis, and consistently on one side of it.
5. **Nothing may read the future.** A percentile or a value "as of" a moment that included later
   samples would make a recomputed history differ from the one that would have been computed live.
6. **No sentence in the readout points at the future.** The product's one hard rule, enforced by
   grepping the generated copy rather than by remembering.

## Fixtures

`tests/fixtures/synthetic/` holds hand-written payloads in the documented response shapes, so the
extractor could be built before a key existed. They are the right shape; they are **not** proof the
real one matches.

`tests/fixtures/live/` is written by `bin/verify-endpoints.php --save-fixtures` — free at that
moment and annoying to obtain later. `live_test.php` runs the extractor against those payloads and
skips cleanly when they are absent, which is why they are gitignored. It has already caught one real
bug: the historical fear-and-greed list arrives newest-first and the extractor had been taking the
oldest point.

Keep both. A synthetic fixture is still the cheapest way to test an edge case the live payload
happens not to contain.
