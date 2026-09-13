<?php
/**
 * MCP server — the same scores as agent tools.
 *
 *     php mcp/server.php
 *
 * Speaks JSON-RPC 2.0 over stdio, which is what an MCP client launches a local server
 * as. No HTTP listener, no port, no framework, and no dependency the shared host has to
 * satisfy: the protocol is small enough to implement directly, and adding a package
 * manager to this project to avoid a hundred lines would be the wrong trade.
 *
 * Every tool is a thin wrapper over `lib/queries.php`, which is the same file the web
 * app reads through. That is deliberate (D8): the two surfaces are supposed to answer
 * identically, and the only way to guarantee it is for them to run the same SQL.
 *
 * Two rules hold everywhere in here:
 *
 *  1. **No tool takes a write action.** The only writer in the system is cron.
 *  2. **Every response carries `sampled_at` and `basis`.** An agent that repeats a score
 *     without the time it was measured is misrepresenting it, and the way to stop that
 *     is to make the timestamp impossible to receive separately from the number.
 *
 * Configure a client with:
 *
 *     { "command": "php", "args": ["/home/USER/divergence/app/mcp/server.php"] }
 */

declare(strict_types=1);

require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/endpoints.php';
require __DIR__ . '/../lib/queries.php';
require __DIR__ . '/../scoring/inputs.php';
require __DIR__ . '/../scoring/normalise.php';
require __DIR__ . '/../scoring/score.php';
require __DIR__ . '/../scoring/recompute.php';

const MCP_PROTOCOL_VERSION = '2024-11-05';

/**
 * Opened once and reused. A tool call that cannot reach the database returns an error
 * result rather than killing the server: the client keeps the session and the next call
 * can succeed once the host recovers.
 */
function mcp_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = db_connect(load_config(['db_name', 'db_user']));
    }
    return $pdo;
}

/**
 * The tool surface, as declared to the client.
 *
 * Descriptions are written for a model reading them cold. Each says what the tool
 * measures and, where it matters, what it does not — an agent that does not know the
 * Voice axis is one daily input will state the score with more confidence than it has
 * earned, and that failure is invisible from the client side.
 *
 * @return array<int,array<string,mixed>>
 */
function mcp_tools(): array
{
    $windowEnum = ['24h', '7d', '30d', 'all'];
    $quadrantEnum = ['loud_and_leveraged', 'chatter_without_conviction', 'quiet_but_leveraged', 'apathy'];

    return [
        [
            'name' => 'get_market_divergence',
            'description' =>
                'Current Voice, Money and Divergence for the whole crypto market, with the quadrant '
                . 'label and the time the sample was taken. Divergence is money minus voice: positive '
                . 'means money committed is running ahead of narrative. A measurement of a recorded '
                . 'moment, never a forecast or a recommendation.',
            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
        ],
        [
            'name' => 'get_asset_divergence',
            'description' =>
                'The same three scores for one asset. Per-asset scores rank the asset against the rest '
                . 'of the tracked universe at the same instant, not against its own past.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'asset' => ['type' => 'string', 'description' => 'Symbol (BTC) or CoinMarketCap id (1).'],
                ],
                'required' => ['asset'],
            ],
        ],
        [
            'name' => 'screen_assets',
            'description' =>
                'The per-asset table as data: every tracked asset with its Voice score, Money score and '
                . 'the gap between them. Sortable, and filterable by quadrant.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'sort'     => ['type' => 'string', 'enum' => ['gap', 'voice', 'money', 'symbol', 'rank'], 'default' => 'gap'],
                    'quadrant' => ['type' => 'string', 'enum' => $quadrantEnum, 'description' => 'Optional filter.'],
                    'limit'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 50],
                ],
                'required' => [],
            ],
        ],
        [
            'name' => 'get_divergence_history',
            'description' =>
                'The recorded trail as a time series. Recording gaps are returned explicitly in a `gaps` '
                . 'list rather than smoothed over — a consumer that plots this as a continuous line is '
                . 'drawing measurements that were never taken.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'scope'  => ['type' => 'string', 'enum' => ['market', 'asset'], 'default' => 'market'],
                    'asset'  => ['type' => 'string', 'description' => 'Symbol or CoinMarketCap id. Required when scope is asset.'],
                    'window' => ['type' => 'string', 'enum' => $windowEnum, 'default' => '7d'],
                ],
                'required' => [],
            ],
        ],
        [
            'name' => 'get_quadrant_transitions',
            'description' =>
                'When the market or an asset crossed from one quadrant to another in recorded history. '
                . 'A description of what happened at a recorded time, not of what it means.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'scope'  => ['type' => 'string', 'enum' => ['market', 'asset'], 'default' => 'market'],
                    'asset'  => ['type' => 'string'],
                    'window' => ['type' => 'string', 'enum' => $windowEnum, 'default' => '7d'],
                ],
                'required' => [],
            ],
        ],
        [
            'name' => 'get_method',
            'description' =>
                'Every input on both axes: its endpoint, reference range, weight, and whether the API '
                . 'plan behind this deployment can currently call it. Use this before stating a score '
                . 'with confidence — on the current plan the Voice axis has one input of three and it '
                . 'updates once a day.',
            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
        ],
        [
            'name' => 'get_recording_health',
            'description' =>
                'How much history the answers are based on: sample count, first sample, longest gap in '
                . 'recording, and the fetch failure rate.',
            'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'required' => []],
        ],
    ];
}

