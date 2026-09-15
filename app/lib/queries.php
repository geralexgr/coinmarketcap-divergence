<?php
/**
 * Every read the surfaces make.
 *
 * The web app and the MCP server both go through this file and neither writes a query
 * of its own. That is not tidiness: the two are supposed to answer identically, and the
 * only way to guarantee it is for them to run the same SQL. D8 calls the MCP layer a
 * thin wrapper over the queries the web app already needs — this file is what makes
 * that true rather than aspirational.
 *
 * Nothing here writes. The only writer in the system is cron.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** The windows the UI and the tools both offer, in hours. `all` is null. */
function window_hours(string $window): ?int
{
    return match ($window) {
        '24h'   => 24,
        '7d'    => 24 * 7,
        '30d'   => 24 * 30,
        default => null,
    };
}

/**
 * The most recent market-wide score.
 *
 * @return array<string,mixed>|null
 */
function latest_market_score(PDO $pdo, int $methodVersion): ?array
{
    $stmt = $pdo->prepare(
        'SELECT sampled_at, voice, money, divergence, quadrant, basis,
                voice_basis, money_basis,
                method_version, voice_inputs, money_inputs, inputs_possible
           FROM scores
          WHERE scope = ? AND cmc_id = 0 AND method_version = ?
       ORDER BY sampled_at DESC
          LIMIT 1'
    );
    $stmt->execute(['market', $methodVersion]);
    $row = $stmt->fetch();

    return $row === false ? null : normalise_score_row($row);
}

/**
 * The score closest to a given number of hours ago, for the "up 26 this week" figures.
 *
 * Deliberately not "the score exactly 7 days ago": recording gaps mean that row may not
 * exist. The nearest row inside a tolerance is honest; interpolating between two rows
 * either side of a gap would invent a measurement.
 *
 * @return array<string,mixed>|null
 */
function market_score_hours_ago(PDO $pdo, int $methodVersion, int $hours, int $toleranceHours = 12): ?array
{
    $stmt = $pdo->prepare(
        'SELECT sampled_at, voice, money, divergence, quadrant, basis,
                method_version, voice_inputs, money_inputs, inputs_possible
           FROM scores
          WHERE scope = ? AND cmc_id = 0 AND method_version = ?
            AND sampled_at BETWEEN DATE_SUB(UTC_TIMESTAMP(3), INTERVAL ? HOUR)
                               AND DATE_SUB(UTC_TIMESTAMP(3), INTERVAL ? HOUR)
       ORDER BY sampled_at DESC
          LIMIT 1'
    );
    $stmt->execute(['market', $methodVersion, $hours + $toleranceHours, max(0, $hours - $toleranceHours)]);
    $row = $stmt->fetch();

    return $row === false ? null : normalise_score_row($row);
}

/**
 * The trail: market scores over a window, oldest first.
 *
 * @return array<int,array<string,mixed>>
 */
function market_series(PDO $pdo, int $methodVersion, string $window = '7d', int $limit = 2000): array
{
    $hours = window_hours($window);

    $sql =
        'SELECT sampled_at, voice, money, divergence, quadrant, basis
           FROM scores
          WHERE scope = :scope AND cmc_id = 0 AND method_version = :version';
    if ($hours !== null) {
        $sql .= ' AND sampled_at >= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL :hours HOUR)';
    }
    // Newest first with a cap, then reversed: a long window must not silently return
    // the *oldest* N rows and present them as "now".
    $sql .= ' ORDER BY sampled_at DESC LIMIT :limit';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':scope', 'market');
    $stmt->bindValue(':version', $methodVersion, PDO::PARAM_INT);
    if ($hours !== null) {
        $stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return array_map('normalise_score_row', array_reverse($stmt->fetchAll()));
}

