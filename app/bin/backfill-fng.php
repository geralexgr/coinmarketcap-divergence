<?php
/**
 * Backfill the Voice axis from the one endpoint that has history behind it.
 *
 *     php bin/backfill-fng.php            # fetch and store, once
 *     php bin/backfill-fng.php --dry-run  # fetch, report, write nothing
 *     php bin/backfill-fng.php --force    # fetch again even though history exists
 *
 * Costs **one credit** and is meant to be run once, by hand. It is deliberately not on
 * a cron: the history does not change, and re-fetching it every quarter hour would buy
 * a duplicate of yesterday's answer for a credit a time.
 *
 * ## Why this exists
 *
 * Everything else this product measures is a snapshot. CoinMarketCap publishes no
 * historical endpoint for turnover, open interest, liquidations or exchange reserve, so
 * every one of those points exists only because the recorder was running at the time.
 * That is the argument for the whole project and it is still true.
 *
 * Sentiment is the exception. `/v3/fear-and-greed/historical` returns 500 daily
 * readings for one credit, it is callable on the Basic plan, and it has been declared
 * in `lib/endpoints.php` — marked `poll: false`, "Fetch once, not on a cron" — since
 * the first commit, and never actually fetched. Meanwhile the Voice axis was scored
 * against a hand-set 0-100 range while five hundred days of its own distribution sat
 * one call away.
 *
 * So the honest version of the recorder claim is narrower and stronger: **Voice can be
 * backfilled 500 days; Money cannot be backfilled at all.** The irreplaceable half of
 * this dataset is the Money axis, and saying so is better than implying both halves are
 * equally unobtainable when one of them is a single GET. See D22.
 *
 * ## What it changes
 *
 * Nothing, by itself. It writes one row to `raw_samples` exactly as the poller would,
 * and stops. `bin/extract.php` reads it into 500 dated `market_metric` rows and
 * `bin/score.php` puts the Voice axis onto the percentile basis, because that axis now
 * has the history the basis needs. Both are re-runnable and neither costs a credit.
 *
 * Exit codes: 0 stored (or nothing to do) · 1 the fetch failed · 3 could not start.
 */

declare(strict_types=1);

require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/http.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/endpoints.php';

$options = getopt('', ['dry-run', 'force', 'limit::']);
$dryRun  = array_key_exists('dry-run', $options);
$force   = array_key_exists('force', $options);

$entry = endpoint_by_key('fear_and_greed_historical');
if ($entry === null) {
    fwrite(STDERR, "fear_and_greed_historical is not in the endpoint catalogue.\n");
    exit(3);
}

// The catalogue records measured plan access, not intent. If the key loses this
// endpoint the run should say so rather than spend a round trip discovering it.
if (($entry['access'] ?? 'ok') !== 'ok') {
    fwrite(STDERR, "fear_and_greed_historical reads as '{$entry['access']}' on this plan — nothing to fetch.\n");
    exit(3);
}

$config = load_config($dryRun ? ['cmc_api_key'] : ['cmc_api_key', 'db_name', 'db_user']);
ensure_log_dir($config);

$pdo = $dryRun ? null : db_connect($config);
if ($pdo !== null && !schema_is_present($pdo)) {
    fwrite(STDERR, "Schema missing. Apply docs/schema.sql first.\n");
    exit(3);
}

echo "\nFear and greed — history backfill\n" . str_repeat('=', 72) . "\n";

// ---------------------------------------------------------------------------
// Idempotence: one credit is cheap, but spending it twice for the same answer is
// still waste, and a second stored payload doubles nothing except the extractor's
// work. --force exists for the case where CMC has extended the window.
// ---------------------------------------------------------------------------
if ($pdo !== null && !$force) {
    $existing = $pdo->prepare(
        'SELECT COUNT(*) FROM raw_samples WHERE endpoint = :endpoint AND http_status = 200'
    );
    $existing->execute([':endpoint' => 'fear_and_greed_historical']);
    if ((int) $existing->fetchColumn() > 0) {
        echo "  A history payload is already stored. Nothing to fetch.\n";
        echo "  Re-derive from it without spending a credit:\n\n";
        echo "      php bin/extract.php && php bin/score.php --rebuild\n\n";
        echo "  Use --force to fetch a fresh one anyway.\n\n";
        exit(0);
    }
}

$query = $entry['query'];
if (isset($options['limit'])) {
    $query['limit'] = max(1, (int) $options['limit']);
}

printf("  GET %s?%s\n", $entry['path'], http_build_query($query));

$runId      = new_run_id();
$attemptedAt = utc_now();
$res         = cmc_get($config, $entry['path'], $query);
$fetchedAt   = utc_now();
$outcome     = cmc_outcome($res);

// Logged whatever happened, like every other fetch in the system (D4). A 403 body is
// the evidence behind the API friction note; throwing it away costs the credit twice.
if ($pdo !== null) {
    $rawId = null;
    if ($res['body'] !== null) {
        $rawId = insert_raw_sample(
            $pdo, 'fear_and_greed_historical', 'market', $fetchedAt,
            $res['http_status'], $res['credits'], $res['body']
        );
    }
    insert_fetch_log(
        $pdo, $runId, 'fear_and_greed_historical', 'market', (string) $res['url'],
        $attemptedAt, $res['http_status'], $res['duration_ms'] ?? null,
        $res['credits'], $rawId,
        $outcome === 'ok' ? null : ($res['error'] ?? $res['api_error'] ?? $outcome)
    );
}

if ($outcome !== 'ok') {
    printf("  FAILED: %s\n\n", $res['error'] ?? $res['api_error'] ?? $outcome);
    exit(1);
}

// ---------------------------------------------------------------------------
// Report what came back. The parse is the extractor's, so what is printed here is
// what will actually land rather than a second opinion about it.
// ---------------------------------------------------------------------------
require __DIR__ . '/../lib/extract.php';

$parsed = extract_sample('fear_and_greed_historical', $res['body']);
$points = $parsed['market'] ?? [];

printf("  %d credit · %s\n", (int) ($res['credits'] ?? 0), $parsed['status']);

if ($points === []) {
    printf("  Stored, but nothing parsed out of it: %s\n\n", $parsed['note'] ?? 'no reason given');
    exit(1);
}

$dates  = array_column($points, 'sampled_at');
$values = array_map('floatval', array_column($points, 'value'));
sort($dates);

printf("  %d dated readings · %s to %s\n", count($points), substr($dates[0], 0, 10), substr($dates[count($dates) - 1], 0, 10));
printf("  index range %d to %d\n", (int) min($values), (int) max($values));
if (!empty($parsed['note'])) {
    printf("  note: %s\n", $parsed['note']);
}

if ($dryRun) {
    echo "\n  Dry run — nothing written.\n\n";
    exit(0);
}

echo "\n  Stored. Now derive from it (no credits, no network):\n\n";
echo "      php bin/extract.php\n";
echo "      php bin/score.php --rebuild\n\n";

exit(0);
