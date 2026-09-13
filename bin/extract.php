<?php
/**
 * Read stored payloads, write typed rows.
 *
 *     php bin/extract.php                 # everything not yet read at this version
 *     php bin/extract.php --limit=500     # keep one cron tick short
 *     php bin/extract.php --rebuild       # re-read every payload ever stored
 *     php bin/extract.php --dry-run       # report what it would write, write nothing
 *     php bin/extract.php --endpoint=global_metrics --verbose
 *
 * This costs no credits and touches no network — it only ever reads raw_samples. That
 * is the point of D3: the parsing can be wrong on day one and fixed on day eighteen
 * without a single lost sample. Bump EXTRACTOR_VERSION in lib/extract.php, run with
 * --rebuild, and the whole history is re-derived.
 *
 * Safe to run while the poller is running. It never writes to raw_samples.
 *
 * Exit codes: 0 done · 1 finished with payloads it could not read · 3 could not start.
 */

declare(strict_types=1);

require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/extract.php';

$options = getopt('', ['limit::', 'rebuild', 'dry-run', 'verbose', 'quiet', 'endpoint::']);

$limit    = (int) ($options['limit'] ?? 2000);
$rebuild  = array_key_exists('rebuild', $options);
$dryRun   = array_key_exists('dry-run', $options);
$verbose  = array_key_exists('verbose', $options);
$quiet    = array_key_exists('quiet', $options) && !$verbose;
$endpoint = isset($options['endpoint']) ? (string) $options['endpoint'] : null;

$config = load_config(['db_name', 'db_user']);

$say = function (string $message, bool $always = false) use ($quiet): void {
    if ($always || !$quiet) {
        echo $message . "\n";
    }
};

// One extractor at a time. Two of these racing would not corrupt anything — every
// write upserts — but they would do the same work twice on a host that charges for it.
$lock = fopen(sys_get_temp_dir() . '/divergence-extract.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $say('previous extraction still in progress, skipping', true);
    exit(3);
}

try {
    $pdo = db_connect($config);
} catch (Throwable $e) {
    fwrite(STDERR, 'FATAL cannot connect to the database: ' . $e->getMessage() . "\n");
    exit(3);
}

if (!schema_is_present($pdo)) {
    fwrite(STDERR, "Schema missing. Run: mysql -u USER -p DB < sql/001_init.sql\n");
    exit(3);
}
if (!derived_schema_is_present($pdo)) {
    fwrite(STDERR, "Derived tables missing. Run: mysql -u USER -p DB < sql/002_derived.sql\n");
    exit(3);
}

// ---------------------------------------------------------------------------
// Pick the work. Ordered oldest first so that a run cut short by a shared-host
// process limit still leaves the series contiguous from the start rather than
// pocked with holes.
// ---------------------------------------------------------------------------
$where = ['1 = 1'];
$params = [':version' => EXTRACTOR_VERSION, ':limit' => max(1, $limit)];

if (!$rebuild) {
    $where[] = 'e.raw_sample_id IS NULL';
}
if ($endpoint !== null) {
    $where[] = 'r.endpoint = :endpoint';
    $params[':endpoint'] = $endpoint;
}

// Ids first, payloads one at a time.
//
// The obvious version of this selects the payload column alongside the id and fetches
// the batch in one go. That loads up to --limit LONGTEXT bodies into memory at once: a
// listings payload is around 150KB, so the documented cron limit of 2000 needs roughly
// 300MB and dies on a shared host with the usual 128MB cap. Measured, not theorised —
// it exhausted the default limit on the first full run.
//
// Selecting only the ids keeps that list to a few tens of kilobytes whatever --limit is,
// and each payload is then read, used and released one at a time. Peak memory becomes a
// property of the largest single payload rather than of the batch size.
$sql = 'SELECT r.id
          FROM raw_samples r
          LEFT JOIN extraction_log e
                 ON e.raw_sample_id = r.id AND e.extractor_version = :version
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY r.id
         LIMIT :limit';

