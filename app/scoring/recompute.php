<?php
/**
 * Turning recorded metrics into scores.
 *
 * This is the only file in `scoring/` that touches the database, and it does two things
 * the pure half cannot: it decides what counts as one sample *moment* when the inputs
 * arrive seconds apart from different endpoints, and it computes the handful of inputs
 * that need two samples to exist at all.
 *
 * Everything it writes is disposable. `scores` is rebuilt from `market_metric` and
 * `asset_metric`, which are themselves rebuilt from `raw_samples`. Nothing here is a
 * source of truth for anything.
 */

declare(strict_types=1);

require_once __DIR__ . '/inputs.php';
require_once __DIR__ . '/normalise.php';
require_once __DIR__ . '/score.php';

/**
 * The endpoint whose sample times define the market-wide moments to score.
 *
 * One poller run writes several rows a few seconds apart, so "the sample at 14:20" is a
 * small cluster rather than an instant. Rather than rounding time into buckets — which
 * puts the boundary in an arbitrary place and splits a run in half whenever a tick
 * drifts across it — one endpoint anchors the moment and every other input is read as
 * of that instant. global-metrics is the anchor because it is polled every run and
 * carries more of the Money axis than anything else.
 */
const MARKET_ANCHOR_ENDPOINT = 'global_metrics';

/**
 * How much of the recent series is rescored on every run, even where rows already exist.
 *
 * Scoring runs on its own cron entry, offset from extraction, so a moment can be scored
 * from a partially-extracted set of payloads. Redoing the tail lets those rows improve
 * once the rest of the data lands, rather than freezing a thin score into the trail.
 */
const RESCORE_TAIL_HOURS = 6;

/**
 * How stale an input may be and still be read as current, per metric, in minutes.
 *
 * Fear and greed is the reason this is not one number. CoinMarketCap updates it once a
 * day, so on a 45-minute rule the Voice axis would be absent from 97% of samples and the
 * chart would be almost entirely gap. 26 hours accepts yesterday's reading as today's,
 * which is what it is — and the app labels the Voice axis as daily so nobody reads the
 * flat stretches as a market that went quiet.
 */
function input_max_age_minutes(string $metric): int
{
    return match ($metric) {
        'fear_greed'              => 26 * 60,
        'exchange_reserve_change' => 3 * 60,
        default                   => 45,
    };
}

/**
 * Every recorded value of the given metrics, oldest first.
 *
 * Loaded once and held in memory rather than queried per sample moment: three weeks of
 * market metrics is a few tens of thousands of rows, and the alternative is one query
 * per metric per moment on a shared host.
 *
 * @param array<int,string> $metrics
 * @return array<string,array<int,array{at:string,value:float,raw_sample_id:int}>>
 */
function load_market_series(PDO $pdo, array $metrics): array
{
    if ($metrics === []) {
        return [];
    }

    $in = implode(',', array_fill(0, count($metrics), '?'));
    $stmt = $pdo->prepare(
        "SELECT metric, value, sampled_at, raw_sample_id
           FROM market_metric
          WHERE metric IN ({$in})
       ORDER BY sampled_at ASC"
    );
    $stmt->execute(array_values($metrics));

    $series = [];
    foreach ($stmt as $row) {
        $series[$row['metric']][] = [
            'at'            => (string) $row['sampled_at'],
            'value'         => (float) $row['value'],
            'raw_sample_id' => (int) $row['raw_sample_id'],
        ];
    }

    return $series;
}

/**
 * The inputs that cannot be extracted from a single payload, because they are the
 * difference between two.
 *
 * Extraction is deliberately single-payload so that a rebuild is order-independent
 * (rule 2 in `lib/extract.php`). Anything cross-sample therefore lands here, where the
 * whole series is visible.
 *
 * `exchange_reserve_change` is the absolute fractional move in the reserve held on the
 * tracked exchange against roughly 24 hours earlier. Absolute because the method makes
 * no claim about which direction money leaving an exchange points — that reading is
 * exactly the kind of interpretation this product does not do.
 *
 * @param array<string,array<int,array{at:string,value:float,raw_sample_id:int}>> $series
 * @return array<string,array<int,array{at:string,value:float,raw_sample_id:int}>>
 */
