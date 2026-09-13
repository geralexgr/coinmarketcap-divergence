<?php
/**
 * Raw input to 0-100, under a named basis.
 *
 * Pure, like `lib/extract.php` and for the same reason: no clock, no database, no
 * network, so it can be tested against fixtures and a rebuild over the whole of history
 * gives the same answer whatever order the samples arrive in.
 *
 * The two bases exist because percentile rank needs history and the first week has
 * none. Which one produced a row is stored on the row and printed on the method page —
 * scores either side of the switchover are not strictly comparable, and hiding that
 * would make the trail dishonest.
 */

declare(strict_types=1);

/**
 * Basis A — min-max against a hand-set reference range.
 *
 * Clamped at both ends. A value beyond the range reads as 0 or 100 rather than running
 * off the axis, which is the right behaviour for a bounded score: the quadrant is
 * defined on 0-100 and a point at 140 has nowhere to sit.
 */
function normalise_fixed(float $value, float $floor, float $ceiling, bool $invert = false): float
{
    if ($ceiling === $floor) {
        return 50.0;
    }

    $scaled = ($value - $floor) / ($ceiling - $floor) * 100.0;
    $scaled = max(0.0, min(100.0, $scaled));

    return $invert ? 100.0 - $scaled : $scaled;
}

/**
 * Basis B — percentile rank within the trailing history of this same input.
 *
 * The midpoint definition: values strictly below, plus half the ties. A series of
 * identical readings scores 50 rather than 0 or 100, which is what "no information"
 * should look like.
 *
 * @param array<int,float> $history Prior values of this input, any order.
 */
function normalise_percentile(float $value, array $history, bool $invert = false): float
{
    $n = count($history);
    if ($n === 0) {
        return 50.0;
    }

    $below = 0;
    $equal = 0;
    foreach ($history as $prior) {
        if ($prior < $value) {
            $below++;
        } elseif ($prior === $value) {
            $equal++;
        }
    }

    $rank = ($below + $equal / 2.0) / $n * 100.0;
    $rank = max(0.0, min(100.0, $rank));

    return $invert ? 100.0 - $rank : $rank;
}

/**
 * Which basis a sample taken at this moment is scored under.
 *
 * The switchover is a property of how much history exists, not of the calendar, so a
 * deployment that started recording late gets its fixed week from its own first sample.
 */
function basis_for(string $firstSampleAt, string $sampledAt, int $fixedDays = FIXED_BASIS_DAYS): string
{
    $first = strtotime($firstSampleAt . ' UTC');
    $at = strtotime($sampledAt . ' UTC');
    if ($first === false || $at === false) {
        return 'fixed';
    }

    return ($at - $first) >= $fixedDays * 86400 ? 'percentile' : 'fixed';
}

/**
 * One axis: the declared inputs, the values actually present, and the history behind
 * them, in — a 0-100 score out.
 *
 * An input with no value is dropped and the surviving weights are renormalised over
 * what is left. That is the whole reason this function reports `used` and `possible`
 * separately: a Voice score built from one of three declared inputs is a different
 * claim from one built from three, and the app prints which it is looking at.
 *
 * A missing input is never zero. Zero is a measurement of a quiet market; absence is a
 * measurement of nothing at all, and the two must not render the same.
 *
 * @param array<int,array<string,mixed>> $inputs   From `available_inputs()`.
 * @param array<string,float>            $values   metric => current value.
 * @param array<string,array<int,float>> $history  metric => prior values, for the percentile basis.
 * @return array{score:?float, used:int, possible:int, parts:array<int,array<string,mixed>>}
 */
function score_axis(array $inputs, array $values, string $basis, array $history = []): array
{
    $parts = [];
    $weighted = 0.0;
    $weightSum = 0.0;

    foreach ($inputs as $input) {
        $metric = (string) $input['metric'];
        if (!array_key_exists($metric, $values) || !is_finite($values[$metric])) {
            continue;
        }

        $raw = (float) $values[$metric];
        $invert = (bool) $input['invert'];

        $normalised = $basis === 'percentile'
            ? normalise_percentile($raw, $history[$metric] ?? [], $invert)
            : normalise_fixed($raw, (float) $input['floor'], (float) $input['ceiling'], $invert);

        $weight = (float) $input['weight'];
        $weighted += $normalised * $weight;
        $weightSum += $weight;

        $parts[] = [
            'metric'     => $metric,
            'label'      => $input['label'],
            'endpoint'   => $input['endpoint'],
            'unit'       => $input['unit'],
            'raw'        => $raw,
            'normalised' => round($normalised, 2),
            'weight'     => $weight,
        ];
    }

    // Every declared input was missing. Not a zero score — no score. The caller writes
    // no row, the chart shows a gap, and nobody reads an empty database as a calm market.
    if ($weightSum <= 0.0) {
        return ['score' => null, 'used' => 0, 'possible' => count($inputs), 'parts' => []];
    }

    // Renormalised over the weights that actually contributed, so the surviving inputs
    // keep their proportions to each other rather than the axis being dragged toward
    // zero by the ones the plan forbids.
    foreach ($parts as $i => $part) {
        $parts[$i]['weight_effective'] = round($part['weight'] / $weightSum, 4);
    }

    return [
        'score'    => round($weighted / $weightSum, 2),
        'used'     => count($parts),
        'possible' => count($inputs),
        'parts'    => $parts,
    ];
}