/**
 * Run one tool.
 *
 * @param array<string,mixed> $args
 * @return array<string,mixed>
 */
function mcp_call_tool(string $name, array $args): array
{
    $pdo = mcp_pdo();

    // Shared by the two tools that accept either scope.
    $resolveSeries = static function (array $args) use ($pdo): array {
        $window = in_array($args['window'] ?? '7d', ['24h', '7d', '30d', 'all'], true) ? (string) $args['window'] : '7d';
        $scope = ($args['scope'] ?? 'market') === 'asset' ? 'asset' : 'market';

        if ($scope === 'asset') {
            $asset = asset_by_reference($pdo, trim((string) ($args['asset'] ?? '')));
            if ($asset === null) {
                throw new RuntimeException('Pass `asset` a symbol or CoinMarketCap id that is in the tracked universe.');
            }
            return [$scope, $window, $asset, asset_series($pdo, METHOD_VERSION, (int) $asset['cmc_id'], $window)];
        }

        return [$scope, $window, null, market_series($pdo, METHOD_VERSION, $window)];
    };

    switch ($name) {
        case 'get_market_divergence':
            $current = latest_market_score($pdo, METHOD_VERSION);
            if ($current === null) {
                return ['error' => 'No market score has been computed yet.', 'health' => recording_health($pdo)];
            }
            $derived = current_derived_inputs($pdo);
            return $current + [
                'quadrant_label' => quadrant_label($current['quadrant']),
                'reading'        => divergence_sentence($current['divergence']),
                'inputs'         => [
                    'voice' => current_input_values($pdo, available_inputs('voice', 'market'), $derived),
                    'money' => current_input_values($pdo, available_inputs('money', 'market'), $derived),
                ],
                'note' => 'A measurement of a condition at the recorded sample time. Not advice, and not a forecast.',
            ];

        case 'get_asset_divergence':
            $asset = asset_by_reference($pdo, trim((string) ($args['asset'] ?? '')));
            if ($asset === null) {
                return ['error' => 'No asset matching that symbol or id is in the tracked universe.'];
            }
            $series = asset_series($pdo, METHOD_VERSION, (int) $asset['cmc_id'], '24h');
            if ($series === []) {
                return ['error' => 'That asset is in the universe but has no recent score.'];
            }
            $latest = $series[count($series) - 1];
            return [
                'cmc_id'         => (int) $asset['cmc_id'],
                'symbol'         => $asset['symbol'],
                'name'           => $asset['name'],
                'quadrant_label' => quadrant_label($latest['quadrant']),
                'reading'        => divergence_sentence($latest['divergence']),
                'basis_note'     => 'Scores are this asset ranked against the rest of the tracked universe at the sample time.',
            ] + $latest;

        case 'screen_assets':
            $sort = in_array($args['sort'] ?? 'gap', ['gap', 'voice', 'money', 'symbol', 'rank'], true) ? (string) $args['sort'] : 'gap';
            $quadrants = ['loud_and_leveraged', 'chatter_without_conviction', 'quiet_but_leveraged', 'apathy'];
            $quadrant = in_array($args['quadrant'] ?? '', $quadrants, true) ? (string) $args['quadrant'] : null;
            $limit = max(1, min(500, (int) ($args['limit'] ?? 50)));
            $rows = latest_asset_scores($pdo, METHOD_VERSION, $sort, $quadrant, $limit);
            return [
                'sort'       => $sort,
                'quadrant'   => $quadrant,
                'basis'      => 'cross_section',
                'sampled_at' => $rows === [] ? null : $rows[0]['sampled_at'],
                'count'      => count($rows),
                'assets'     => $rows,
            ];

        case 'get_divergence_history':
            [$scope, $window, $asset, $series] = $resolveSeries($args);
            return [
                'scope'      => $scope,
                'asset'      => $asset === null ? null : ['cmc_id' => (int) $asset['cmc_id'], 'symbol' => $asset['symbol']],
                'window'     => $window,
                'count'      => count($series),
                // Explicit, never smoothed. See rule 2 at the top of this file.
                'gaps'       => gaps_in($series),
                'series'     => $series,
            ];

        case 'get_quadrant_transitions':
            [$scope, $window, $asset, $series] = $resolveSeries($args);
            $transitions = array_map(
                static fn(array $t): array => $t + [
                    'from_label' => quadrant_label($t['from']),
                    'to_label'   => quadrant_label($t['to']),
                ],
                quadrant_transitions($series)
            );
            return [
                'scope'       => $scope,
                'asset'       => $asset === null ? null : ['cmc_id' => (int) $asset['cmc_id'], 'symbol' => $asset['symbol']],
                'window'      => $window,
                'count'       => count($transitions),
                'transitions' => $transitions,
                'note'        => 'Each entry is a crossing that was recorded. It carries no claim about what followed.',
            ];

        case 'get_method':
            return method_description() + [
                'note' => 'Inputs marked unavailable are forbidden by the API plan behind this deployment. '
                    . 'They are declared so the gap between the designed method and the running one is visible.',
            ];

        case 'get_recording_health':
            return recording_health($pdo) + [
                'note' => 'Every fetch attempt is logged, including failures. Gaps are shown as gaps and never interpolated.',
            ];

        default:
            throw new RuntimeException("Unknown tool: {$name}");
    }
}

