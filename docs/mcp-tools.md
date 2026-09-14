# MCP tool surface

`app/mcp/server.php` — JSON-RPC 2.0 over stdio. Seven tools, each a thin wrapper over `app/lib/queries.php`,
which is the same file the web app reads through: the two surfaces run the same SQL, so they cannot
drift into answering differently (D8).

```json
{ "mcpServers": { "divergence": { "command": "php", "args": ["/home/USER/divergence/app/mcp/server.php"] } } }
```

The same rule applies as everywhere else: tools return measurements, never advice. A tool that
answered "should I..." would not belong here.

## Tools

### `get_market_divergence`
Current Voice, Money and Divergence for the whole market, plus the quadrant label, the sample
timestamp, and the raw inputs in their native units with the endpoint each came from.

Returns: `{ voice, money, divergence, quadrant, quadrant_label, reading, sampled_at, basis,
method_version, voice_inputs, money_inputs, inputs }`

### `get_asset_divergence`
The same three scores for one asset.

Params: `asset` — a symbol (`BTC`) or a CoinMarketCap id (`1`)

Carries `basis_note`, because per-asset scores rank the asset against the rest of the universe at
the same instant rather than against its own past (D17), and an agent that did not know that would
describe the number wrongly.

### `screen_assets`
The per-asset table as data.

Params: `sort` (`gap` | `voice` | `money` | `symbol` | `rank`), `quadrant` (optional filter),
`band` (`all` | `top50` | `mid` | `deep` — a rank band by market cap), `limit`
Returns: rows of `{ cmc_id, symbol, name, rank_last, voice, money, divergence, quadrant, sampled_at }`

Sorting by `gap` returns much the same assets every day: an attention proxy that permanently
outruns turnover is a property of the asset, not news about it. Use `find_movers` for what changed.
The band filters which rows come back and changes no score — every asset is still ranked against
the whole cross-section.

### `find_movers`
The assets whose gap **moved** most between two recorded cross-sections, rather than the assets
whose gap is largest.

Params: `hours` (how far back the baseline is taken from, default 24), `only_crossings` (restrict to
assets that changed quadrant), `limit`
Returns: rows of `{ cmc_id, symbol, name, rank_last, voice, money, divergence, quadrant,
voice_then, money_then, divergence_then, quadrant_then, divergence_change, crossed, sampled_at,
sampled_at_then }`, alongside the `from` and `to` sample times.

Both ends of every comparison are carried on the row, so the change is checkable rather than
asserted, and every asset is measured between the *same* two cross-sections. An asset missing from
the baseline yields no row rather than a change measured against whatever was nearest — which would
report a recording gap as market movement. An empty result means no cross-section that old was
recorded, not that nothing moved.

A measurement of a past interval. It says nothing about the next one.

### `get_divergence_history`
The trail, as a series.

Params: `scope` (`market` | `asset`), `asset` (if scope is asset), `window` (`24h` | `7d` | `30d` | `all`)
Returns: samples of `{ sampled_at, voice, money, divergence, quadrant, basis }`, alongside a `gaps`
list. Gaps are returned explicitly rather than left to be inferred from the timestamps: a consumer
that plots this as a continuous line is drawing measurements that were never taken.

### `get_quadrant_transitions`
When the market or an asset crossed from one quadrant to another, in recorded history.

Params: `scope`, `asset`, `window`
Returns: `{ at, from, to, from_label, to_label }` — a description of what happened at a recorded
time. It carries no claim about what followed.

### `get_method`
Every input on both axes: endpoint, reference range, weight, and **whether the plan behind this
deployment can currently call it**. Exists so an agent can explain where a number came from instead
of asserting it — and so it knows that on the current plan the Voice axis is one input of three,
updated once a day, before stating a score with more confidence than it has earned.

### `get_recording_health`
Sample count, first sample, longest gap, failure rate. Exists so an agent can tell a user how much
history a given answer is based on.

## Conventions

- Every response carries `sampled_at` and `basis`. An agent that repeats a score without the time it
  was measured is misrepresenting it.
- Gaps are explicit. Never a smoothed series presented as continuous.
- No tool takes a write action. The only writer in the system is cron.
- A failing tool returns an error result rather than killing the server: the client keeps the
  session and the next call can succeed once the host recovers.
