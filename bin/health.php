<?php
/**
 * Is it still recording?
 *
 *     php bin/health.php
 *     php bin/health.php --stale=900   # exit non-zero if nothing landed in 15 min
 *
 * Answers the only question that matters during the recording phase, and prints the
 * gap figures the app header and the method page quote. Reads; never writes.
 *
 * Exit 0 = recording. 1 = stalled or nothing recorded yet.
 */

declare(strict_types=1);

require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/endpoints.php';
require __DIR__ . '/../lib/extract.php';

$options = getopt('', ['stale::']);
$staleSeconds = (int) ($options['stale'] ?? 900);

// A stopped extractor is the same category of fault as a stopped poller — a cron that
// is not running — so it exits non-zero the same way, and the watchdog entry in
// docs/deploy.md catches both. It is a separate flag from $problem, which covers
// degraded-but-alive conditions that are worth reading and not worth paging over.
$extractionStalled = false;

$config = load_config(['db_name', 'db_user']);
$pdo = db_connect($config);

if (!schema_is_present($pdo)) {
    fwrite(STDERR, "Schema missing. Run: mysql -u USER -p DB < sql/001_init.sql\n");
    exit(1);
}

echo "\nRecording health — " . gmdate('Y-m-d H:i:s') . " UTC\n";
echo str_repeat('=', 84) . "\n";

// --- Overall ----------------------------------------------------------------
$overall = $pdo->query(
    'SELECT COUNT(*) AS samples, MIN(fetched_at) AS first_at, MAX(fetched_at) AS last_at
       FROM raw_samples'
)->fetch();

if ((int) $overall['samples'] === 0) {
    echo "Nothing recorded yet.\n\n";
    echo "  php poller/run.php --once\n\n";
    exit(1);
}

$lastAgo = time() - strtotime((string) $overall['last_at'] . ' UTC');
$sinceDays = (time() - strtotime((string) $overall['first_at'] . ' UTC')) / 86400;

printf("Recording since %s UTC (%.1f days)\n", $overall['first_at'], $sinceDays);
printf("%s samples · last one %s ago\n\n", number_format((int) $overall['samples']), human_duration($lastAgo));

// --- Per endpoint -----------------------------------------------------------
// The 24h window is what the app header quotes, and it is short enough that a
// endpoint that died yesterday is still visible as a hole rather than averaged away.
$rows = $pdo->query(
    "SELECT endpoint,
            scope,
            COUNT(*)                                          AS attempts,
            SUM(http_status = 200)                            AS ok,
            MAX(attempted_at)                                 AS last_at,
            ROUND(AVG(duration_ms))                           AS avg_ms,
            SUM(COALESCE(credits, 0))                         AS credits
       FROM fetch_log
      WHERE attempted_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR
      GROUP BY endpoint, scope
      ORDER BY scope, endpoint"
)->fetchAll();

printf("%-28s %-7s %8s %8s %7s %8s %10s\n", 'endpoint', 'scope', 'attempts', 'ok', 'ok %', 'avg ms', 'last');
echo str_repeat('-', 84) . "\n";

$problem = false;
foreach ($rows as $row) {
    $okRate = (int) $row['attempts'] > 0 ? 100 * (int) $row['ok'] / (int) $row['attempts'] : 0.0;
    if ($okRate < 95.0) {
        $problem = true;
    }
    printf(
        "%-28s %-7s %8d %8d %6.1f%% %8s %10s\n",
        $row['endpoint'],
        $row['scope'],
        $row['attempts'],
        $row['ok'],
        $okRate,
        $row['avg_ms'] ?? '—',
        human_duration(time() - strtotime((string) $row['last_at'] . ' UTC'))
    );
}

// --- Gaps -------------------------------------------------------------------
// A gap is shown as a gap, here and on the charts. Nothing is interpolated (see
// docs/method.md), so the honest figure has to be visible to whoever is on call.
echo "\nGaps in the market cadence, last 24h\n";
echo str_repeat('-', 84) . "\n";

$ticks = $pdo->query(
    "SELECT DISTINCT run_id, MIN(attempted_at) AS started
       FROM fetch_log
      WHERE scope = 'market' AND attempted_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR
      GROUP BY run_id ORDER BY started"
)->fetchAll();

if (count($ticks) < 2) {
    echo "  Not enough runs in the window to measure cadence yet.\n";
} else {
    $intervals = [];
    for ($i = 1, $n = count($ticks); $i < $n; $i++) {
        $intervals[] = strtotime((string) $ticks[$i]['started'] . ' UTC')
                     - strtotime((string) $ticks[$i - 1]['started'] . ' UTC');
    }
    sort($intervals);
    $median = $intervals[intdiv(count($intervals), 2)];
    $longest = max($intervals);

    printf("  %d runs · median interval %s · longest gap %s\n", count($ticks), human_duration($median), human_duration($longest));

    // Cron drift is expected and is why real timestamps are stored (D5). A gap of
    // more than double the median is a missed tick, not drift.
    $missed = count(array_filter($intervals, static fn(int $i): bool => $i > 2 * $median));
    if ($missed > 0) {
        printf("  %d interval(s) longer than twice the median — missed ticks, not drift.\n", $missed);
        $problem = true;
    }
}

