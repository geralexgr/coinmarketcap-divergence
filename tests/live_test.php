<?php
/**
 * The extractor against the real payloads.
 *
 * tests/fixtures/live/ is written by `bin/verify-endpoints.php --save-fixtures` and is
 * gitignored, so these cases skip when it is empty rather than failing a fresh clone.
 * When it is populated, this is the file that matters: the synthetic fixtures prove the
 * extractor handles the shape we *documented*, and only these prove it handles the
 * shape CoinMarketCap actually sends.
 *
 * That distinction has already earned its place once. The live /v3/fear-and-greed/
 * historical payload arrives newest-first; the extractor assumed oldest-first and
 * returned a value sixteen months stale, which looked entirely reasonable on a chart.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/lib/extract.php';

function live_fixture(string $name): ?string
{
    $path = __DIR__ . '/fixtures/live/' . $name . '.json';
    return is_readable($path) ? (string) file_get_contents($path) : null;
}

/** @return array<string,float> */
function live_market(string $endpoint, ?string $file = null): ?array
{
    $body = live_fixture($file ?? $endpoint);
    if ($body === null) {
        return null;
    }
    $result = extract_sample($endpoint, $body);
    $out = [];
    foreach ($result['market'] as $row) {
        $out[$row['metric']] = $row['value'];
    }
    return $out;
}

test('LIVE global-metrics carries derivatives volume — the field D10 turned on', function (): void {
    $m = live_market('global_metrics');
    if ($m === null) {
        return; // no live fixtures yet
    }
    assert_true(
        array_key_exists('derivatives_volume_24h', $m),
        'confirmed present 13 Sep 2026. If this ever stops being true, the Money axis '
        . 'loses its only positioning-shaped input and docs/decisions.md D10 needs revisiting'
    );
    assert_true($m['derivatives_volume_24h'] > 0, 'and it must be a real figure, not a zero');
});

test('LIVE global-metrics gives the cold-start inputs that need no recorded history', function (): void {
    $m = live_market('global_metrics');
    if ($m === null) {
        return;
    }
    // These arrive pre-differenced, so the charts are not empty on day one. Losing them
    // would not break anything visibly — it would just quietly make the first week blank.
    foreach (['total_volume_24h_change', 'total_market_cap_change', 'btc_dominance_change'] as $metric) {
        assert_true(array_key_exists($metric, $m), "{$metric} missing from the live payload");
    }
    assert_true($m['reported_volume_ratio'] >= 1.0, 'reported volume cannot be below the adjusted figure');
});

test('LIVE global-metrics gives turnover inside a believable range', function (): void {
    $m = live_market('global_metrics');
    if ($m === null) {
        return;
    }
    // Not a prediction — a sanity bound. Total 24h volume has never been a hundredth of
    // a percent of total market cap, nor larger than it. Either would mean the fields
    // moved and the division is now measuring something else.
    assert_true(
        $m['market_turnover'] > 0.0001 && $m['market_turnover'] < 1.0,
        'market_turnover out of any plausible range: ' . $m['market_turnover']
    );
});

test('LIVE fear-and-greed history returns the newest point, not the oldest', function (): void {
    $body = live_fixture('fear_and_greed_historical');
    if ($body === null) {
        return;
    }
    $decoded = json_decode($body, true);
    $timestamps = array_map(static fn(array $p): int => (int) $p['timestamp'], $decoded['data']);
    $newest = null;
    foreach ($decoded['data'] as $point) {
        if ($newest === null || (int) $point['timestamp'] > (int) $newest['timestamp']) {
            $newest = $point;
        }
    }

    $m = live_market('fear_and_greed', 'fear_and_greed_historical');
    assert_close((float) $newest['value'], $m['fear_greed'], 'the newest point by timestamp is the reading');

    // Documents the ordering rather than asserting it: if CMC ever flips it, the
    // extractor is already indifferent, and this line says so out loud.
    $descending = $timestamps[0] > $timestamps[count($timestamps) - 1];
    assert_true(true, 'payload ordering is ' . ($descending ? 'newest-first' : 'oldest-first'));
});

test('LIVE every available endpoint extracts something', function (): void {
    // The regression net for CMC changing a response shape. An endpoint our plan can
    // call, whose payload we can no longer read, is a silent hole in the charts.
    $shouldExtract = ['global_metrics', 'listings_latest', 'quotes_latest', 'fear_and_greed', 'key_info'];

    foreach ($shouldExtract as $endpoint) {
        $body = live_fixture($endpoint);
        if ($body === null) {
            continue;
        }
        $result = extract_sample($endpoint, $body);
        assert_same('ok', $result['status'], "{$endpoint} must extract: " . (string) $result['note']);
        assert_true(
            count($result['market']) + count($result['asset']) > 0,
            "{$endpoint} produced no rows"
        );
    }
});

test('LIVE a 403 payload is skipped, not read as data', function (): void {
    // Seven of the nine Voice endpoints answer 403 on this plan. The verifier stored
    // those bodies too, and none of them may produce a metric row.
    foreach (['content_latest', 'trending_most_visited', 'exchange_listings'] as $endpoint) {
        $body = live_fixture($endpoint);
        if ($body === null) {
            continue;
        }
        $result = extract_sample($endpoint, $body);
        assert_same('skipped', $result['status'], "{$endpoint} is forbidden on this plan and must skip");
        assert_true(
            stripos((string) $result['note'], 'plan') !== false
            || stripos((string) $result['note'], 'subscription') !== false,
            "the reason must name the plan, because that is the evidence for docs/api-friction.md: "
            . (string) $result['note']
        );
    }
});