/**
 * The screener: the most recent score for every asset that has one.
 *
 * One row per asset from the latest cross-section, joined to the universe for the name.
 * The subquery pins every row to a single sample moment rather than mixing assets from
 * different minutes into one table, which would make the ordering meaningless.
 *
 * **Stablecoins are excluded by default** (D21). A stablecoin has enormous turnover and
 * essentially no narrative *by construction*, so it pins the Money axis high and the
 * Voice axis low and lands at the top of a gap-ranked table every single time. On the
 * live screener four of the top nine rows were USDT, USD1 and USDG — the most prominent
 * findings in the product were a definition, not a measurement. They remain one filter
 * click away, because their absence should be visible rather than silent.
 *
 * @return array<int,array<string,mixed>>
 */
function latest_asset_scores(
    PDO $pdo,
    int $methodVersion,
    string $sort = 'gap',
    ?string $quadrant = null,
    int $limit = 100,
    bool $includeStablecoins = false,
    ?int $minRank = null,
    ?int $maxRank = null
): array {
    $order = match ($sort) {
        'voice'  => 's.voice DESC',
        'money'  => 's.money DESC',
        'symbol' => 'u.symbol ASC',
        'rank'   => 'u.rank_last ASC',
        default  => 'ABS(s.divergence) DESC',
    };

    $sql =
        'SELECT s.cmc_id, u.symbol, u.name, u.rank_last, u.is_stablecoin,
                s.voice, s.money, s.divergence, s.quadrant, s.basis, s.sampled_at,
                s.voice_inputs, s.money_inputs, s.inputs_possible, s.method_version
           FROM scores s
           JOIN asset_universe u ON u.cmc_id = s.cmc_id
          WHERE s.scope = :scope
            AND s.method_version = :version
            AND s.sampled_at = (
                SELECT MAX(sampled_at) FROM scores
                 WHERE scope = :scope2 AND method_version = :version2
            )';
    if (!$includeStablecoins) {
        $sql .= ' AND u.is_stablecoin = 0';
    }
    if ($quadrant !== null) {
        $sql .= ' AND s.quadrant = :quadrant';
    }
    // A rank band, because the discovery value is not evenly spread across the universe.
    // The top twenty are known to anyone who would open this page, and a gap-ranked table
    // over 200 assets spends its first screen on them. Excluding a band is a filter on
    // what is shown and changes no score: each asset is still ranked against the whole
    // recorded cross-section, not against the survivors of the filter.
    if ($minRank !== null) {
        $sql .= ' AND u.rank_last >= :min_rank';
    }
    if ($maxRank !== null) {
        $sql .= ' AND u.rank_last <= :max_rank';
    }
    $sql .= " ORDER BY {$order} LIMIT :limit";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':scope', 'asset');
    $stmt->bindValue(':scope2', 'asset');
    $stmt->bindValue(':version', $methodVersion, PDO::PARAM_INT);
    $stmt->bindValue(':version2', $methodVersion, PDO::PARAM_INT);
    if ($quadrant !== null) {
        $stmt->bindValue(':quadrant', $quadrant);
    }
    if ($minRank !== null) {
        $stmt->bindValue(':min_rank', $minRank, PDO::PARAM_INT);
    }
    if ($maxRank !== null) {
        $stmt->bindValue(':max_rank', $maxRank, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $stmt->execute();

    return array_map('normalise_score_row', $stmt->fetchAll());
}

/**
 * How many assets were in one recorded cross-section.
 *
 * Derived by counting rows rather than stored on them, so it needs no column and works
 * over history already on disk.
 */
function asset_cross_section_size(PDO $pdo, int $methodVersion, string $at): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM scores WHERE scope = ? AND method_version = ? AND sampled_at = ?'
    );
    $stmt->execute(['asset', $methodVersion, $at]);

    return (int) $stmt->fetchColumn();
}

