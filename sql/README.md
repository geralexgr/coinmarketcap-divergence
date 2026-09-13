# sql/

Forward-only migrations, numbered. Safe to re-run: every statement is `IF NOT EXISTS`.

| File | Contents | Status |
|---|---|---|
| `001_init.sql` | `raw_samples`, `fetch_log` — the minimum needed to start recording | **built** |
| `002_derived.sql` | `market_metric`, `asset_metric`, `asset_universe`, `extraction_log`, `scores` | **built** |

```bash
mysql -u USER -p DB < sql/001_init.sql
mysql -u USER -p DB < sql/002_derived.sql
```

`001` is everything that cannot be rebuilt later. The derived tables are disposable by design: if a
formula changes, truncate and recompute from `raw_samples`.

Column comments in `001_init.sql` are authoritative where `../docs/data-model.md` disagrees with
them — notably `payload`, which is `LONGTEXT` rather than a JSON column, because MySQL reparses JSON
columns on write and the stored payload has to stay byte-for-byte what CoinMarketCap sent.

Apply `001` and start recording even if anything about `002` goes wrong. The derived tables are
rebuildable from raw payloads; the fetching is the half that cannot be caught up on later.
