<?php
/**
 * Map the API surface without a key.
 *
 *     php bin/probe-paths.php
 *     php bin/probe-paths.php --paths=extra-paths.txt
 *
 * pro-api.coinmarketcap.com resolves the path before it validates the key, so an
 * invalid key still separates real endpoints from imaginary ones:
 *
 *     401 / 403 / 400  → the path exists
 *     404              → it does not
 *     200 + error_code 500 "system is busy" → it does not (see cmc_outcome)
 *
 * This is how open question 2 got answered on day 1 without waiting for a key: every
 * plausible derivatives path 404s while every documented path 401s. Once a real key
 * exists, bin/verify-endpoints.php answers the different question of which of the
 * surviving paths our plan is actually allowed to call.
 *
 * No key needed and no credits spent — an invalid-key 401 is free.
 */

declare(strict_types=1);

require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/http.php';
require __DIR__ . '/../lib/endpoints.php';

$options = getopt('', ['paths::', 'key::']);

// A deliberately invalid key. Using the real one here would spend credits on the
// paths that do exist, and this question does not need a valid key to answer.
$config = [
    'cmc_api_key'     => $options['key'] ?? 'PROBE-INVALID-KEY-0000000000000000',
    'cmc_base_url'    => 'https://pro-api.coinmarketcap.com',
    'request_timeout' => 10,
];

$paths = candidate_paths();
if (isset($options['paths']) && is_readable((string) $options['paths'])) {
    $extra = file((string) $options['paths'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $paths = array_merge($paths, array_map('trim', $extra ?: []));
}

// Control paths. If these do not come back 'no', the technique is not working on this
// host or CMC has changed its routing, and every other result below is meaningless.
$controls = ['/v1/definitely-not-an-endpoint/xyz', '/v3/also-not-real/abc'];

$limiter = new RateLimiter(120);
echo "\nProbing " . count($paths) . " paths for existence (no key, no credits)\n";
echo str_repeat('-', 78) . "\n";

$controlOk = true;
foreach ($controls as $control) {
    $limiter->wait();
    if (cmc_path_exists(cmc_get($config, $control)) !== 'no') {
        $controlOk = false;
        echo "CONTROL FAILED: {$control} did not read as absent. Results below are not trustworthy.\n";
    }
}
if ($controlOk) {
    echo "Control paths read as absent — the 404/401 split is working.\n\n";
}

$exists = [];
$absent = [];

foreach ($paths as $path) {
    $limiter->wait();
    $res = cmc_get($config, $path);
    $verdict = cmc_path_exists($res);
    $outcome = cmc_outcome($res);

    printf(
        "%s %-50s %s %s\n",
        $verdict === 'yes' ? 'exists ' : ($verdict === 'no' ? 'absent ' : '   ?   '),
        $path,
        str_pad((string) ($res['http_status'] ?? '—'), 4),
        $outcome
    );

    if ($verdict === 'yes') {
        $exists[] = $path;
    } elseif ($verdict === 'no') {
        $absent[] = $path;
    }
}

echo str_repeat('-', 78) . "\n";
printf("%d exist, %d absent, %d inconclusive.\n\n", count($exists), count($absent), count($paths) - count($exists) - count($absent));

$deadDerivatives = array_values(array_filter($absent, static fn(string $p): bool =>
    str_contains($p, 'derivativ') || str_contains($p, 'futures') || str_contains($p, 'perpetual')
    || str_contains($p, 'funding') || str_contains($p, 'open-interest') || str_contains($p, 'liquidation')));
$liveDerivatives = array_values(array_filter($exists, static fn(string $p): bool =>
    str_contains($p, 'derivativ') || str_contains($p, 'futures') || str_contains($p, 'perpetual')
    || str_contains($p, 'funding') || str_contains($p, 'open-interest') || str_contains($p, 'liquidation')));

echo "Open question 2 — derivatives paths:\n";
echo $liveDerivatives === []
    ? '  none of ' . count($deadDerivatives) . " candidate paths exist. The Money axis takes the fallback.\n"
    : '  ' . implode("\n  ", $liveDerivatives) . "\n  These exist — verify plan access with bin/verify-endpoints.php.\n";
echo "\n";

/**
 * Candidate paths: everything in the catalogue, plus the naming shapes a derivatives
 * endpoint could plausibly take. Guessing widely is cheap here — the whole probe costs
 * nothing but time — and a wrong guess is recorded as absent rather than assumed away.
 *
 * @return string[]
 */
function candidate_paths(): array
{
    $paths = array_map(static fn(array $e): string => $e['path'], endpoint_catalogue());

    // Sweep every version prefix, not only the ones this project already uses.
    //
    // The original sweep stopped at v4 because nothing here lived under v5, and it
    // therefore "proved" that no derivatives endpoint existed anywhere on the API. The
    // whole family is under /v5/ (D20). A probe whose search space is defined by what is
    // already believed can only ever confirm the belief, so the range now runs past the
    // versions in use and the shapes include the ones that were actually found.
    $derivativeShapes = [];
    foreach (['v1', 'v2', 'v3', 'v4', 'v5', 'v6'] as $version) {
        foreach ([
            'derivatives/listings/latest',
            'derivatives/quotes/latest',
            'derivatives/exchanges/latest',
            'derivatives/funding-rate/latest',
            'derivatives/open-interest/latest',
            'derivatives/liquidations/latest',
            'futures/listings/latest',
            'perpetuals/listings/latest',
            // Confirmed present under v5 on 13 Sep 2026. Probed at every version anyway,
            // so the day CoinMarketCap moves them the sweep says so instead of the
            // poller quietly recording 404s.
            'cryptocurrency/derivatives/market-pairs/list/latest',
            'derivatives/liquidations/cryptocurrency/list/latest',
            'derivatives/liquidations/cryptocurrency/quotes/latest',
            'exchange/derivatives/list',
            // Neighbouring families seen in use elsewhere. Cheap to include, and their
            // absence from the original list is exactly how v5 was missed.
            'real-world-assets/assets/list',
            'real-world-assets/quotes/latest',
            'altcoin-season-index/latest',
            'cryptocurrency/categories',
        ] as $shape) {
            $derivativeShapes[] = "/{$version}/{$shape}";
        }
    }

    $other = [
        '/v1/cryptocurrency/derivatives/latest',
        '/v1/exchange/derivatives/latest',
        '/v1/open-interest/latest',
        '/v1/funding-rate/latest',
        '/v1/liquidations/latest',
        '/v1/content/posts/latest',
        '/v1/content/posts/comments',
        '/v1/cryptocurrency/categories',
        '/v1/cryptocurrency/airdrops',
        '/v1/exchange/quotes/latest',
        '/v4/dex/listings/quotes',
        '/v4/dex/networks/list',
        '/v4/dex/spot-pairs/latest',
        '/v2/tools/price-conversion',
    ];

    return array_values(array_unique(array_merge($paths, $derivativeShapes, $other)));
}
