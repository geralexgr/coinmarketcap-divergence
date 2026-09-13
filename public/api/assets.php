<?php
/**
 * The screener as data.
 *
 *     api/assets.php?sort=gap&quadrant=quiet_but_leveraged&limit=50
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');

[$pdo, $error] = web_connect();
if ($pdo === null) {
    http_response_code(503);
    echo json_encode(['error' => $error ?? 'The database is unreachable.'], JSON_PRETTY_PRINT);
    exit;
}

$sort = query_choice('sort', ['gap', 'voice', 'money', 'symbol', 'rank'], 'gap');
$quadrants = ['loud_and_leveraged', 'chatter_without_conviction', 'quiet_but_leveraged', 'apathy'];
$quadrant = query_choice('quadrant', $quadrants, '') ?: null;
$limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));

$rows = latest_asset_scores($pdo, METHOD_VERSION, $sort, $quadrant, $limit);

echo json_encode([
    'sort'           => $sort,
    'quadrant'       => $quadrant,
    'method_version' => METHOD_VERSION,
    // Per-asset scores rank each asset against the rest of the universe at one instant,
    // not against its own past. Stated in the response so a consumer cannot mistake
    // these for the market-wide series, which uses a different basis entirely.
    'basis'          => 'cross_section',
    'sampled_at'     => $rows === [] ? null : $rows[0]['sampled_at'],
    'count'          => count($rows),
    'assets'         => $rows,
    'note'           => 'Each score is a rank against the tracked universe at the sample time. Not advice, and not a forecast.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
