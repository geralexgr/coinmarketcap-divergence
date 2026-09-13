<?php
/**
 * Database access. Two writers and a connection, which is all the recorder needs.
 *
 * Everything here writes UTC. The host's timezone is not a fact about the data, and
 * a shared host can have its clock moved without warning — see D5.
 */

declare(strict_types=1);

/** @param array<string,mixed> $config */
function db_connect(array $config): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        (int) $config['db_port'],
        $config['db_name']
    );

    $pdo = new PDO($dsn, (string) $config['db_user'], (string) $config['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // The session, not the server: shared hosting will not change the server default,
    // and every timestamp this process writes has to be UTC regardless.
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

/** Current UTC time as MySQL DATETIME(3). */
function utc_now(?float $timestamp = null): string
{
    $ts = $timestamp ?? microtime(true);
    $whole = (int) floor($ts);
    $millis = (int) round(($ts - $whole) * 1000);
    if ($millis === 1000) {
        $whole++;
        $millis = 0;
    }
    return gmdate('Y-m-d H:i:s', $whole) . '.' . str_pad((string) $millis, 3, '0', STR_PAD_LEFT);
}

/** Groups every endpoint fetched by one cron invocation. Sortable, collision-safe enough. */
function new_run_id(): string
{
    return gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3));
}

/**
 * Append one verbatim payload. Never updated, never deleted.
 *
 * The payload is stored whatever the status was: a 403 body explains why it was a 403,
 * and that explanation is the evidence for the API friction write-up.
 */
function insert_raw_sample(
    PDO $pdo,
    string $endpoint,
    string $scope,
    string $fetchedAt,
    ?int $httpStatus,
    ?int $credits,
    ?string $payload
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO raw_samples (endpoint, scope, fetched_at, http_status, credits, payload)
         VALUES (:endpoint, :scope, :fetched_at, :http_status, :credits, :payload)'
    );
    $stmt->execute([
        ':endpoint'    => $endpoint,
        ':scope'       => $scope,
        ':fetched_at'  => $fetchedAt,
        ':http_status' => $httpStatus,
        ':credits'     => $credits,
        ':payload'     => $payload,
    ]);

    return (int) $pdo->lastInsertId();
}

/** Append one attempt. Called for every call, including the ones that never connected. */
function insert_fetch_log(
    PDO $pdo,
    string $runId,
    string $endpoint,
    string $scope,
    string $url,
    string $attemptedAt,
    ?int $httpStatus,
    ?int $durationMs,
    ?int $credits,
    ?int $rawSampleId,
    ?string $error
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO fetch_log
            (run_id, endpoint, scope, url, attempted_at, http_status, duration_ms, credits, raw_sample_id, error)
         VALUES
            (:run_id, :endpoint, :scope, :url, :attempted_at, :http_status, :duration_ms, :credits, :raw_sample_id, :error)'
    );
    $stmt->execute([
        ':run_id'        => $runId,
        ':endpoint'      => $endpoint,
        ':scope'         => $scope,
        ':url'           => substr($url, 0, 500),
        ':attempted_at'  => $attemptedAt,
        ':http_status'   => $httpStatus,
        ':duration_ms'   => $durationMs,
        ':credits'       => $credits,
        ':raw_sample_id' => $rawSampleId,
        ':error'         => $error,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * When each endpoint was last recorded successfully.
 *
 * Drives per-endpoint cadence in the poller. Read from raw_samples rather than kept in a
 * file, because the schedule then survives a restart, a redeploy and a cleared temp
 * directory — the record of what happened is the same thing as the schedule state.
 *
 * @return array<string,string> endpoint => UTC datetime
 */
function last_success_times(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT endpoint, MAX(fetched_at) AS last_at
           FROM raw_samples
          WHERE http_status = 200
       GROUP BY endpoint'
    )->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row['endpoint']] = (string) $row['last_at'];
    }

    return $out;
}