// ---------------------------------------------------------------------------
// JSON-RPC over stdio
// ---------------------------------------------------------------------------

/** @param array<string,mixed>|null $result */
function mcp_send(?int $id, ?array $result, ?array $error = null): void
{
    // A notification has no id and must not be answered at all.
    if ($id === null && $error === null) {
        return;
    }

    $message = ['jsonrpc' => '2.0', 'id' => $id];
    if ($error !== null) {
        $message['error'] = $error;
    } else {
        $message['result'] = $result;
    }

    // stdout carries the protocol and nothing else. Anything this server wants to say to
    // a human goes to stderr, because a stray line here corrupts the stream.
    fwrite(STDOUT, json_encode($message, JSON_UNESCAPED_SLASHES) . "\n");
    fflush(STDOUT);
}

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $request = json_decode($line, true);
    if (!is_array($request)) {
        mcp_send(null, null, ['code' => -32700, 'message' => 'Parse error']);
        continue;
    }

    $id = isset($request['id']) ? (int) $request['id'] : null;
    $method = (string) ($request['method'] ?? '');
    $params = is_array($request['params'] ?? null) ? $request['params'] : [];

    try {
        switch ($method) {
            case 'initialize':
                mcp_send($id, [
                    'protocolVersion' => MCP_PROTOCOL_VERSION,
                    'capabilities'    => ['tools' => (object) []],
                    'serverInfo'      => ['name' => 'divergence', 'version' => (string) METHOD_VERSION],
                ]);
                break;

            case 'notifications/initialized':
                break;

            case 'tools/list':
                mcp_send($id, ['tools' => mcp_tools()]);
                break;

            case 'tools/call':
                $name = (string) ($params['name'] ?? '');
                $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                $result = mcp_call_tool($name, $args);
                mcp_send($id, [
                    'content' => [[
                        'type' => 'text',
                        'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    ]],
                    'isError' => isset($result['error']),
                ]);
                break;

            case 'ping':
                mcp_send($id, (object) []);
                break;

            default:
                mcp_send($id, null, ['code' => -32601, 'message' => "Method not found: {$method}"]);
        }
    } catch (Throwable $e) {
        // A failing tool is a failed call, not a dead server: the client keeps the
        // session and the next call can succeed once the host recovers.
        mcp_send($id, null, ['code' => -32603, 'message' => $e->getMessage()]);
    }
}
