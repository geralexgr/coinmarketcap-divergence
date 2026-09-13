<?php
/**
 * The recorder. This is the part of the project that cannot be caught up on later.
 *
 *     php poller/run.php --market          # every 5 minutes, via cron
 *     php poller/run.php --assets          # every 15 minutes, via cron
 *     php poller/run.php --once            # market scope, verbose, for a human
 *     php poller/run.php --once --dry-run  # fetch and report, write nothing
 *
 * What it does and nothing more: call each confirmed endpoint, store the response
 * body verbatim with the time it actually arrived, and log the attempt either way.
 * No parsing, no scoring, no normalisation. Those read from what this wrote, and can
 * be rewritten on day eighteen. This cannot.
 *
 * Exit codes: 0 all recorded · 1 some failed · 2 nothing recorded · 3 could not start.
 */

declare(strict_types=1);

require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/http.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/endpoints.php';

$options = getopt('', ['market', 'assets', 'once', 'dry-run', 'verbose', 'quiet']);

$scope   = array_key_exists('assets', $options) ? 'asset' : 'market';
$dryRun  = array_key_exists('dry-run', $options);
$once    = array_key_exists('once', $options);
$verbose = $once || array_key_exists('verbose', $options);
$quiet   = array_key_exists('quiet', $options) && !$verbose;

$config = load_config($dryRun ? ['cmc_api_key'] : ['cmc_api_key', 'db_name', 'db_user']);
ensure_log_dir($config);
$runId  = new_run_id();

/** Everything the run says goes through here, so cron output and the log file match. */
$log = function (string $message, bool $always = false) use ($config, $verbose, $quiet, $runId, $scope): void {
    $line = sprintf('[%s] %s %s: %s', utc_now(), $runId, $scope, $message);
    if ($always || !$quiet) {
        echo $line . "\n";
    }
    if (!empty($config['log_path'])) {
        @file_put_contents((string) $config['log_path'], $line . "\n", FILE_APPEND | LOCK_EX);
    }
};

// ---------------------------------------------------------------------------
// Only one run per scope at a time. A hung run on a shared host must not pile up
// behind the next cron tick until the host kills the account for process count.
// ---------------------------------------------------------------------------
$lockPath = sys_get_temp_dir() . "/divergence-{$scope}.lock";
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $log('previous run still in progress, skipping this tick', true);
    exit(3);
}

// ---------------------------------------------------------------------------
// Connect before fetching. A run that cannot write is a run that burns credits for
// nothing, and finding that out after the calls is the wrong order.
// ---------------------------------------------------------------------------
$pdo = null;
if (!$dryRun) {
    try {
        $pdo = db_connect($config);
    } catch (Throwable $e) {
        $log('FATAL cannot connect to the database: ' . $e->getMessage(), true);
        exit(3);
    }
    if (!schema_is_present($pdo)) {
        $log('FATAL Schema missing. Apply docs/schema.sql to the database (phpMyAdmin -> SQL tab, or: mysql -u USER -p DB < docs/schema.sql)', true);
        exit(3);
    }
}

$endpoints = endpoints_to_poll($config, $scope);
if ($endpoints === []) {
    $log('no endpoints configured for this scope', true);
    exit(3);
}

$limiter = new RateLimiter((int) $config['max_requests_per_minute']);
$calls = [];   // [key, query] — flattened, because asset endpoints expand into batches

foreach ($endpoints as $entry) {
    if ($scope === 'asset' && array_key_exists('id', $entry['query'])) {
        // Per-asset endpoints take a comma-separated id list, so the top 100 is one
        // call rather than a hundred. The universe comes from the most recent
        // listings sample this poller already stored — no extra call, and it means
        // the asset run records against the same universe the market run saw.
        foreach (asset_id_batches($pdo, $config, $limiter, $log) as $batch) {
            $query = $entry['query'];
            $query['id'] = implode(',', $batch);
            $calls[] = [$entry, $query];
        }
    } else {
        $calls[] = [$entry, $entry['query']];
    }
}

$log(sprintf('starting %d call(s)%s', count($calls), $dryRun ? ' (dry run, nothing will be written)' : ''));

$recorded = 0;
$failed = 0;
$credits = 0;

