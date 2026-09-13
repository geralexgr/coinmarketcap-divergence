<?php
/**
 * Turn extracted metrics into Voice, Money and Divergence.
 *
 *     php bin/score.php                   # everything not yet scored at this method version
 *     php bin/score.php --market          # market-wide only
 *     php bin/score.php --assets          # per asset only
 *     php bin/score.php --rebuild         # rescore every moment ever recorded
 *     php bin/score.php --limit=500       # keep one cron tick short
 *     php bin/score.php --dry-run         # report what it would write, write nothing
 *
 * Costs no credits and touches no network. It reads `market_metric` and `asset_metric`
 * and writes `scores`, and every row it writes is disposable: change a weight in
 * `scoring/inputs.php`, bump METHOD_VERSION, run --rebuild, and the whole history is
 * rescored from data already on disk. That is the point of storing raw payloads (D3),
 * and it is why the weights can be wrong on day one without costing anything.
 *
 * Safe to run while the poller and the extractor are running. It writes to neither of
 * their tables.
 *
 * Exit codes: 0 done · 1 finished with moments it could not score · 3 could not start.
 */

declare(strict_types=1);

require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/endpoints.php';
require __DIR__ . '/../scoring/recompute.php';

$options = getopt('', ['market', 'assets', 'rebuild', 'limit::', 'dry-run', 'verbose', 'quiet']);

$rebuild = array_key_exists('rebuild', $options);
$dryRun  = array_key_exists('dry-run', $options);
$verbose = array_key_exists('verbose', $options);
$quiet   = array_key_exists('quiet', $options) && !$verbose;
$limit   = isset($options['limit']) ? max(1, (int) $options['limit']) : null;

// Neither flag means both scopes, which is what the cron entry wants.
$doMarket = array_key_exists('market', $options) || !array_key_exists('assets', $options);
$doAssets = array_key_exists('assets', $options) || !array_key_exists('market', $options);

$config = load_config(['db_name', 'db_user']);

$say = function (string $message, bool $always = false) use ($quiet): void {
    if ($always || !$quiet) {
        echo $message . "\n";
    }
};

// One scorer at a time. Two racing would not corrupt anything — every write upserts —
// but they would do identical work on a host that counts processes.
$lock = fopen(sys_get_temp_dir() . '/divergence-score.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $say('previous scoring run still in progress, skipping', true);
    exit(3);
}

try {
    $pdo = db_connect($config);
} catch (Throwable $e) {
    fwrite(STDERR, 'FATAL cannot connect to the database: ' . $e->getMessage() . "\n");
    exit(3);
}

if (!derived_schema_is_present($pdo) || !scores_schema_is_present($pdo)) {
    fwrite(STDERR, "Derived tables missing or out of date. Schema missing. Apply docs/schema.sql to the database (phpMyAdmin -> SQL tab, or: mysql -u USER -p DB < docs/schema.sql)\n");
    exit(3);
}

$say(sprintf(
    'method version %d%s%s',
    METHOD_VERSION,
    $rebuild ? ', rebuilding every moment' : '',
    $dryRun ? ', dry run — nothing will be written' : ''
));

// What the plan actually lets the method use. Printed every run because it is the
// single most load-bearing caveat in the product: Voice is one input of three.
if ($verbose) {
    foreach (['market', 'asset'] as $scope) {
        foreach (['voice', 'money'] as $axis) {
            $declared = count(axis_inputs($axis, $scope));
            $usable = count(available_inputs($axis, $scope));
            $names = implode(', ', array_column(available_inputs($axis, $scope), 'metric'));
            $say(sprintf('  %-6s %-5s  %d of %d inputs callable: %s', $scope, $axis, $usable, $declared, $names ?: 'none'));
        }
    }
}

$problems = 0;

foreach (['market' => $doMarket, 'asset' => $doAssets] as $scope => $wanted) {
    if (!$wanted) {
        continue;
    }

    $started = microtime(true);
    try {
        $result = $scope === 'market'
            ? recompute_market($pdo, $rebuild, $limit, $dryRun)
            : recompute_assets($pdo, $rebuild, $limit, $dryRun);
    } catch (Throwable $e) {
        fwrite(STDERR, "FATAL scoring {$scope}: " . $e->getMessage() . "\n");
        exit(1);
    }

    $say(sprintf(
        '%-6s  %d moment(s) available, %d score row(s) %s, %d skipped  (%.1fs)',
        $scope,
        $result['moments'],
        $result['written'],
        $dryRun ? 'pending' : 'written',
        $result['skipped'],
        microtime(true) - $started
    ));

    // A skip is never silent. Every one of these is either a recording gap or an input
    // the plan forbids, and both are things somebody should be able to read off a log
    // rather than infer from a thin chart.
    foreach ($result['reasons'] as $reason => $count) {
        $say(sprintf('        %4d x %s', $count, $reason));
        $problems++;
    }
}

exit($problems > 0 ? 1 : 0);