/**
 * The two cross-sections a movers comparison would run between, and whether comparing
 * them actually measures anything.
 *
 * **Why this check has to exist.** A per-asset score is a percentile rank against the
 * rest of the universe at that instant (D17) — not against the asset's own past. So the
 * comparison set is part of the measurement, and if the universe changed size between
 * the two cross-sections, every rank in one of them was taken against a different
 * population. The difference is then mostly an artefact of the change.
 *
 * This is not hypothetical. Raising the universe from 100 to 200 assets (D23) produced a
 * live movers table with a **median absolute change of 31 points and 52 of 86 assets
 * crossing a midline in 24 hours** — none of which was market movement. An asset ranked
 * 50th of 100 sits at the 50th percentile; ranked 50th of 200 it sits at the 75th, and
 * it did not move.
 *
 * So the window reports itself as not comparable and the movers table shows nothing with
 * a reason, the same way a recording gap is shown as a gap rather than interpolated
 * across. It is self-healing: once both ends of the window fall after the change, the
 * table returns on its own.
 *
 * `$sizeTolerance` is the fraction the two cross-sections may differ by and still be
 * compared. Natural rank churn moves a handful of assets in and out of the top 200 each
 * day, which is noise at this scale; a deliberate change to `asset_universe` is not.
 *
 * @return array{
 *   from:?string, to:?string, from_size:int, to_size:int,
 *   hours:int, comparable:bool, reason:?string
 * }
 */
function asset_movers_window(
    PDO $pdo,
    int $methodVersion,
    int $hours = 24,
    int $toleranceHours = 12,
    float $sizeTolerance = 0.10
): array {
    $blank = [
        'from' => null, 'to' => null, 'from_size' => 0, 'to_size' => 0,
        'hours' => $hours, 'comparable' => false, 'reason' => null,
    ];

    $latest = $pdo->prepare('SELECT MAX(sampled_at) FROM scores WHERE scope = ? AND method_version = ?');
    $latest->execute(['asset', $methodVersion]);
    $now = $latest->fetchColumn();
    if ($now === false || $now === null) {
        return ['reason' => 'No per-asset scores have been recorded yet.'] + $blank;
    }

    $baselineAt = $pdo->prepare(
        'SELECT MAX(sampled_at) FROM scores
          WHERE scope = ? AND method_version = ?
            AND sampled_at <= DATE_SUB(?, INTERVAL ? HOUR)
            AND sampled_at >= DATE_SUB(?, INTERVAL ? HOUR)'
    );
    $baselineAt->execute(['asset', $methodVersion, $now, $hours, $now, $hours + $toleranceHours]);
    $then = $baselineAt->fetchColumn();
    if ($then === false || $then === null) {
        return [
            'to'     => (string) $now,
            'reason' => 'No cross-section was recorded far enough back to compare against. '
                      . 'Both ends of a comparison have to be samples that were actually taken.',
        ] + $blank;
    }

    $toSize   = asset_cross_section_size($pdo, $methodVersion, (string) $now);
    $fromSize = asset_cross_section_size($pdo, $methodVersion, (string) $then);

    $window = [
        'from' => (string) $then, 'to' => (string) $now,
        'from_size' => $fromSize, 'to_size' => $toSize,
        'hours' => $hours, 'comparable' => true, 'reason' => null,
    ];

    $larger = max($fromSize, $toSize);
    if ($larger === 0) {
        return ['comparable' => false, 'reason' => 'One of the two cross-sections is empty.'] + $window;
    }

    if (abs($toSize - $fromSize) / $larger > $sizeTolerance) {
        return [
            'comparable' => false,
            'reason'     => sprintf(
                'The tracked universe changed size between these two samples — %d assets then, '
                . '%d now. Per-asset scores are ranks against the universe at that instant, so a '
                . 'change measured across that boundary would mostly be the boundary. This returns '
                . 'on its own once both ends fall on the same side of it.',
                $fromSize,
                $toSize
            ),
        ] + $window;
    }

    return $window;
}