$stmt = $pdo->prepare($sql);
foreach ($params as $name => $value) {
    $stmt->bindValue($name, $value, $name === ':limit' || $name === ':version' ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();
$sampleIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

if ($sampleIds === []) {
    $say(sprintf('Nothing to extract at version %d. Everything stored has been read.', EXTRACTOR_VERSION));
    exit(0);
}

$say(sprintf(
    "Extracting %d sample(s) at version %d%s\n",
    count($sampleIds),
    EXTRACTOR_VERSION,
    $dryRun ? ' (dry run, nothing will be written)' : ''
));

$loadSample = $pdo->prepare(
    'SELECT id, endpoint, scope, fetched_at, http_status, payload FROM raw_samples WHERE id = ?'
);

// ---------------------------------------------------------------------------
// Read them.
// ---------------------------------------------------------------------------
$counts = ['ok' => 0, 'skipped' => 0, 'error' => 0];
$rowsTotal = 0;
$skipReasons = [];

foreach ($sampleIds as $sampleId) {
    $loadSample->execute([$sampleId]);
    $sample = $loadSample->fetch();
    if ($sample === false) {
        continue;
    }

    $id = (int) $sample['id'];
    $endpointName = (string) $sample['endpoint'];
    $sampledAt = (string) $sample['fetched_at'];

    try {
        $result = extract_sample($endpointName, $sample['payload']);
    } catch (Throwable $e) {
        // A payload that throws is a parsing bug, not a reason to stop: it is recorded
        // and the run continues, because one malformed sample must not block the rest
        // of the history from being derived.
        $counts['error']++;
        $note = get_class($e) . ': ' . $e->getMessage();
        $skipReasons[$note] = ($skipReasons[$note] ?? 0) + 1;
        if (!$dryRun) {
            record_extraction($pdo, $id, EXTRACTOR_VERSION, 'error', 0, $note);
        }
        if ($verbose) {
            $say(sprintf('  #%-8d %-26s ERROR %s', $id, $endpointName, $note));
        }
        continue;
    }

    $written = 0;
    if (!$dryRun) {
        $pdo->beginTransaction();
        try {
            $written += insert_market_metrics($pdo, $id, $endpointName, $sampledAt, $result['market']);
            $written += insert_asset_metrics($pdo, $id, $endpointName, $sampledAt, $result['asset']);
            if ($result['universe'] !== []) {
                upsert_asset_universe($pdo, $sampledAt, $result['universe']);
            }
            record_extraction($pdo, $id, EXTRACTOR_VERSION, $result['status'], $written, $result['note']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            fwrite(STDERR, sprintf("FATAL writing sample #%d (%s): %s\n", $id, $endpointName, $e->getMessage()));
            exit(1);
        }
    } else {
        $written = count($result['market']) + count($result['asset']);
    }

    $counts[$result['status']]++;
    $rowsTotal += $written;

    if ($result['status'] !== 'ok' && $result['note'] !== null) {
        $skipReasons[$result['note']] = ($skipReasons[$result['note']] ?? 0) + 1;
    }
    if ($verbose) {
        $say(sprintf(
            '  #%-8d %-26s %-8s %4d row(s) %s',
            $id,
            $endpointName,
            $result['status'],
            $written,
            $result['note'] ?? ''
        ));
    }

    // Explicit, because the loop body holds the only reference and the next iteration
    // would otherwise keep this payload alive while loading the following one.
    unset($sample, $result);
}

// ---------------------------------------------------------------------------
// Report. The skip reasons matter more than the totals: a new reason appearing is
// usually CMC changing a response shape, and it should be read the same day.
// ---------------------------------------------------------------------------
$say(sprintf(
    "\n%d ok · %d skipped · %d error · %s derived row(s)",
    $counts['ok'],
    $counts['skipped'],
    $counts['error'],
    number_format($rowsTotal)
), true);

if ($skipReasons !== []) {
    $say("\nWhy payloads were not read:");
    arsort($skipReasons);
    foreach ($skipReasons as $reason => $n) {
        $say(sprintf('  %4d x %s', $n, $reason));
    }
}

if (count($sampleIds) === $limit) {
    $say(sprintf("\nHit the --limit of %d. Run again for the rest.", $limit));
}

$say('');

exit($counts['error'] > 0 ? 1 : 0);