foreach ($calls as [$entry, $query]) {
    $limiter->wait();

    $attemptedAt = utc_now();
    $res = cmc_get($config, $entry['path'], $query);
    $outcome = cmc_outcome($res);
    // D5: the moment the response arrived, not the moment cron meant to start.
    $fetchedAt = utc_now();

    $credits += $res['credits'] ?? 0;
    $error = $res['error'] ?? $res['api_error'] ?? null;
    if ($outcome !== 'ok' && $error === null) {
        $error = $outcome;
    }

    $rawId = null;
    if (!$dryRun && $pdo !== null) {
        try {
            // The body is stored whatever the outcome was. A 403 body explains the
            // 403, and that explanation is the evidence for the API friction note.
            if ($res['body'] !== null) {
                $rawId = insert_raw_sample(
                    $pdo, $entry['key'], $scope, $fetchedAt,
                    $res['http_status'], $res['credits'], $res['body']
                );
            }
            insert_fetch_log(
                $pdo, $runId, $entry['key'], $scope, $res['url'], $attemptedAt,
                $res['http_status'], $res['duration_ms'], $res['credits'], $rawId, $error
            );
        } catch (Throwable $e) {
            // A write failure must not take down the rest of the run: the other
            // endpoints in this tick are still recoverable history.
            $log('WRITE FAILED ' . $entry['key'] . ': ' . $e->getMessage(), true);
            $failed++;
            continue;
        }
    }

    if ($outcome === 'ok') {
        $recorded++;
        if ($verbose) {
            $log(sprintf(
                '  ok   %-26s %5d ms  %4d bytes  %d credit(s)%s',
                $entry['key'],
                $res['duration_ms'],
                strlen((string) $res['body']),
                $res['credits'] ?? 0,
                $rawId !== null ? "  → raw_samples #{$rawId}" : ''
            ));
        }
    } else {
        $failed++;
        $log(sprintf('  FAIL %-26s %s %s', $entry['key'], $outcome, substr((string) $error, 0, 120)), true);
    }
}

$log(sprintf(
    'done: %d recorded, %d failed, %d credits, %s',
    $recorded,
    $failed,
    $credits,
    $dryRun ? 'nothing written' : 'written'
), $failed > 0);

flock($lock, LOCK_UN);
fclose($lock);

exit($recorded === 0 ? 2 : ($failed > 0 ? 1 : 0));

// ---------------------------------------------------------------------------

/**
 * The asset universe, as id batches, newest listings sample first.
 *
 * Reading it from storage rather than refetching keeps the asset run to the calls it
 * actually needs, and keeps both runs describing the same universe. If nothing has
 * been stored yet — the first ever asset run — it fetches once.
 *
 * @return array<int, int[]>
 */
function asset_id_batches(?PDO $pdo, array $config, RateLimiter $limiter, callable $log): array
{
    $universeSize = (int) $config['asset_universe'];
    $payload = null;

    if ($pdo !== null) {
        $stmt = $pdo->prepare(
            'SELECT payload FROM raw_samples
              WHERE endpoint = :endpoint AND http_status = 200
              ORDER BY fetched_at DESC LIMIT 1'
        );
        $stmt->execute([':endpoint' => 'listings_latest']);
        $payload = $stmt->fetchColumn();
        if ($payload === false) {
            $payload = null;
        }
    }

    if ($payload === null) {
        $log('  no stored universe yet, fetching listings once');
        $entry = endpoint_by_key('listings_latest');
        $limiter->wait();
        $res = cmc_get($config, $entry['path'], $entry['query']);
        if (cmc_outcome($res) !== 'ok') {
            $log('  FAILED to establish the asset universe: ' . cmc_outcome($res), true);
            return [];
        }
        $payload = $res['body'];
        if ($pdo !== null) {
            insert_raw_sample($pdo, 'listings_latest', 'market', utc_now(), $res['http_status'], $res['credits'], $payload);
        }
    }

    $decoded = json_decode((string) $payload, true);
    $ids = [];
    foreach ($decoded['data'] ?? [] as $asset) {
        if (isset($asset['id'])) {
            $ids[] = (int) $asset['id'];
        }
        if (count($ids) >= $universeSize) {
            break;
        }
    }

    if ($ids === []) {
        $log('  stored universe payload had no ids in it', true);
        return [];
    }

    // CMC prices quotes/latest per 100 data points, so 100 ids per call is one credit
    // and also the natural batch size.
    return array_chunk($ids, 100);
}