/**
 * Assets whose gap moved the most, rather than whose gap is the largest.
 *
 * The `gap` sort answers "where is the market most divergent right now", and it answers
 * it with the same names most days: an asset whose attention proxy always outruns its
 * turnover sits at the top of that table permanently, which makes it a property of the
 * asset rather than news about it. The first look is useful and the fiftieth is not.
 *
 * This asks the other question — **what moved** — by ranking each asset on the change in
 * its divergence between the latest cross-section and the most recent one at or before
 * `$hours` ago. An asset that has been at -60 all week scores zero here. One that went
 * from -5 to -40 overnight is at the top, which is the thing a reader did not already
 * know.
 *
 * Both ends of the comparison are recorded measurements and the row carries both, so
 * the change is checkable rather than asserted. It remains a measurement of a past
 * interval: nothing about a mover says where it goes next, and the copy around it says
 * so.
 *
 * `$toleranceHours` is how far past `$hours` the baseline may sit before the whole
 * comparison is abandoned. Every row is measured between the *same* two cross-sections,
 * chosen once — an asset missing from the baseline (a poller gap, or an asset that
 * joined the universe later) yields no row at all, rather than a change measured against
 * whatever happened to be nearest, which would report a recording gap as market
 * movement.
 *
 * The same reasoning applied one level up is `asset_movers_window()`: if the *universe*
 * changed size between the two cross-sections, every rank in one of them was taken
 * against a different population and the whole comparison is abandoned with a reason.
 * Read that docblock before changing anything here — the first version of this function
 * shipped without the check and reported a universe change as a 31-point median move.
 *
 * @return array<int,array<string,mixed>>
 */
function asset_divergence_movers(
    PDO $pdo,
    int $methodVersion,
    int $hours = 24,
    int $limit = 12,
    bool $includeStablecoins = false,
    int $toleranceHours = 12
): array {
    $window = asset_movers_window($pdo, $methodVersion, $hours, $toleranceHours);
    if (!$window['comparable']) {
        return [];
    }

    $now  = $window['to'];
    $then = $window['from'];

    $sql =
        'SELECT n.cmc_id, u.symbol, u.name, u.rank_last, u.is_stablecoin,
                n.voice, n.money, n.divergence, n.quadrant, n.basis, n.sampled_at,
                n.voice_inputs, n.money_inputs, n.inputs_possible, n.method_version,
                t.voice      AS voice_then,
                t.money      AS money_then,
                t.divergence AS divergence_then,
                t.quadrant   AS quadrant_then,
                t.sampled_at AS sampled_at_then,
                (n.divergence - t.divergence) AS divergence_change
           FROM scores n
           JOIN scores t
                  ON t.cmc_id         = n.cmc_id
                 AND t.scope          = n.scope
                 AND t.method_version = n.method_version
                 AND t.sampled_at     = :then
           JOIN asset_universe u ON u.cmc_id = n.cmc_id
          WHERE n.scope = :scope
            AND n.method_version = :version
            AND n.sampled_at = :now';
    if (!$includeStablecoins) {
        $sql .= ' AND u.is_stablecoin = 0';
    }
    $sql .= ' ORDER BY ABS(n.divergence - t.divergence) DESC LIMIT :limit';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':scope', 'asset');
    $stmt->bindValue(':version', $methodVersion, PDO::PARAM_INT);
    $stmt->bindValue(':now', $now);
    $stmt->bindValue(':then', $then);
    $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
    $stmt->execute();

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $row = normalise_score_row($row);
        foreach (['voice_then', 'money_then', 'divergence_then', 'divergence_change'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (float) $row[$key];
            }
        }
        // Whether the move carried the asset across a midline — the one kind of move
        // that renames the reading rather than only shifting it.
        $row['crossed'] = ($row['quadrant_then'] ?? null) !== ($row['quadrant'] ?? null);
        $rows[] = $row;
    }

    return $rows;
}

