<?php
/**
 * Extraction tests.
 *
 * Aimed at the cases that fail silently rather than loudly: a division that should not
 * have happened, an error body that parses as valid JSON, a field that is absent being
 * quietly turned into a zero. A zero plots as a reading; a gap should plot as a gap.
 *
 * Fixtures are synthetic until a key exists — see tests/fixtures/synthetic/README.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/extract.php';

/** @return array<string,float> metric => value, for readable assertions */
function market_metrics(array $result): array
{
    $out = [];
    foreach ($result['market'] as $row) {
        $out[$row['metric']] = $row['value'];
    }
    return $out;
}

/** @return array<string,float> "id.metric" => value */
function asset_metrics(array $result): array
{
    $out = [];
    foreach ($result['asset'] as $row) {
        $out[$row['cmc_id'] . '.' . $row['metric']] = $row['value'];
    }
    return $out;
}

function extract_fixture(string $endpoint, string $file): array
{
    return extract_sample($endpoint, fixture('synthetic/' . $file));
}

// ---------------------------------------------------------------------------
// The status block, not the HTTP status
// ---------------------------------------------------------------------------

test('an error_code 500 body is skipped, not read as data', function (): void {
    $result = extract_fixture('global_metrics', 'error_system_busy.json');
    assert_same('skipped', $result['status'], 'the system-is-busy body must be skipped');
    assert_same([], $result['market'], 'nothing may be extracted from an error body');
    assert_true(
        strpos((string) $result['note'], '500') !== false,
        'the reason must name the error code, since this is the unknown-path trap'
    );
});

test('a plan-forbidden body is skipped with the reason kept verbatim', function (): void {
    $result = extract_fixture('quotes_latest', 'error_plan_forbidden.json');
    assert_same('skipped', $result['status'], 'a 1006 body is not data');
    assert_true(
        strpos((string) $result['note'], 'not authorized') !== false,
        'the plan message is the evidence for the API friction write-up and must survive'
    );
});

test('a body that is not JSON is skipped rather than throwing', function (): void {
    $result = extract_sample('global_metrics', '<html>502 Bad Gateway</html>');
    assert_same('skipped', $result['status'], 'an HTML error page must not stop the run');
});

test('a sample with no body at all is skipped', function (): void {
    $result = extract_sample('global_metrics', null);
    assert_same('skipped', $result['status'], 'a timeout stores no body and must be skipped');
});

test('an endpoint with no extractor is skipped by name', function (): void {
    $result = extract_sample('market_pairs_derivatives', fixture('synthetic/global_metrics.json'));
    assert_same('skipped', $result['status'], 'unmapped endpoints are recorded, not guessed at');
    assert_true(
        strpos((string) $result['note'], 'market_pairs_derivatives') !== false,
        'the note must name the endpoint so the gap is findable'
    );
});

// ---------------------------------------------------------------------------
// Money, market-wide
// ---------------------------------------------------------------------------

test('global metrics yields turnover as volume over market cap', function (): void {
    $m = market_metrics(extract_fixture('global_metrics', 'global_metrics.json'));
    assert_close(0.05, $m['market_turnover'], '100bn over 2tn is 0.05');
    assert_close(2.0e12, $m['total_market_cap'], 'total market cap is passed through');
    assert_close(54.2153, $m['btc_dominance'], 'dominance is passed through');
    assert_close(0.6, $m['stablecoin_volume_share'], '60bn of 100bn is 0.6');
});

test('derivatives volume is absent when the payload does not carry it', function (): void {
    $m = market_metrics(extract_fixture('global_metrics', 'global_metrics.json'));
    assert_true(
        !array_key_exists('derivatives_volume_24h', $m),
        'D10 rests on this field being absent — it must never be invented as a zero'
    );
});

test('derivatives volume is picked up under either spelling if it appears', function (): void {
    // The single field that could partly reverse D10. Both spellings are accepted
    // because which one CMC uses is unverified until a key exists.
    foreach (['derivative_volume_24h', 'derivatives_volume_24h'] as $field) {
        $payload = json_encode([
            'status' => ['error_code' => 0],
            'data'   => ['quote' => ['USD' => [
                'total_market_cap' => 1000.0,
                'total_volume_24h' => 100.0,
                $field             => 42.0,
            ]]],
        ]);
        $m = market_metrics(extract_sample('global_metrics', (string) $payload));
        assert_close(42.0, $m['derivatives_volume_24h'], "spelling {$field} must be read");
    }
});

test('turnover is not computed when market cap is zero', function (): void {
    $payload = json_encode([
        'status' => ['error_code' => 0],
        'data'   => ['quote' => ['USD' => ['total_market_cap' => 0, 'total_volume_24h' => 100.0]]],
    ]);
    $m = market_metrics(extract_sample('global_metrics', (string) $payload));
    assert_true(!array_key_exists('market_turnover', $m), 'no division by zero, and no zero stored in its place');
    assert_close(100.0, $m['total_volume_24h'], 'the fields that are present are still extracted');
});

test('exchange concentration is a real HHI', function (): void {
    $m = market_metrics(extract_fixture('exchange_listings', 'exchange_listings.json'));
    // 6bn, 2bn, 1bn of 9bn total. The zero-volume venue is excluded entirely.
    $expected = (6 / 9) ** 2 + (2 / 9) ** 2 + (1 / 9) ** 2;
    assert_close($expected, $m['exchange_hhi'], 'HHI is the sum of squared shares', 1e-12);
    assert_same(3.0, $m['exchange_count'], 'a venue reporting zero volume is not a venue');
    assert_close(1.0, $m['exchange_top5_share'], 'three venues means the top five are all of them');
});

// ---------------------------------------------------------------------------
// Voice
// ---------------------------------------------------------------------------

