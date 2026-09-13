<?php
/**
 * The JSON the charts read, and the same data as a public read-only API.
 *
 *     api/series.php?scope=market&window=7d
 *     api/series.php?scope=asset&asset=1027&window=24h
 *
 * Read-only, like everything else under public/. There is no write path in the web app
 * at all; the only writer in the system is cron.
 *
 * Recording gaps are returned as a list rather than left to be inferred from the
 * timestamps. A consumer that plots this without breaking the line at a gap is drawing
 * a measurement that was never taken, and making that easy to get right is the point of
 * shipping the gaps alongside the points.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');

/** @param array<string,mixed> $payload */
function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

[$pdo, $error] = web_connect();
if ($pdo === null) {
    respond(['error' => $error ?? 'The database is unreachable.'], 503);
}

$scope = ($_GET['scope'] ?? 'market') === 'asset' ? 'asset' : 'market';
$window = query_choice('window', ['24h', '7d', '30d', 'all'], '7d');

if ($scope === 'asset') {
    $reference = trim((string) ($_GET['asset'] ?? ''));
    $asset = $reference === '' ? null : asset_by_reference($pdo, $reference);
    if ($asset === null) {
        respond(['error' => 'Pass ?asset= a CoinMarketCap id or a symbol in the tracked universe.'], 404);
    }
    $series = asset_series($pdo, METHOD_VERSION, (int) $asset['cmc_id'], $window);
} else {
    $asset = null;
    $series = market_series($pdo, METHOD_VERSION, $window);
}

respond([
    'scope'          => $scope,
    'asset'          => $asset === null ? null : ['cmc_id' => (int) $asset['cmc_id'], 'symbol' => $asset['symbol'], 'name' => $asset['name']],
    'window'         => $window,
    'method_version' => METHOD_VERSION,
    'divergence'     => 'money - voice',
    'count'          => count($series),
    // Explicit, never smoothed over. See the note at the top of this file.
    'gaps'           => gaps_in($series),
    'transitions'    => quadrant_transitions($series),
    'series'         => $series,
    'note'           => 'Every point is a measurement of a condition at the recorded sample time. Nothing here is a forecast.',
]);
