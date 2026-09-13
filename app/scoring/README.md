# scoring/

Rows in, scores out.

| File | Job |
|---|---|
| `inputs.php` | **the method, as data** — every input, its endpoint, range, weight and rationale |
| `normalise.php` | raw value → 0–100, under a named basis |
| `score.php` | divergence, the quadrant, and the sentence that describes it |
| `recompute.php` | the only file here that touches the database |

`inputs.php` is the interesting one. The method is declared rather than implemented: the scorer
runs from that declaration, the public method page renders from it, and `docs/method.md` is written
from it. There is one description of the method and the code is it.

Two properties that matter more than the weights:

1. **Missing inputs are dropped, not zeroed**, and the surviving weights renormalise. Ten of the
   twenty endpoints in the catalogue are 403 on this key, so this path runs
   on every sample. A zero would read as a measurement of a quiet market; absence is a measurement
   of nothing.
2. **A plan upgrade needs no code.** Forbidden inputs stay declared at their intended weights and
   are gated on the measured access table in `../lib/endpoints.php`. One verification run turns them
   on.

Everything except `recompute.php` is pure — no clock, no database, no network — which is what makes
it testable against fixtures and makes a rebuild order-independent.

## Rules

1. `../docs/method.md` and this code must agree. If they disagree, the code is right and the doc is
   a bug.
2. Every score row records the `basis` and `method_version` that produced it. Changing weights bumps
   the version rather than silently rewriting the past.
3. `bin/score.php --rebuild` must be able to rescore the entire history — that is the whole reason
   raw payloads are stored.
4. Normalisation is the part that silently goes wrong. It has fixtures in `../tests/scoring_test.php`,
   and most of them test the boundaries rather than the happy path.