test('fear and greed reads the latest object', function (): void {
    $m = market_metrics(extract_fixture('fear_and_greed', 'fear_and_greed.json'));
    assert_close(63.0, $m['fear_greed'], 'the index value is the metric');
});

test('fear and greed reads the most recent point of a historical list', function (): void {
    $payload = json_encode([
        'status' => ['error_code' => 0],
        'data'   => [
            ['value' => 20, 'timestamp' => '1757000000'],
            ['value' => 71, 'timestamp' => '1757600000'],
        ],
    ]);
    $m = market_metrics(extract_sample('fear_and_greed', (string) $payload));
    assert_close(71.0, $m['fear_greed'], 'the last entry is the most recent one');
});

test('content feed gives post volume and engagement per post', function (): void {
    $m = market_metrics(extract_fixture('content_latest', 'content_latest.json'));
    assert_same(2.0, $m['post_count'], 'two posts in the fixture');
    assert_same(4.0, $m['post_comments'], 'comment counts arrive as strings and must still add up');
    assert_same(12.0, $m['post_likes'], 'like counts likewise');
    assert_close(8.0, $m['post_engagement'], '(4 + 12) / 2 posts');
});

test('trending lists are ranked by position in the payload', function (): void {
    $result = extract_fixture('trending_most_visited', 'trending_most_visited.json');
    $a = asset_metrics($result);
    assert_same(1.0, $a['1027.trend_rank'], 'first in the list is rank 1, not rank 0');
    assert_same(2.0, $a['1.trend_rank'], 'second is rank 2');
    assert_same(3.0, $a['5426.trend_rank'], 'third is rank 3');
    assert_same(3.0, market_metrics($result)['trending_asset_count'], 'and the list length is market-wide');
});

// ---------------------------------------------------------------------------
// Per asset
// ---------------------------------------------------------------------------

test('listings yields per-asset turnover and the universe', function (): void {
    $result = extract_fixture('listings_latest', 'listings_latest.json');
    $a = asset_metrics($result);

    assert_close(40.0 / 1200.0, $a['1.turnover'], 'BTC turnover is volume over market cap');
    assert_close(20.0 / 300.0, $a['1027.turnover'], 'ETH turnover likewise');
    assert_close(-12.5, $a['1.volume_change_24h'], 'a negative change stays negative');
    assert_same(1.0, $a['1.cmc_rank'], 'rank is stored so the universe can be reconstructed per sample');

    assert_same(3, count($result['universe']), 'every listed asset enters the universe');
    assert_same('Bitcoin', $result['universe'][0]['name'], 'with its name');
});

test('an asset with zero market cap gets no turnover but keeps its other fields', function (): void {
    $a = asset_metrics(extract_fixture('listings_latest', 'listings_latest.json'));
    assert_true(!array_key_exists('9999.turnover', $a), 'no division by zero for an unpriced asset');
    assert_close(5000000.0, $a['9999.volume_24h'], 'its volume is still a real measurement');
});

test('quotes handles both the object and the list-per-id v2 shapes', function (): void {
    $result = extract_fixture('quotes_latest', 'quotes_latest.json');
    $a = asset_metrics($result);
    assert_close(40.0 / 1200.0, $a['1.turnover'], 'the object shape is read');
    assert_close(20.0 / 300.0, $a['1027.turnover'], 'and so is the list-per-id shape');
    assert_same(2.0, market_metrics($result)['quotes_asset_count'], 'the count is what answers the batching question');
});

test('quotes_asset_count is what open question 6 is measured with', function (): void {
    // The poller assumes 100 ids per call are accepted. Nobody has confirmed that.
    // Comparing this count against the batch size requested is the confirmation, so
    // it must be a stored number rather than something read off a terminal once.
    $ids = range(1, 100);
    $data = [];
    foreach ($ids as $id) {
        $data[(string) $id] = [
            'id' => $id, 'symbol' => 'S' . $id,
            'quote' => ['USD' => ['volume_24h' => 10.0, 'market_cap' => 100.0]],
        ];
    }
    $payload = json_encode(['status' => ['error_code' => 0], 'data' => $data]);
    $m = market_metrics(extract_sample('quotes_latest', (string) $payload));
    assert_same(100.0, $m['quotes_asset_count'], 'a full batch must come back as 100 assets');
});

// ---------------------------------------------------------------------------
// Support
// ---------------------------------------------------------------------------

test('key info gives the credit budget from the API rather than a local tally', function (): void {
    $m = market_metrics(extract_fixture('key_info', 'key_info.json'));
    assert_same(4210.0, $m['credits_used_month'], 'used this month');
    assert_same(295790.0, $m['credits_left_month'], 'left this month');
    assert_same(480.0, $m['credits_used_today'], 'and today');
});

// ---------------------------------------------------------------------------
// The rule that holds the rebuild together
// ---------------------------------------------------------------------------

test('extraction is pure: the same payload gives the same rows every time', function (): void {
    $first  = extract_fixture('listings_latest', 'listings_latest.json');
    $second = extract_fixture('listings_latest', 'listings_latest.json');
    assert_same($first, $second, 'a rebuild must be able to re-derive history identically');
});

test('nothing extracted is a NaN or an infinity', function (): void {
    // DECIMAL(24,8) cannot store either, and PDO would fail the whole transaction
    // for one bad row — taking the rest of that sample down with it.
    foreach (['global_metrics', 'listings_latest', 'exchange_listings', 'content_latest'] as $endpoint) {
        $result = extract_fixture($endpoint, $endpoint . '.json');
        foreach (array_merge($result['market'], $result['asset']) as $row) {
            assert_true(is_finite($row['value']), "{$endpoint}.{$row['metric']} must be a finite number");
        }
    }
});
