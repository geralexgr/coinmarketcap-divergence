<?php
/**
 * Scoring tests.
 *
 * `scoring/README.md` says normalisation is the part that silently goes wrong, which is
 * why most of these are about the boundaries rather than the happy path: a clamp that
 * does not clamp, a missing input treated as a zero, or a percentile that peeks at the
 * future all produce a chart that looks entirely plausible and is wrong.
 *
 * No database. Everything here is the pure half of the scoring layer.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/lib/endpoints.php';
require_once __DIR__ . '/../app/scoring/inputs.php';
require_once __DIR__ . '/../app/scoring/normalise.php';
require_once __DIR__ . '/../app/scoring/score.php';
require_once __DIR__ . '/../app/scoring/recompute.php';

// ---------------------------------------------------------------------------
// Fixed-basis normalisation
// ---------------------------------------------------------------------------

test('fixed normalisation maps a range onto 0-100', function (): void {
    assert_close(0.0,   normalise_fixed(0, 0, 100), 'floor is 0');
    assert_close(100.0, normalise_fixed(100, 0, 100), 'ceiling is 100');
    assert_close(50.0,  normalise_fixed(50, 0, 100), 'midpoint is 50');
    assert_close(25.0,  normalise_fixed(0.01, 0.005, 0.025), 'an off-centre range still scales linearly');
});

test('fixed normalisation clamps rather than running off the axis', function (): void {
    // The quadrant is defined on 0-100. A point at 140 has nowhere to sit, so a reading
    // beyond the reference range is pinned to the edge instead of leaving the plot.
    assert_close(100.0, normalise_fixed(500, 0, 100), 'above the ceiling pins to 100');
    assert_close(0.0,   normalise_fixed(-20, 0, 100), 'below the floor pins to 0');
});

test('an inverted input points the other way', function (): void {
    assert_close(100.0, normalise_fixed(0, 0, 100, true), 'the floor scores highest when inverted');
    assert_close(0.0,   normalise_fixed(100, 0, 100, true), 'and the ceiling scores lowest');
    assert_close(70.0,  normalise_fixed(30, 0, 100, true), 'and the middle mirrors');
});

test('a degenerate reference range gives the midpoint, not a division by zero', function (): void {
    assert_close(50.0, normalise_fixed(7, 5, 5), 'floor equal to ceiling cannot be scaled');
});

// ---------------------------------------------------------------------------
// Percentile normalisation
// ---------------------------------------------------------------------------

test('percentile rank places a value within its own history', function (): void {
    $history = [1.0, 2.0, 3.0, 4.0];
    assert_close(0.0,   normalise_percentile(0.5, $history), 'below everything');
    assert_close(100.0, normalise_percentile(9.0, $history), 'above everything');
    assert_close(50.0,  normalise_percentile(2.5, $history), 'halfway through');
});

test('percentile rank of an unvarying series is the midpoint', function (): void {
    // A week of identical readings carries no information about where today sits, and
    // 50 is what "no information" should look like. Counting ties as "below" would
    // score a flat market at 100 and put it in the wrong quadrant every sample.
    assert_close(50.0, normalise_percentile(5.0, [5.0, 5.0, 5.0, 5.0]), 'ties count half');
});

test('percentile rank with no history is the midpoint, not zero', function (): void {
    assert_close(50.0, normalise_percentile(42.0, []), 'the first sample ranks against nothing');
});

// ---------------------------------------------------------------------------
// The basis switchover
// ---------------------------------------------------------------------------

test('the basis switches over after the fixed window, measured from the first sample', function (): void {
    $first = '2026-09-13 00:00:00.000';
    assert_same('fixed', basis_for($first, '2026-09-13 12:00:00.000'), 'day 1 is fixed');
    assert_same('fixed', basis_for($first, '2026-09-19 23:00:00.000'), 'day 7 is still fixed');
    assert_same('percentile', basis_for($first, '2026-09-20 00:00:00.000'), 'day 8 switches');
    assert_same('percentile', basis_for($first, '2026-10-30 00:00:00.000'), 'and stays switched');
});

// ---------------------------------------------------------------------------
// Axis composition — where a missing input is either handled or quietly lies
// ---------------------------------------------------------------------------

test('an axis weights its inputs', function (): void {
    $inputs = [
        scoring_input('a', 'A', 'global_metrics', 0, 100, 0.75, 'u', ''),
        scoring_input('b', 'B', 'global_metrics', 0, 100, 0.25, 'u', ''),
    ];
    $result = score_axis($inputs, ['a' => 100.0, 'b' => 0.0], 'fixed');

    assert_close(75.0, (float) $result['score'], 'the 0.75 input carries three quarters');
    assert_same(2, $result['used'], 'both inputs contributed');
});

test('a missing input is dropped and the survivors renormalise', function (): void {
    // The load-bearing one. On the Basic plan two of the three Voice inputs are 403, so
    // this path runs on every single sample the product will ever record.
    $inputs = [
        scoring_input('a', 'A', 'global_metrics', 0, 100, 0.40, 'u', ''),
        scoring_input('b', 'B', 'global_metrics', 0, 100, 0.35, 'u', ''),
        scoring_input('c', 'C', 'global_metrics', 0, 100, 0.25, 'u', ''),
    ];
    $result = score_axis($inputs, ['a' => 80.0], 'fixed');

    assert_close(80.0, (float) $result['score'], 'one surviving input is the whole axis, not 40% of it');
    assert_same(1, $result['used'], 'one input contributed');
    assert_same(3, $result['possible'], 'three were declared');
    assert_close(1.0, (float) $result['parts'][0]['weight_effective'], 'its effective weight is renormalised to 1');
});

test('a missing input is never scored as a zero', function (): void {
    // A zero reads as a measurement of a quiet market. Absence is a measurement of
    // nothing. If these ever render the same the product is lying about the market
    // rather than about the plan.
    $inputs = [
        scoring_input('a', 'A', 'global_metrics', 0, 100, 0.5, 'u', ''),
        scoring_input('b', 'B', 'global_metrics', 0, 100, 0.5, 'u', ''),
    ];
    $withGap = score_axis($inputs, ['a' => 60.0], 'fixed');
    $withZero = score_axis($inputs, ['a' => 60.0, 'b' => 0.0], 'fixed');

    assert_close(60.0, (float) $withGap['score'], 'the gap leaves the surviving input alone');
    assert_close(30.0, (float) $withZero['score'], 'a real zero drags the axis down');
});

test('an axis with no inputs at all scores null, not zero', function (): void {
    $inputs = [scoring_input('a', 'A', 'global_metrics', 0, 100, 1.0, 'u', '')];
    $result = score_axis($inputs, [], 'fixed');

    assert_same(null, $result['score'], 'nothing measured is not a measurement of nothing');
    assert_same(0, $result['used'], 'and nothing contributed');
});

test('a non-finite value is refused rather than poisoning the axis', function (): void {
    $inputs = [
        scoring_input('a', 'A', 'global_metrics', 0, 100, 0.5, 'u', ''),
        scoring_input('b', 'B', 'global_metrics', 0, 100, 0.5, 'u', ''),
    ];
    $result = score_axis($inputs, ['a' => 40.0, 'b' => INF], 'fixed');

    assert_same(1, $result['used'], 'the infinity is dropped');
    assert_close(40.0, (float) $result['score'], 'and the axis is still a number');
});

// ---------------------------------------------------------------------------
// Divergence and quadrants
// ---------------------------------------------------------------------------

test('divergence is money minus voice, matching the method page', function (): void {
    // docs/method.md defines the sign. An earlier schema comment had it the other way
    // round, which would have inverted every sentence on the main screen.
    assert_close(38.0,  divergence_of(41.0, 79.0), 'money ahead is positive');
    assert_close(-54.0, divergence_of(88.0, 34.0), 'voice ahead is negative');
    assert_close(0.0,   divergence_of(50.0, 50.0), 'level is zero');
});

test('the four quadrants are read off the midlines', function (): void {
    assert_same('loud_and_leveraged',         quadrant_of(80, 80), 'high voice, high money');
    assert_same('chatter_without_conviction', quadrant_of(80, 20), 'high voice, low money');
    assert_same('quiet_but_leveraged',        quadrant_of(20, 80), 'low voice, high money');
    assert_same('apathy',                     quadrant_of(20, 20), 'low voice, low money');
});

test('the midline itself belongs to the upper quadrant, consistently', function (): void {
    assert_same('loud_and_leveraged', quadrant_of(50, 50), 'exactly 50 on both axes');
    assert_same('quiet_but_leveraged', quadrant_of(49.99, 50), 'and the boundary does not flicker');
});

test('the divergence sentence describes the present and nothing else', function (): void {
    $sentences = [
        divergence_sentence(38.0),
        divergence_sentence(-38.0),
        divergence_sentence(1.0),
    ];

    // The product's one hard rule (CLAUDE.md): no sentence anywhere points at the
    // future. This is the caption on the largest number on the main screen, which makes
    // it the one most likely to drift into advice.
    foreach ($sentences as $sentence) {
        foreach ([' will ', ' could ', ' should ', ' expect', ' likely', ' suggests', ' signal'] as $forbidden) {
            assert_true(
                stripos($sentence, $forbidden) === false,
                "the readout sentence must not contain '{$forbidden}': {$sentence}"
            );
        }
    }

    assert_true(str_contains($sentences[0], 'Money committed is running ahead'), 'positive reads as money ahead');
    assert_true(str_contains($sentences[1], 'Narrative is running ahead'), 'negative reads as narrative ahead');
});

test('a score row needs both axes', function (): void {
    $voice = ['score' => null, 'used' => 0, 'possible' => 3, 'parts' => []];
    $money = ['score' => 70.0, 'used' => 2, 'possible' => 4, 'parts' => []];

    assert_same(null, compose_score($voice, $money, '2026-09-13 12:00:00.000', 'fixed'), 'half a point is not a point');
});

test('a composed row carries the basis, the version and how much of the method it saw', function (): void {
    $voice = ['score' => 41.0, 'used' => 1, 'possible' => 3, 'parts' => []];
    $money = ['score' => 79.0, 'used' => 4, 'possible' => 5, 'parts' => []];
    $row = compose_score($voice, $money, '2026-09-13 12:00:00.000', 'fixed');

    assert_close(38.0, (float) $row['divergence'], 'divergence is computed from the two axes');
    assert_same('quiet_but_leveraged', $row['quadrant'], 'and the quadrant with it');
    assert_same(1, $row['voice_inputs'], 'one Voice input was available');
    assert_same(8, $row['inputs_possible'], 'against eight declared across both axes');
    assert_same(METHOD_VERSION, $row['method_version'], 'the row records which method produced it');
});

// ---------------------------------------------------------------------------
// The series helpers — the ones that could quietly read the future
// ---------------------------------------------------------------------------

test('a value is read as of a moment and never from after it', function (): void {
    // If this ever looks forward, a recomputed history stops matching the one that would
    // have been computed live, and the trail becomes a thing that could not have existed.
    $points = [
        ['at' => '2026-09-13 12:00:00.000', 'value' => 1.0, 'raw_sample_id' => 1],
        ['at' => '2026-09-13 12:10:00.000', 'value' => 2.0, 'raw_sample_id' => 2],
        ['at' => '2026-09-13 12:20:00.000', 'value' => 3.0, 'raw_sample_id' => 3],
    ];

    assert_close(2.0, value_as_of($points, '2026-09-13 12:15:00.000', 45)['value'], 'the most recent prior reading');
    assert_close(3.0, value_as_of($points, '2026-09-13 12:20:00.000', 45)['value'], 'a reading exactly on the moment counts');
    assert_same(null, value_as_of($points, '2026-09-13 11:00:00.000', 45), 'nothing had been recorded yet');
});

test('inputs recorded seconds after the anchor still belong to that sample', function (): void {
    // The regression test for the bug that reached production. One poller run writes
    // global_metrics first and the derivatives endpoints a few seconds later, so the
    // anchor is always the EARLIEST row in its own cluster and a strictly-backward
    // lookup cannot see the rest of the run.
    //
    // Live, that scored the Money axis on one input of five while the readout panel
    // showed all five as current. The local seeder wrote every endpoint with an
    // identical timestamp and hid it completely, which is why this test uses the real
    // shape: an anchor, and siblings a few seconds after it.
    $anchor = '2026-09-13 11:05:46.701';
    $openInterest = [['at' => '2026-09-13 11:05:50.138', 'value' => 9.05e10, 'raw_sample_id' => 2]];
    $liquidations = [['at' => '2026-09-13 11:05:51.314', 'value' => 1.9e8,  'raw_sample_id' => 3]];

    assert_true(value_as_of($openInterest, $anchor, 45) !== null, 'a sibling 3s later is part of the same sample');
    assert_true(value_as_of($liquidations, $anchor, 45) !== null, 'and so is one 5s later');
    assert_close(9.05e10, value_as_of($openInterest, $anchor, 45)['value'], 'with its real value', 1.0);
});

test('the cluster window does not reach into the next poller run', function (): void {
    // Runs are five minutes apart at the fastest cron tick and the window is two
    // minutes, so a reading can never be claimed by the wrong moment. If this ever
    // fails, a score is being built from data that did not exist when it was taken.
    $anchor = '2026-09-13 11:05:00.000';
    $nextRun = [['at' => '2026-09-13 11:10:02.000', 'value' => 42.0, 'raw_sample_id' => 9]];

    assert_same(null, value_as_of($nextRun, $anchor, 45), 'the next run is not part of this moment');
    assert_true(SAMPLE_CLUSTER_SECONDS < 300, 'the window stays inside the minimum gap between runs');
});

test('a reading too old to be current is dropped rather than stretched', function (): void {
    $points = [['at' => '2026-09-13 00:00:00.000', 'value' => 1.0, 'raw_sample_id' => 1]];

    assert_same(null, value_as_of($points, '2026-09-13 12:00:00.000', 45), '12 hours is not current at a 45 minute tolerance');
    assert_close(1.0, value_as_of($points, '2026-09-13 12:00:00.000', 26 * 60)['value'], 'but it is within the daily tolerance');
});

test('fear and greed gets the daily tolerance it needs to appear at all', function (): void {
    // On a 45-minute rule the once-a-day Voice input would be absent from almost every
    // sample and the Voice axis would be nearly all gap.
    assert_true(input_max_age_minutes('fear_greed') >= 24 * 60, 'the daily input tolerates a day');
    assert_same(45, input_max_age_minutes('market_turnover'), 'a per-run input does not');
});

test('percentile history stops strictly before the moment being scored', function (): void {
    $points = [
        ['at' => '2026-09-13 12:00:00.000', 'value' => 1.0, 'raw_sample_id' => 1],
        ['at' => '2026-09-13 12:10:00.000', 'value' => 2.0, 'raw_sample_id' => 2],
        ['at' => '2026-09-13 12:20:00.000', 'value' => 3.0, 'raw_sample_id' => 3],
    ];
    $history = history_before($points, '2026-09-13 12:20:00.000');

    assert_same([1.0, 2.0], $history, 'the moment itself is not part of its own history');
});

test('history outside the trailing window is left out', function (): void {
    $points = [
        ['at' => '2026-07-01 00:00:00.000', 'value' => 99.0, 'raw_sample_id' => 1],
        ['at' => '2026-09-12 00:00:00.000', 'value' => 2.0,  'raw_sample_id' => 2],
    ];
    $history = history_before($points, '2026-09-13 00:00:00.000', 30);

    assert_same([2.0], $history, 'a reading from ten weeks ago is outside a 30 day window');
});

test('exchange reserve movement is derived as an absolute fraction of a day ago', function (): void {
    // Absolute because the method makes no claim about which way money leaving an
    // exchange points. That reading is an interpretation, and interpretation is the one
    // thing this product does not do.
    $series = ['exchange_reserve_usd' => [
        ['at' => '2026-09-12 00:00:00.000', 'value' => 100.0, 'raw_sample_id' => 1],
        ['at' => '2026-09-13 00:00:00.000', 'value' => 110.0, 'raw_sample_id' => 2],
        ['at' => '2026-09-14 00:00:00.000', 'value' =>  99.0, 'raw_sample_id' => 3],
    ]];
    $derived = derive_cross_sample_inputs($series);
    $change = $derived['exchange_reserve_change'];

    assert_close(0.10, $change[0]['value'], 'a 10% rise reads as 0.10', 1e-9);
    assert_close(0.10, $change[1]['value'], 'a 10% fall reads as 0.10 too', 1e-9);
});

test('reserve movement needs two readings and does not invent one', function (): void {
    $series = ['exchange_reserve_usd' => [
        ['at' => '2026-09-13 00:00:00.000', 'value' => 100.0, 'raw_sample_id' => 1],
    ]];
    assert_true(
        !isset(derive_cross_sample_inputs($series)['exchange_reserve_change']),
        'one reading is not a movement'
    );
});

// ---------------------------------------------------------------------------
// The method declaration itself
// ---------------------------------------------------------------------------

test('every declared input names an endpoint the catalogue knows', function (): void {
    // A typo here would silently mark a working input unavailable and quietly shrink the
    // method, which is exactly the kind of failure nothing else would catch.
    foreach (['market', 'asset'] as $scope) {
        foreach (['voice', 'money'] as $axis) {
            foreach (axis_inputs($axis, $scope) as $input) {
                assert_true(
                    endpoint_by_key($input['endpoint']) !== null,
                    "{$scope}.{$axis} input '{$input['metric']}' names unknown endpoint '{$input['endpoint']}'"
                );
            }
        }
    }
});

test('every declared input has a usable reference range', function (): void {
    foreach (['market', 'asset'] as $scope) {
        foreach (['voice', 'money'] as $axis) {
            foreach (axis_inputs($axis, $scope) as $input) {
                assert_true(
                    $input['ceiling'] > $input['floor'],
                    "{$scope}.{$axis} input '{$input['metric']}' has a ceiling at or below its floor"
                );
                assert_true(
                    $input['rationale'] !== '',
                    "{$scope}.{$axis} input '{$input['metric']}' has no stated rationale, so the method page cannot explain it"
                );
            }
        }
    }
});

test('availability is read from measured plan access, not restated', function (): void {
    $access = endpoint_access_results();

    foreach (['market', 'asset'] as $scope) {
        foreach (['voice', 'money'] as $axis) {
            foreach (available_inputs($axis, $scope) as $input) {
                assert_same(
                    'ok',
                    $access[$input['endpoint']] ?? 'ok',
                    "{$scope}.{$axis} offers '{$input['metric']}' from a forbidden endpoint"
                );
            }
        }
    }
});

test('both axes still have at least one callable input on this plan', function (): void {
    // If this fails, the product has no chart. It is the check that would have caught
    // the Basic-plan discovery on day one instead of on day two.
    foreach (['market', 'asset'] as $scope) {
        foreach (['voice', 'money'] as $axis) {
            assert_true(
                available_inputs($axis, $scope) !== [],
                "{$scope}.{$axis} has no callable input at all — there is nothing to plot"
            );
        }
    }
});

test('the method describes itself for the method page and the MCP tool', function (): void {
    $method = method_description();

    assert_same(METHOD_VERSION, $method['method_version'], 'the version is published');
    assert_same('money - voice', $method['divergence_formula'], 'so is the formula');
    assert_true(count($method['axes']) === 4, 'all four scope-and-axis combinations are described');

    $voice = $method['axes']['market.voice'];
    assert_true(count($voice) === 3, 'the forbidden Voice inputs stay declared rather than disappearing');
    $availableCount = count(array_filter($voice, static fn(array $i): bool => $i['available']));
    assert_same(1, $availableCount, 'and exactly one of them is callable on the Basic plan');
});