/**
 * The assets that changed quadrant between those same two cross-sections.
 *
 * A subset of `asset_divergence_movers()` by construction, kept separate because it
 * answers a sharper question: a crossing is the only move that changes what the asset is
 * *called*, so it earns a heading of its own. Ordered by how far the gap travelled, so a
 * decisive crossing outranks one that inched over the line.
 *
 * Filtered in PHP rather than SQL so that "crossed" has exactly one definition, the one
 * above. The query planner would have to walk the whole cross-section either way.
 *
 * @return array<int,array<string,mixed>>
 */
function asset_quadrant_crossings(
    PDO $pdo,
    int $methodVersion,
    int $hours = 24,
    int $limit = 12,
    bool $includeStablecoins = false
): array {
    $movers = asset_divergence_movers($pdo, $methodVersion, $hours, 200, $includeStablecoins);
    $crossed = array_values(array_filter($movers, static fn(array $r): bool => $r['crossed'] === true));

    return array_slice($crossed, 0, max(1, $limit));
}

/**
 * One asset's trail.
 *
 * @return array<int,array<string,mixed>>
 */
function asset_series(PDO $pdo, int $methodVersion, int $cmcId, string $window = '7d', int $limit = 2000): array
{
    $hours = window_hours($window);

    $sql =
        'SELECT sampled_at, voice, money, divergence, quadrant, basis
           FROM scores
          WHERE scope = :scope AND cmc_id = :cmc_id AND method_version = :version';
    if ($hours !== null) {
        $sql .= ' AND sampled_at >= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL :hours HOUR)';
    }
    $sql .= ' ORDER BY sampled_at DESC LIMIT :limit';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':scope', 'asset');
    $stmt->bindValue(':cmc_id', $cmcId, PDO::PARAM_INT);
    $stmt->bindValue(':version', $methodVersion, PDO::PARAM_INT);
    if ($hours !== null) {
        $stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return array_map('normalise_score_row', array_reverse($stmt->fetchAll()));
}