function derive_cross_sample_inputs(array $series): array
{
    $reserves = $series['exchange_reserve_usd'] ?? [];
    if (count($reserves) < 2) {
        return $series;
    }

    $derived = [];
    foreach ($reserves as $i => $point) {
        $target = strtotime($point['at'] . ' UTC') - 86400;

        // The most recent reading at or before 24h ago. Falls back to the oldest
        // reading there is, so the input starts working before a full day is banked —
        // over a shorter baseline, which understates the move rather than inventing one.
        $baseline = null;
        for ($j = $i; $j >= 0; $j--) {
            $baseline = $reserves[$j];
            if (strtotime($reserves[$j]['at'] . ' UTC') <= $target) {
                break;
            }
        }

        if ($baseline === null || $baseline['value'] <= 0.0 || $baseline['at'] === $point['at']) {
            continue;
        }

        $derived[] = [
            'at'            => $point['at'],
            'value'         => abs($point['value'] - $baseline['value']) / $baseline['value'],
            'raw_sample_id' => $point['raw_sample_id'],
        ];
    }

    if ($derived !== []) {
        $series['exchange_reserve_change'] = $derived;
    }

    return $series;
}

/**
 * How far past the anchor a reading may sit and still belong to the same sample moment.
 *
 * A poller run is not an instant. It writes global_metrics, then the derivatives
 * endpoints, then the rest, a few seconds apart — five HTTP calls at 200-400ms each. The
 * anchor is whichever lands first, so every other input in the same run is a few seconds
 * *after* it.
 *
 * A strictly-backward lookup therefore cannot see them, and the Money axis scored on one
 * input instead of five while the readout panel showed all five current. Found on the
 * live deployment; the local seeder had written every endpoint with an identical
 * timestamp and hid it completely.
 *
 * 120 seconds is comfortably longer than a run (about two seconds) and comfortably
 * shorter than the gap between runs (five minutes at the fastest cron tick), so a
 * reading can never be claimed by the wrong moment.
 */
const SAMPLE_CLUSTER_SECONDS = 120;

/**
 * The value of one metric as of one moment, or null if the nearest reading is too old to
 * be called current.
 *
 * "As of" means the sample cluster the anchor belongs to, not the instant it carries —
 * see SAMPLE_CLUSTER_SECONDS. Beyond that window it never looks forward: a score for
 * 14:20 is built only from what the recorder had by 14:22, which is what keeps a
 * recomputed history identical to the one that would have been computed live.
 *
 * @param array<int,array{at:string,value:float,raw_sample_id:int}> $points Oldest first.
 */
function value_as_of(array $points, string $at, int $maxAgeMinutes): ?array
{
    $target = strtotime($at . ' UTC');
    if ($target === false) {
        return null;
    }

    $ceiling = $target + SAMPLE_CLUSTER_SECONDS;

    $found = null;
    foreach ($points as $point) {
        $t = strtotime($point['at'] . ' UTC');
        if ($t === false || $t > $ceiling) {
            break;
        }
        $found = $point;
    }

    if ($found === null) {
        return null;
    }

    // Age is measured from the anchor, and a reading inside the cluster has an age at or
    // below zero — which is within any tolerance, as it should be.
    $age = ($target - strtotime($found['at'] . ' UTC')) / 60;

    return $age <= $maxAgeMinutes ? $found : null;
}

/**
 * Prior values of one metric inside the percentile window, for the percentile basis.
 *
 * Strictly before the moment being scored, for the same reason as above: a percentile
 * that included the future would make every recomputed score differ from the live one.
 *
 * @param array<int,array{at:string,value:float,raw_sample_id:int}> $points
 * @return array<int,float>
 */
function history_before(array $points, string $at, int $windowDays = PERCENTILE_WINDOW_DAYS): array
{
    $target = strtotime($at . ' UTC');
    $floor = $target - $windowDays * 86400;

    $values = [];
    foreach ($points as $point) {
        $t = strtotime($point['at'] . ' UTC');
        if ($t >= $target) {
            break;
        }
        if ($t >= $floor) {
            $values[] = $point['value'];
        }
    }

    return $values;
}

