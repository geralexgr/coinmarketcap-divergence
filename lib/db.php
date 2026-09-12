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

/** True when 001_init.sql has been applied. Checked before the first write, not after. */
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
