# MCP tool surface

Planned, not built. Deliberately last — see decision D8. Everything here is a thin wrapper over
queries the web app already needs, so this costs little once the scores exist.

The same rule applies as everywhere else: tools return measurements, never advice. A tool that
answered "should I..." would not belong here.

## Tools

### `get_market_divergence`
Current Voice, Money and Divergence for the whole market, plus the quadrant label and the sample
timestamp.

Returns: `{ voice, money, divergence, quadrant, sampled_at, basis, method_version }`

### `get_asset_divergence`
Same three scores for one asset.

Params: `symbol` or `cmc_id`

### `screen_assets`
The per-asset table as data.

Params: `sort` (`gap` | `voice` | `money`), `quadrant` (optional filter), `limit`
Returns: rows of `{ symbol, voice, money, divergence, quadrant, sampled_at }`

### `get_divergence_history`
The trail, as a series.

Params: `scope` (`market` | `asset`), `symbol` (if asset), `window` (`24h` | `7d` | `all`)
Returns: samples of `{ sampled_at, voice, money, divergence }`, with recording gaps marked as gaps
rather than omitted silently.

### `get_quadrant_transitions`
When the market or an asset crossed from one quadrant to another, in recorded history.

Params: `scope`, `symbol`, `window`
Returns: `{ at, from, to }` — description of what happened, not what it means.

### `get_method`
The current weights, normalisation basis, switchover date, and inputs per axis. Exists so an agent
can explain where a number came from instead of asserting it.

### `get_recording_health`
Sample count, first sample, longest gap, failure rate. Exists so an agent can tell a user how much
history a given answer is based on.

## Conventions

- Every response carries `sampled_at` and `basis`. An agent that repeats a score without the time it
  was measured is misrepresenting it.
- Gaps are explicit. Never a smoothed series presented as continuous.
- No tool takes a write action. The only writer in the system is cron.