/**
 * The earliest recorded reading across one axis's declared inputs, or null if it has
 * none.
 *
 * This is what decides when the axis switches to percentile rank, and it is
 * deliberately not the recorder's start date. Those were the same number until the
 * fear-and-greed history was backfilled; now Voice's earliest reading is roughly 500
 * days before this deployment's first sample and Money's is the first sample itself.
 *
 * The earliest across the axis, not the latest, because an axis whose weights are
 * renormalised over whatever survived is already comfortable with its inputs having
 * different coverage — and the alternative would hold the whole axis back for one
 * late-added input.
 *
 * @param array<int,array<string,mixed>> $inputs From `available_inputs()`.
 * @param array<string,array<int,array{at:string,value:float,raw_sample_id:int}>> $series
 */
function axis_history_start(array $inputs, array $series): ?string
{
    $earliest = null;

    foreach ($inputs as $input) {
        $points = $series[(string) $input['metric']] ?? [];
        if ($points === []) {
            continue;
        }
        // load_market_series orders by sampled_at ascending, so the first point is the
        // oldest this metric has.
        $at = (string) $points[0]['at'];
        if ($earliest === null || $at < $earliest) {
            $earliest = $at;
        }
    }

    return $earliest;
}

/**
 * Write one batch of score rows.
 *
 * Upserts on `(scope, cmc_id, sampled_at, method_version)`, so recomputing over ground
 * already covered updates in place. Running the scorer twice must produce the same
 * table, not two copies of the trail.
 *
 * @param array<int,array<string,mixed>> $rows
 */
function write_scores(PDO $pdo, array $rows, int $chunk = 200): int
{
    $written = 0;

    foreach (array_chunk($rows, max(1, $chunk)) as $batch) {
        $placeholders = [];
        $values = [];
        foreach ($batch as $row) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $values,
                $row['scope'],
                $row['cmc_id'],
                $row['sampled_at'],
                $row['voice'],
                $row['money'],
                $row['divergence'],
                $row['quadrant'],
                $row['basis'],
                $row['voice_basis'] ?? $row['basis'],
                $row['money_basis'] ?? $row['basis'],
                $row['method_version'],
                $row['voice_inputs'],
                $row['money_inputs'],
                $row['inputs_possible'],
                utc_now()
            );
        }

        $stmt = $pdo->prepare(
            'INSERT INTO scores
                (scope, cmc_id, sampled_at, voice, money, divergence, quadrant, basis,
                 voice_basis, money_basis,
                 method_version, voice_inputs, money_inputs, inputs_possible, computed_at)
             VALUES ' . implode(', ', $placeholders) . '
             ON DUPLICATE KEY UPDATE
                voice           = VALUES(voice),
                money           = VALUES(money),
                divergence      = VALUES(divergence),
                quadrant        = VALUES(quadrant),
                basis           = VALUES(basis),
                voice_basis     = VALUES(voice_basis),
                money_basis     = VALUES(money_basis),
                voice_inputs    = VALUES(voice_inputs),
                money_inputs    = VALUES(money_inputs),
                inputs_possible = VALUES(inputs_possible),
                computed_at     = VALUES(computed_at)'
        );
        $stmt->execute($values);
        $written += count($batch);
    }

    return $written;
}

/**
 * Score every market-wide sample moment that does not already have a row.
 *
 * @return array{moments:int,written:int,skipped:int,reasons:array<string,int>}
 */
