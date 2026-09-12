# scoring/

Pure functions: rows in, scores out. No fetching, no side effects beyond writing `scores`.

## Planned files

| File | Job |
|---|---|
| `normalise.php` | raw input → 0–100, under a named basis (`fixed` or `percentile`) |
| `voice.php` | weighted Voice score from its inputs |
| `money.php` | weighted Money score from its inputs |
| `divergence.php` | `money − voice`, plus the quadrant label |
| `recompute.php` | rebuild all scores from `raw_samples` for a given `method_version` |

## Rules

1. `../docs/method.md` is written first; this code follows it. If they disagree, one is a bug.
2. Every score row records the `basis` and `method_version` that produced it. Changing weights bumps
   the version rather than silently rewriting the past.
3. `recompute.php` must be able to rebuild the entire history — that is the whole reason raw
   payloads are stored.
4. Normalisation is the part that silently goes wrong. It gets fixtures in `../tests/`.