/** @return array<string,mixed>|null */
function asset_by_reference(PDO $pdo, string $reference): ?array
{
    $stmt = ctype_digit($reference)
        ? $pdo->prepare('SELECT cmc_id, symbol, name, rank_last, first_seen, last_seen FROM asset_universe WHERE cmc_id = ?')
        : $pdo->prepare('SELECT cmc_id, symbol, name, rank_last, first_seen, last_seen FROM asset_universe WHERE symbol = ? ORDER BY rank_last IS NULL, rank_last ASC LIMIT 1');
    $stmt->execute([ctype_digit($reference) ? (int) $reference : strtoupper($reference)]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * The raw inputs behind the current market score, in their native units.
 *
 * This is the row a judge checks against CoinMarketCap, so every value carries the
 * logical endpoint it came from and the minute it was sampled. Without that the number
 * is an assertion; with it, it is verifiable in thirty seconds, which is the 30% of the
 * score that matters most.
 *
 * Cross-sample inputs are the exception: `exchange_reserve_change` is a difference
 * between two samples, so it is never stored in `market_metric` and a plain lookup
 * reports "no reading" for an input that did in fact contribute to the score. Those are
 * passed in by the caller, which has the series to hand.
 *
 * @param array<int,array<string,mixed>> $inputs From `available_inputs()`.
 * @param array<string,array{value:float,at:string}> $derived Cross-sample values.
 * @return array<int,array<string,mixed>>
 */
function current_input_values(PDO $pdo, array $inputs, array $derived = []): array
{
    $out = [];

    $stmt = $pdo->prepare(
        'SELECT value, sampled_at, endpoint, raw_sample_id
           FROM market_metric
          WHERE metric = ?
       ORDER BY sampled_at DESC
          LIMIT 1'
    );

    foreach ($inputs as $input) {
        if (isset($derived[$input['metric']])) {
            $out[] = [
                'metric'     => $input['metric'],
                'label'      => $input['label'],
                'unit'       => $input['unit'],
                'endpoint'   => $input['endpoint'],
                'rationale'  => $input['rationale'],
                'value'      => (float) $derived[$input['metric']]['value'],
                'sampled_at' => (string) $derived[$input['metric']]['at'],
                'raw_sample_id' => null,
            ];
            continue;
        }

        $stmt->execute([$input['metric']]);
        $row = $stmt->fetch();

        $out[] = [
            'metric'     => $input['metric'],
            'label'      => $input['label'],
            'unit'       => $input['unit'],
            'endpoint'   => $input['endpoint'],
            'rationale'  => $input['rationale'],
            'value'      => $row === false ? null : (float) $row['value'],
            'sampled_at' => $row === false ? null : (string) $row['sampled_at'],
            'raw_sample_id' => $row === false ? null : (int) $row['raw_sample_id'],
        ];
    }

    return $out;
}

/**
 * Recording health, from `fetch_log` and `raw_samples`.
 *
 * The header claim — "recording since 9 Sep, 4,312 samples" — is the thing the whole
 * product rests on, so it is a live count rather than a written-down figure. The longest
 * gap is here for the same reason: a tool that shows a continuous trail while quietly
 * having missed a day is not a measurement.
 *
 * @return array<string,mixed>
 */
function recording_health(PDO $pdo): array
{
    $totals = $pdo->query(
        'SELECT COUNT(*) AS samples, MIN(fetched_at) AS first_sample, MAX(fetched_at) AS latest_sample
           FROM raw_samples'
    )->fetch() ?: [];

    $attempts = $pdo->query(
        'SELECT COUNT(*) AS attempts,
                SUM(CASE WHEN http_status = 200 THEN 1 ELSE 0 END) AS ok,
                SUM(credits) AS credits
           FROM fetch_log'
    )->fetch() ?: [];

    $scored = $pdo->query(
        "SELECT COUNT(*) AS rows_scored, MAX(sampled_at) AS latest_score
           FROM scores WHERE scope = 'market'"
    )->fetch() ?: [];

    $attemptCount = (int) ($attempts['attempts'] ?? 0);
    $okCount = (int) ($attempts['ok'] ?? 0);

    return [
        'samples'        => (int) ($totals['samples'] ?? 0),
        'first_sample'   => $totals['first_sample'] ?? null,
        'latest_sample'  => $totals['latest_sample'] ?? null,
        'attempts'       => $attemptCount,
        'failures'       => $attemptCount - $okCount,
        'failure_rate'   => $attemptCount > 0 ? round(($attemptCount - $okCount) / $attemptCount * 100, 2) : 0.0,
        'credits_used'   => (int) ($attempts['credits'] ?? 0),
        'scores'         => (int) ($scored['rows_scored'] ?? 0),
        'latest_score'   => $scored['latest_score'] ?? null,
        'longest_gap_minutes' => longest_recording_gap_minutes($pdo),
    ];
}

/**
 * The longest interval between consecutive market samples.
 *
 * Computed over the anchor endpoint only. Every endpoint mixed together would measure
 * the interval between *any* two calls, which is a few seconds and says nothing about
 * whether recording stopped.
 */
function longest_recording_gap_minutes(PDO $pdo): ?float
{
    $stmt = $pdo->prepare(
        'SELECT fetched_at FROM raw_samples WHERE endpoint = ? ORDER BY fetched_at ASC'
    );
    $stmt->execute([MARKET_ANCHOR_ENDPOINT]);
    $times = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($times) < 2) {
        return null;
    }

    $longest = 0.0;
    for ($i = 1, $n = count($times); $i < $n; $i++) {
        $gap = (strtotime($times[$i] . ' UTC') - strtotime($times[$i - 1] . ' UTC')) / 60;
        $longest = max($longest, $gap);
    }

    return round($longest, 1);
}

/**
 * Where a recording gap falls, so the chart can break the line instead of drawing
 * through it.
 *
 * A gap is any interval more than `$multiple` times the median interval. Derived from
 * the data rather than from the cron schedule, because the schedule is an intention and
 * the samples are what happened.
 *
 * @param array<int,array<string,mixed>> $series
 * @return array<int,array{after:string,before:string,minutes:float}>
 */
function gaps_in(array $series, float $multiple = 2.5): array
{
    if (count($series) < 3) {
        return [];
    }

    $intervals = [];
    for ($i = 1, $n = count($series); $i < $n; $i++) {
        $intervals[] = (strtotime($series[$i]['sampled_at'] . ' UTC') - strtotime($series[$i - 1]['sampled_at'] . ' UTC')) / 60;
    }

    $sorted = $intervals;
    sort($sorted);
    $median = $sorted[intdiv(count($sorted), 2)];
    if ($median <= 0) {
        return [];
    }

    $gaps = [];
    foreach ($intervals as $i => $minutes) {
        if ($minutes > $median * $multiple) {
            $gaps[] = [
                'after'   => $series[$i]['sampled_at'],
                'before'  => $series[$i + 1]['sampled_at'],
                'minutes' => round($minutes, 1),
            ];
        }
    }

    return $gaps;
}

/**
 * Every quadrant crossing in a series, as an event list.
 *
 * A description of what happened at a recorded moment. Not a signal, and the wording
 * everywhere it surfaces keeps it that way.
 *
 * @param array<int,array<string,mixed>> $series
 * @return array<int,array{at:string,from:string,to:string}>
 */
function quadrant_transitions(array $series): array
{
    $transitions = [];
    $previous = null;

    foreach ($series as $point) {
        if ($previous !== null && $point['quadrant'] !== $previous) {
            $transitions[] = ['at' => $point['sampled_at'], 'from' => $previous, 'to' => $point['quadrant']];
        }
        $previous = $point['quadrant'];
    }

    return $transitions;
}

/**
 * Which endpoints have been called, how they answered, and what they cost.
 *
 * The evidence behind `docs/api-friction.md`, and the reason that note is a report of
 * measurements rather than a recollection.
 *
 * @return array<int,array<string,mixed>>
 */
function endpoint_activity(PDO $pdo): array
{
    return $pdo->query(
        'SELECT endpoint,
                COUNT(*) AS attempts,
                SUM(CASE WHEN http_status = 200 THEN 1 ELSE 0 END) AS ok,
                SUM(credits) AS credits,
                MAX(attempted_at) AS latest,
                ROUND(AVG(duration_ms)) AS avg_ms
           FROM fetch_log
       GROUP BY endpoint
       ORDER BY attempts DESC'
    )->fetchAll();
}

/**
 * MySQL hands back DECIMAL columns as strings. Left alone they reach `json_encode` in
 * quotes, and a chart library reading "41.00" where it expects 41 draws nothing and
 * reports no error.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function normalise_score_row(array $row): array
{
    foreach (['voice', 'money', 'divergence'] as $key) {
        if (isset($row[$key])) {
            $row[$key] = (float) $row[$key];
        }
    }
    foreach (['cmc_id', 'rank_last', 'voice_inputs', 'money_inputs', 'inputs_possible', 'method_version', 'is_stablecoin'] as $key) {
        if (isset($row[$key])) {
            $row[$key] = (int) $row[$key];
        }
    }

    return $row;
}

/**
 * The latest value of each cross-sample input, for the readout panel.
 *
 * These are computed rather than stored — see the note on `current_input_values()`.
 * Derived here from the same function the scorer uses, so the number on the screen is
 * the number that went into the score rather than a second implementation of it.
 *
 * @return array<string,array{value:float,at:string}>
 */
function current_derived_inputs(PDO $pdo): array
{
    $series = derive_cross_sample_inputs(load_market_series($pdo, ['exchange_reserve_usd']));

    $out = [];
    foreach ($series as $metric => $points) {
        if ($metric === 'exchange_reserve_usd' || $points === []) {
            continue;
        }
        $latest = $points[count($points) - 1];
        $out[$metric] = ['value' => $latest['value'], 'at' => $latest['at']];
    }

    return $out;
}
