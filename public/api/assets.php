<?php
/**
 * The screener as data.
 *
 *     api/assets.php?sort=gap&quadrant=quiet_but_leveraged&limit=50
 *     api/assets.php?band=mid                  # ranks 51-200 only
 *     api/assets.php?view=movers&hours=24      # ranked by change in gap, not size of gap
 *     api/assets.php?view=crossings&hours=168  # only those that changed quadrant
 *
 * `view=movers` answers the question the default sort cannot: the largest gaps are
 * largely the same assets every day, because a permanent property of an asset is not
 * news about it. See `asset_divergence_movers()`.
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
// Excluded by default, matching the screener. ?stablecoins=1 includes them.
$withStables = ($_GET['stablecoins'] ?? '') === '1';

// Rank bands. A filter on which rows are returned, never on how they were scored:
// every asset is ranked against the whole recorded cross-section either way (D23).
$bands = [
    ''      => [null, null],
    'top50' => [1, 50],
    'mid'   => [51, 200],
    'deep'  => [101, 200],
];
$band = query_choice('band', array_keys($bands), '');
[$minRank, $maxRank] = $bands[$band];

$view  = query_choice('view', ['screener', 'movers', 'crossings'], 'screener');
$hours = max(1, min(24 * 30, (int) ($_GET['hours'] ?? 24)));

$window = null;
if ($view === 'movers' || $view === 'crossings') {
    $rows = $view === 'crossings'
        ? asset_quadrant_crossings($pdo, METHOD_VERSION, $hours, $limit, $withStables)
        : asset_divergence_movers($pdo, METHOD_VERSION, $hours, $limit, $withStables);

    // Both ends of every comparison, so a consumer can check the change rather than
    // trust it — and so an empty result is legibly "no cross-section that old" rather
    // than "nothing moved".
    $window = [
        'hours'        => $hours,
        'from'         => $rows === [] ? null : $rows[0]['sampled_at_then'],
        'to'           => $rows === [] ? null : $rows[0]['sampled_at'],
        'measured_for' => 'every row, over the same two recorded cross-sections',
    ];
} else {
    $rows = latest_asset_scores(
        $pdo, METHOD_VERSION, $sort, $quadrant, $limit, $withStables, $minRank, $maxRank
    );
}

echo json_encode([
    'view'           => $view,
    'sort'           => $view === 'screener' ? $sort : 'absolute change in divergence, descending',
    'band'           => $band === '' ? 'all' : $band,
    'window'         => $window,
    'quadrant'       => $quadrant,
    'stablecoins'    => $withStables ? 'included' : 'excluded (pass ?stablecoins=1 to include)',
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
