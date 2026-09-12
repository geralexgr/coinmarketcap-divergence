# tests/

Not a coverage exercise. Tests exist here for the parts that fail silently.

## What is worth testing

1. **Normalisation** — fixed-range and percentile paths, boundary and out-of-range inputs. This is
   the code most likely to be quietly wrong while looking plausible on a chart.
2. **Extraction** — saved real payloads as fixtures, asserting the fields we depend on are pulled
   out correctly. Also the regression net for when CMC changes a response shape.
3. **Quadrant boundaries** — exactly at 50 on either axis.
4. **Gap handling** — a series with a hole in it must come out with the hole intact, not smoothed.

## Fixtures

`tests/fixtures/` holds real (trimmed) CMC responses captured during endpoint verification. Capture
them on day 1 while making the verification calls — they are free then and annoying to obtain later.
