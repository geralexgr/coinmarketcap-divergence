# sql/

Forward-only migrations, numbered. Safe to re-run: every statement is `IF NOT EXISTS`.

| File | Contents | Status |
|---|---|---|
| `001_init.sql` | `raw_samples`, `fetch_log` — the minimum needed to start recording | **built** |
| `002_derived.sql` | `market_metric`, `asset_metric`, `asset_universe`, `scores` | planned |

```bash
mysql -u USER -p DB < sql/001_init.sql
```

`001` is everything that cannot be rebuilt later. The derived tables are disposable by design: if a
formula changes, truncate and recompute from `raw_samples`.

Column comments in `001_init.sql` are authoritative where `../docs/data-model.md` disagrees with
them — notably `payload`, which is `LONGTEXT` rather than a JSON column, because MySQL reparses JSON
columns on write and the stored payload has to stay byte-for-byte what CoinMarketCap sent.

Do not design the full schema before something is recording.