function recompute_market(PDO $pdo, bool $rebuild = false, ?int $limit = null, bool $dryRun = false): array
{
    $inputs = [
        'voice' => available_inputs('voice', 'market'),
        'money' => available_inputs('money', 'market'),
    ];

    // exchange_reserve_usd is loaded because exchange_reserve_change is derived from it,
    // not because anything scores it directly.
    $metrics = array_merge(
        array_column($inputs['voice'], 'metric'),
        array_column($inputs['money'], 'metric'),
        ['exchange_reserve_usd']
    );

    $series = derive_cross_sample_inputs(load_market_series($pdo, array_values(array_unique($metrics))));

    // The anchor moments: one per run of the endpoint that defines a market sample.
    $anchor = $pdo->prepare(
        'SELECT DISTINCT sampled_at
           FROM market_metric
          WHERE endpoint = ?
       ORDER BY sampled_at ASC'
    );
    $anchor->execute([MARKET_ANCHOR_ENDPOINT]);
    $moments = $anchor->fetchAll(PDO::FETCH_COLUMN);

    if ($moments === []) {
        return ['moments' => 0, 'written' => 0, 'skipped' => 0, 'reasons' => ['nothing recorded yet' => 1]];
    }

    $existing = [];
    if (!$rebuild) {
        $done = $pdo->prepare('SELECT sampled_at FROM scores WHERE scope = ? AND cmc_id = 0 AND method_version = ?');
        $done->execute(['market', METHOD_VERSION]);
        $existing = array_flip($done->fetchAll(PDO::FETCH_COLUMN));

        // Always redo the recent tail, even where a row already exists.
        //
        // The extractor and the scorer are separate cron jobs on offset minutes, so a
        // moment can be scored before the extractor has finished reading every payload
        // belonging to it — and without this it would keep that thinner score forever.
        // Rescoring the last few hours costs almost nothing and makes the series
        // self-healing instead of permanently degraded by one unlucky tick.
        $cutoff = time() - RESCORE_TAIL_HOURS * 3600;
        foreach (array_keys($existing) as $at) {
            if (strtotime((string) $at . ' UTC') >= $cutoff) {
                unset($existing[$at]);
            }
        }
    }

    // Each axis reaches percentile rank on its own schedule, from the history its own
    // inputs have rather than from the date this deployment started recording. The
    // fear-and-greed backfill gives Voice 500 days; no Money input has any history at
    // all beyond what was recorded here, because CMC publishes none. See D22.
    $axisStart = [
        'voice' => axis_history_start($inputs['voice'], $series),
        'money' => axis_history_start($inputs['money'], $series),
    ];

    $rows = [];
    $skipped = 0;
    $reasons = [];

    foreach ($moments as $at) {
        $at = (string) $at;
        if (isset($existing[$at])) {
            continue;
        }
        if ($limit !== null && count($rows) >= $limit) {
            break;
        }

        $basis = [];
        $axes = [];

        foreach ($inputs as $axis => $declared) {
            $basis[$axis] = basis_for_axis($axisStart[$axis], $at);
            $values = [];
            $history = [];
            foreach ($declared as $input) {
                $metric = (string) $input['metric'];
                $point = value_as_of($series[$metric] ?? [], $at, input_max_age_minutes($metric));
                if ($point === null) {
                    continue;
                }
                $values[$metric] = $point['value'];
                if ($basis[$axis] === 'percentile') {
                    // Per input, not per axis: the window is a property of how much
                    // history that input has. fear_greed is backfilled 500 days and
                    // everything else has only what was recorded.
                    $history[$metric] = history_before($series[$metric], $at, percentile_window_days($metric));
                }
            }
            $axes[$axis] = score_axis($declared, $values, $basis[$axis], $history);
        }

        $row = compose_score(
            $axes['voice'], $axes['money'], $at,
            $basis['voice'], 0, 'market', $basis['money']
        );
        if ($row === null) {
            $skipped++;
            $reason = $axes['voice']['score'] === null ? 'no Voice input in range' : 'no Money input in range';
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            continue;
        }

        $rows[] = $row;
    }

    $written = ($dryRun || $rows === []) ? 0 : write_scores($pdo, $rows);

    return [
        'moments' => count($moments),
        'written' => $dryRun ? count($rows) : $written,
        'skipped' => $skipped,
        'reasons' => $reasons,
    ];
}

/**
 * Score every asset in each recorded universe snapshot.
 *
 * **A different normalisation, on purpose.** Market-wide scores rank a reading against
 * the same reading's own past, which is why they need a week of history before they mean
 * much. Per-asset scores rank each asset against *the rest of the universe at the same
 * moment* — turnover in the 92nd percentile of the top 200 right now. That is the
 * question a screener is actually asked, it needs no banked history so the table works
 * from the first sample, and it is why the basis on these rows reads `cross_section`
 * rather than `fixed` or `percentile`. The method page states this plainly; the two
 * kinds of score are not interchangeable and the app does not mix them on one chart.
 *
 * @return array{moments:int,written:int,skipped:int,reasons:array<string,int>}
 */