/** True when the recording core exists. Checked before the first write, not after. */
function schema_is_present(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM raw_samples LIMIT 1');
        $pdo->query('SELECT 1 FROM fetch_log LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// ---------------------------------------------------------------------------
// Derived tables. Everything below is rebuildable from
// raw_samples, which is why all of it upserts rather than appends: running the
// extractor twice over the same payload must produce the same table, not two
// copies of the same series.
// ---------------------------------------------------------------------------

/** True when the derived tables exist. */
function derived_schema_is_present(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM market_metric LIMIT 1');
        $pdo->query('SELECT 1 FROM asset_metric LIMIT 1');
        $pdo->query('SELECT 1 FROM asset_universe LIMIT 1');
        $pdo->query('SELECT 1 FROM extraction_log LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * @param array<int,array{metric:string,value:float}> $metrics
 * @return int rows written
 */
function insert_market_metrics(PDO $pdo, int $rawSampleId, string $endpoint, string $sampledAt, array $metrics): int
{
    if ($metrics === []) {
        return 0;
    }

    $placeholders = [];
    $values = [];
    foreach ($metrics as $row) {
        $placeholders[] = '(?, ?, ?, ?, ?)';
        array_push($values, $rawSampleId, $endpoint, $row['metric'], $row['value'], $sampledAt);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO market_metric (raw_sample_id, endpoint, metric, value, sampled_at) VALUES '
        . implode(', ', $placeholders)
        . ' ON DUPLICATE KEY UPDATE value = VALUES(value), endpoint = VALUES(endpoint), sampled_at = VALUES(sampled_at)'
    );
    $stmt->execute($values);

    return count($metrics);
}

/**
 * Chunked, because a listings sample is ~100 assets times ~7 metrics and MySQL has a
 * max_allowed_packet that shared hosts set low.
 *
 * @param array<int,array{cmc_id:int,symbol:string,metric:string,value:float}> $metrics
 * @return int rows written
 */
function insert_asset_metrics(PDO $pdo, int $rawSampleId, string $endpoint, string $sampledAt, array $metrics, int $chunk = 200): int
{
    $written = 0;

    foreach (array_chunk($metrics, max(1, $chunk)) as $batch) {
        $placeholders = [];
        $values = [];
        foreach ($batch as $row) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $values,
                $rawSampleId,
                $endpoint,
                $row['cmc_id'],
                substr($row['symbol'], 0, 32),
                $row['metric'],
                $row['value'],
                $sampledAt
            );
        }

        $stmt = $pdo->prepare(
            'INSERT INTO asset_metric (raw_sample_id, endpoint, cmc_id, symbol, metric, value, sampled_at) VALUES '
            . implode(', ', $placeholders)
            . ' ON DUPLICATE KEY UPDATE value = VALUES(value), symbol = VALUES(symbol), sampled_at = VALUES(sampled_at)'
        );
        $stmt->execute($values);
        $written += count($batch);
    }

    return $written;
}

/**
 * Membership of the tracked universe.
 *
 * first_seen uses LEAST so that a rebuild, which may process samples in any order,
 * cannot move an asset's arrival forward in time; last_seen uses GREATEST for the
 * same reason. An asset that stops appearing simply stops having last_seen moved,
 * which is what makes "when did this leave the top 100" answerable later.
 *
 * @param array<int,array{cmc_id:int,symbol:string,name:string,rank:?int,is_stablecoin?:bool}> $assets
 */
function upsert_asset_universe(PDO $pdo, string $sampledAt, array $assets, int $chunk = 100): int
{
    $written = 0;

    foreach (array_chunk($assets, max(1, $chunk)) as $batch) {
        $placeholders = [];
        $values = [];
        foreach ($batch as $asset) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $values,
                $asset['cmc_id'],
                substr($asset['symbol'], 0, 32),
                substr($asset['name'], 0, 120),
                !empty($asset['is_stablecoin']) ? 1 : 0,
                $asset['rank'],
                $sampledAt,
                $sampledAt
            );
        }

        $stmt = $pdo->prepare(
            'INSERT INTO asset_universe (cmc_id, symbol, name, is_stablecoin, rank_last, first_seen, last_seen) VALUES '
            . implode(', ', $placeholders)
            . ' ON DUPLICATE KEY UPDATE
                 symbol        = VALUES(symbol),
                 name          = VALUES(name),
                 is_stablecoin = VALUES(is_stablecoin),
                 rank_last     = IF(VALUES(last_seen) >= last_seen, VALUES(rank_last), rank_last),
                 first_seen    = LEAST(first_seen, VALUES(first_seen)),
                 last_seen     = GREATEST(last_seen, VALUES(last_seen))'
        );
        $stmt->execute($values);
        $written += count($batch);
    }

    return $written;
}

/** What happened to one payload, so the next run knows not to read it again. */
function record_extraction(
    PDO $pdo,
    int $rawSampleId,
    int $extractorVersion,
    string $status,
    int $rowsWritten,
    ?string $note
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO extraction_log (raw_sample_id, extractor_version, extracted_at, status, rows_written, note)
         VALUES (:raw_sample_id, :version, :at, :status, :rows, :note)
         ON DUPLICATE KEY UPDATE
            extracted_at = VALUES(extracted_at),
            status       = VALUES(status),
            rows_written = VALUES(rows_written),
            note         = VALUES(note)'
    );
    $stmt->execute([
        ':raw_sample_id' => $rawSampleId,
        ':version'       => $extractorVersion,
        ':at'            => utc_now(),
        ':status'        => $status,
        ':rows'          => $rowsWritten,
        ':note'          => $note,
    ]);
}
