# public/

The document root — the **only** web-served directory. Read-only against the database. No write
endpoint exists anywhere in the web app; the only writer in the system is cron.

## Planned files

| File | Screen |
|---|---|
| `index.php` | Market — quadrant, trail, readout, per-asset table |
| `assets.php` | Assets — the full screener, one asset detail view |
| `leadlag.php` | Lead and lag, only if the recorded history supports an honest version |
| `method.php` | Method — formulas, weights, normalisation switchover, endpoints, recording health |
| `api/series.php` | JSON the charts read: scores over a window, gaps marked as gaps |
| `assets/app.js` | chart rendering, window selector, table sorting |
| `assets/app.css` | the tokens in `../docs/ui-spec.md` |

Spec: [`../docs/ui-spec.md`](../docs/ui-spec.md) · Mockup: [`../docs/mockups/`](../docs/mockups/)

Two non-negotiables on every screen: each number carries its endpoint and sample minute, and no
sentence points at the future.
