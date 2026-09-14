<?php
/**
 * Divergence and the quadrant reading.
 *
 * Small on purpose. The judgement in this product lives in `inputs.php` (what is
 * measured and what it is worth) and `normalise.php` (how a raw number becomes a
 * score); by the time it reaches here the two axes are settled and all that is left is
 * subtraction and a label.
 *
 * Pure. No clock, no database.
 */

declare(strict_types=1);

/**
 * `divergence = money - voice`, matching `docs/method.md`.
 *
 * Positive: money committed is running ahead of narrative.
 * Negative: narrative is running ahead of money committed.
 *
 * Both are statements about a measured present. Neither says what happens next, and no
 * caption anywhere in this product is allowed to imply that it does.
 */
function divergence_of(float $voice, float $money): float
{
    return round($money - $voice, 2);
}

/**
 * Which of the four readings the point currently sits in.
 *
 * Midlines at 50, on the same basis as the scores themselves. A quadrant is a label for
 * a location, not a rating: nothing about "loud and leveraged" says the market should be
 * doing anything different.
 */
function quadrant_of(float $voice, float $money): string
{
    if ($voice >= 50.0) {
        return $money >= 50.0 ? 'loud_and_leveraged' : 'chatter_without_conviction';
    }

    return $money >= 50.0 ? 'quiet_but_leveraged' : 'apathy';
}

/** The four readings as they are written for a reader. */
function quadrant_label(string $quadrant): string
{
    return [
        'loud_and_leveraged'         => 'Loud and leveraged',
        'chatter_without_conviction' => 'Chatter without conviction',
        'quiet_but_leveraged'        => 'Quiet, but leveraged',
        'apathy'                     => 'Apathy',
    ][$quadrant] ?? $quadrant;
}

/**
 * One sentence describing the gap. Present tense, no consequence clause.
 *
 * This string is on the largest number on the main screen, which makes it the sentence
 * most likely to drift into advice. It describes a relationship between two measurements
 * and stops there.
 */
function divergence_sentence(float $divergence): string
{
    if (abs($divergence) < 5.0) {
        return 'Money committed and narrative are reading close to level.';
    }

    return $divergence > 0
        ? 'Money committed is running ahead of narrative.'
        : 'Narrative is running ahead of money committed.';
}

/**
 * Both axes into one score row.
 *
 * Returns null when either axis had no inputs at all. A row needs both to mean anything,
 * and half a point on a two-axis plot is not a measurement — the sample is left out and
 * the trail shows the gap.
 *
 * `$moneyBasis` defaults to `$basis`, so a caller with one basis for the whole row — the
 * per-asset cross-section, and every test written before the split — passes one argument
 * and gets the old behaviour. The market scorer passes two, because since the
 * fear-and-greed backfill its axes no longer reach percentile rank at the same time
 * (D22).
 *
 * @param array{score:?float,used:int,possible:int,parts:array} $voice
 * @param array{score:?float,used:int,possible:int,parts:array} $money
 * @return array<string,mixed>|null
 */
function compose_score(
    array $voice,
    array $money,
    string $sampledAt,
    string $basis,
    int $cmcId = 0,
    string $scope = 'market',
    ?string $moneyBasis = null
): ?array {
    if ($voice['score'] === null || $money['score'] === null) {
        return null;
    }

    $v = (float) $voice['score'];
    $m = (float) $money['score'];

    $voiceBasis = $basis;
    $moneyBasis ??= $basis;

    return [
        'scope'           => $scope,
        'cmc_id'          => $cmcId,
        'sampled_at'      => $sampledAt,
        'voice'           => $v,
        'money'           => $m,
        'divergence'      => divergence_of($v, $m),
        'quadrant'        => quadrant_of($v, $m),
        // The weaker of the two, so a row is never advertised as more established than
        // its least-established axis. See combined_basis().
        'basis'           => combined_basis($voiceBasis, $moneyBasis),
        'voice_basis'     => $voiceBasis,
        'money_basis'     => $moneyBasis,
        'method_version'  => METHOD_VERSION,
        'voice_inputs'    => $voice['used'],
        'money_inputs'    => $money['used'],
        'inputs_possible' => $voice['possible'] + $money['possible'],
        'parts'           => ['voice' => $voice['parts'], 'money' => $money['parts']],
    ];
}