function recompute_assets(PDO $pdo, bool $rebuild = false, ?int $limit = null, bool $dryRun = false): array
{
    $inputs = [
        'voice' => available_inputs('voice', 'asset'),
        'money' => available_inputs('money', 'asset'),
    ];
    $metrics = array_values(array_unique(array_merge(
        array_column($inputs['voice'], 'metric'),
        array_column($inputs['money'], 'metric')
    )));

    if ($metrics === []) {
        return ['moments' => 0, 'written' => 0, 'skipped' => 0, 'reasons' => ['no per-asset input is callable on this plan' => 1]];
    }

    // listings_latest is the anchor: it is the call that defines the universe and
    // carries every per-asset input in the same payload, so one sample is one complete
    // cross-section rather than a stitched-together one.
    $anchor = $pdo->prepare(
        'SELECT DISTINCT sampled_at
           FROM asset_metric
          WHERE endpoint = ?
       ORDER BY sampled_at ASC'
    );
    $anchor->execute(['listings_latest']);
    $moments = $anchor->fetchAll(PDO::FETCH_COLUMN);

    if ($moments === []) {
        return ['moments' => 0, 'written' => 0, 'skipped' => 0, 'reasons' => ['no listings sample recorded yet' => 1]];
    }

    $scored = [];
    if (!$rebuild) {
        $done = $pdo->prepare('SELECT DISTINCT sampled_at FROM scores WHERE scope = ? AND method_version = ?');
        $done->execute(['asset', METHOD_VERSION]);
        $scored = array_flip($done->fetchAll(PDO::FETCH_COLUMN));
    }

    $in = implode(',', array_fill(0, count($metrics), '?'));
    $load = $pdo->prepare(
        "SELECT cmc_id, metric, value
           FROM asset_metric
          WHERE sampled_at = ? AND metric IN ({$in})"
    );

    $rows = [];
    $moment = 0;
    $skipped = 0;
    $reasons = [];

    foreach ($moments as $at) {
        $at = (string) $at;
        if (isset($scored[$at])) {
            continue;
        }
        if ($limit !== null && $moment >= $limit) {
            break;
        }
        $moment++;

        $load->execute(array_merge([$at], $metrics));

        /** @var array<int,array<string,float>> $byAsset */
        $byAsset = [];
        /** @var array<string,array<int,float>> $crossSection */
        $crossSection = [];
        foreach ($load as $row) {
            $id = (int) $row['cmc_id'];
            $metric = (string) $row['metric'];
            $value = (float) $row['value'];
            $byAsset[$id][$metric] = $value;
            $crossSection[$metric][] = $value;
        }

        if ($byAsset === []) {
            $skipped++;
            $reasons['listings sample carried none of the scored metrics'] = ($reasons['listings sample carried none of the scored metrics'] ?? 0) + 1;
            continue;
        }

        foreach ($byAsset as $cmcId => $values) {
            $axes = [];
            foreach ($inputs as $axis => $declared) {
                // The comparison set is the rest of the universe at this instant, not
                // this asset's own past — see the note above the function.
                $axes[$axis] = score_axis($declared, $values, 'percentile', $crossSection);
            }

            $row = compose_score($axes['voice'], $axes['money'], $at, 'cross_section', $cmcId, 'asset');
            if ($row === null) {
                $skipped++;
                continue;
            }
            $rows[] = $row;
        }
    }

    $written = ($dryRun || $rows === []) ? 0 : write_scores($pdo, $rows);

    return [
        'moments' => $moment,
        'written' => $dryRun ? count($rows) : $written,
        'skipped' => $skipped,
        'reasons' => $reasons,
    ];
}

/** True when the score columns this version writes are present. */
function scores_schema_is_present(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT voice, money, divergence, quadrant, basis, voice_inputs, money_inputs, inputs_possible FROM scores LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}
