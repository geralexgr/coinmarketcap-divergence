# sql/

Forward-only migrations, numbered. Empty until day 1.

| File | Contents |
|---|---|
| `001_init.sql` | `raw_samples`, `fetch_log` — the minimum needed to start recording |
| `002_metrics.sql` | `market_metric`, `asset_metric`, `asset_universe` |
| `003_scores.sql` | `scores` |

Draft shapes and the reasoning for each column: [`../docs/data-model.md`](../docs/data-model.md).

Day 1 only needs `001`. Do not design the full schema before something is recording.