// --- Credits ----------------------------------------------------------------
$credits = $pdo->query(
    "SELECT SUM(COALESCE(credits,0)) AS today FROM fetch_log
      WHERE attempted_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR"
)->fetchColumn();
$creditsMonth = $pdo->query(
    "SELECT SUM(COALESCE(credits,0)) FROM fetch_log
      WHERE attempted_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')"
)->fetchColumn();

echo "\nCredits\n" . str_repeat('-', 84) . "\n";
printf(
    "  %s in the last 24h · %s this calendar month of 300,000 (%.1f%%)\n",
    number_format((int) $credits),
    number_format((int) $creditsMonth),
    100 * (int) $creditsMonth / 300000
);
printf("  At the last 24h rate, a 30-day month costs %s.\n", number_format((int) $credits * 30));

// --- Extraction -------------------------------------------------------------
// Recording and extraction fail independently: the poller can be storing payloads
// perfectly while the extractor has been dead for a day, and the charts would go flat
// without a single fetch failure to show for it. Lag is reported in samples rather
// than minutes because that is the figure bin/extract.php acts on.
if (derived_schema_is_present($pdo)) {
    echo "\nExtraction (version " . EXTRACTOR_VERSION . ")\n" . str_repeat('-', 84) . "\n";

    $extraction = $pdo->prepare(
        'SELECT COUNT(*) AS pending,
                MIN(r.fetched_at) AS oldest_pending
           FROM raw_samples r
           LEFT JOIN extraction_log e
                  ON e.raw_sample_id = r.id AND e.extractor_version = :version
          WHERE e.raw_sample_id IS NULL'
    );
    $extraction->execute([':version' => EXTRACTOR_VERSION]);
    $pending = $extraction->fetch();

    $done = $pdo->prepare(
        "SELECT status, COUNT(*) AS n, MAX(extracted_at) AS last_at
           FROM extraction_log
          WHERE extractor_version = :version
          GROUP BY status"
    );
    $done->execute([':version' => EXTRACTOR_VERSION]);

    $byStatus = [];
    $lastExtraction = null;
    foreach ($done->fetchAll() as $row) {
        $byStatus[(string) $row['status']] = (int) $row['n'];
        $lastExtraction = max($lastExtraction, (string) $row['last_at']);
    }

    $pendingCount = (int) $pending['pending'];
    printf(
        "  %s read · %s skipped · %s error · %s pending\n",
        number_format($byStatus['ok'] ?? 0),
        number_format($byStatus['skipped'] ?? 0),
        number_format($byStatus['error'] ?? 0),
        number_format($pendingCount)
    );

    if ($lastExtraction !== null) {
        printf("  last ran %s ago\n", human_duration(time() - strtotime($lastExtraction . ' UTC')));
    }

    if ($pendingCount > 0) {
        printf(
            "  oldest unread sample is %s old — run: php bin/extract.php\n",
            human_duration(time() - strtotime((string) $pending['oldest_pending'] . ' UTC'))
        );
        // One tick behind is normal; the extractor runs on its own cron. An hour
        // behind means it is not running at all.
        if (time() - strtotime((string) $pending['oldest_pending'] . ' UTC') > 3600) {
            $extractionStalled = true;
        }
    }

    // A skip reason is CMC changing a shape, a plan refusal, or a parsing bug. All
    // three are things to read the same day, so they are printed rather than counted.
    $reasons = $pdo->prepare(
        "SELECT note, COUNT(*) AS n FROM extraction_log
          WHERE extractor_version = :version AND status <> 'ok' AND note IS NOT NULL
          GROUP BY note ORDER BY n DESC LIMIT 5"
    );
    $reasons->execute([':version' => EXTRACTOR_VERSION]);
    foreach ($reasons->fetchAll() as $row) {
        printf("  %6s x %s\n", number_format((int) $row['n']), $row['note']);
    }

    if (($byStatus['error'] ?? 0) > 0) {
        $problem = true;
    }
} else {
    echo "\nExtraction\n" . str_repeat('-', 84) . "\n";
    echo "  Derived tables not migrated yet: mysql -u USER -p DB < sql/002_derived.sql\n";
}

// --- Verdict ----------------------------------------------------------------
echo "\n" . str_repeat('=', 84) . "\n";
if ($lastAgo > $staleSeconds) {
    printf("STALLED — nothing recorded for %s. Check cron and the log.\n\n", human_duration($lastAgo));
    exit(1);
}
if ($extractionStalled) {
    // Recording is fine, so no history is being lost — but the charts read the derived
    // tables, and those have stopped moving.
    echo "Recording, but EXTRACTION IS STALLED — the derived tables have stopped moving.\n";
    echo "Check the bin/extract.php cron entry.\n\n";
    exit(1);
}
echo $problem
    ? "Recording, with failures above worth reading.\n\n"
    : "Recording cleanly.\n\n";

exit(0);

// ---------------------------------------------------------------------------

function human_duration(int $seconds): string
{
    return match (true) {
        $seconds < 90    => $seconds . 's',
        $seconds < 5400  => round($seconds / 60) . 'm',
        $seconds < 172800 => round($seconds / 3600) . 'h',
        default          => round($seconds / 86400) . 'd',
    };
}
