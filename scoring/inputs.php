<?php
/**
 * The method, as data.
 *
 * Every input on both axes is declared here — where it comes from, how it is scaled,
 * which way it points, and what it is worth. `docs/method.md` is written from this
 * file and the public method page renders it directly, so the three cannot drift:
 * there is one description of the method and the code is it.
 *
 * Two properties matter more than the numbers:
 *
 *  1. **Missing inputs are dropped, not zeroed.** Ten of the seventeen endpoints this
 *     product was designed around answer 403 on the Basic plan (D14). An input with no
 *     data contributes nothing and the surviving weights are renormalised; the score
 *     records how many of the possible inputs it actually saw, and the app prints that.
 *     A zero would read as a measurement of quiet, which is a lie about the market
 *     rather than about the plan.
 *  2. **A plan upgrade needs no code.** The forbidden inputs are declared here in full.
 *     The day `endpoint_access_results()` in `lib/endpoints.php` says they are callable,
 *     the poller records them, the extractor writes them, and they start contributing —
 *     with `method_version` bumped so the change is visible in the data.
 */

declare(strict_types=1);

/**
 * Bumped whenever a weight, a reference range or the input list changes.
 *
 * Scores are keyed on it, so a change adds a parallel series rather than rewriting the
 * one already recorded. The method page prints both, and the trail does not silently
 * become a different measurement halfway along.
 *
 * 1 — first published method, 13 Sep 2026. Voice is fear and greed alone; every other
 *     Voice endpoint is forbidden on this plan.
 */
const METHOD_VERSION = 1;

/**
 * How long the fixed reference ranges are used before percentile ranking takes over.
 *
 * Percentile rank against trailing history is meaningless when there is no history —
 * the first day's scores would be ranked against a handful of samples and the plot
 * would jump between 0 and 100 for no reason. So the first week is min-max scaled
 * against hand-set ranges, and the switchover is published rather than hidden.
 */
const FIXED_BASIS_DAYS = 7;

/**
 * The trailing window percentile rank is taken over, once there is enough history.
 *
 * 30 days is longer than this deployment will have during the hackathon, which is the
 * honest position: the window is "everything recorded so far, up to 30 days", and the
 * method page says which of the two it currently is.
 */
const PERCENTILE_WINDOW_DAYS = 30;

/**
 * One input on one axis.
 *
 * floor/ceiling are the fixed-basis reference range: the value that scores 0 and the
 * value that scores 100. `invert` means the input points the other way — a higher raw
 * value is a *lower* score.
 *
 * `available` is not a guess. It is read from the measured plan access in
 * `lib/endpoints.php`, so an input is marked unreachable by the same table that stops
 * the poller calling it.
 */
function scoring_input(
    string $metric,
    string $label,
    string $endpoint,
    float $floor,
    float $ceiling,
    float $weight,
    string $unit,
    string $rationale,
    bool $invert = false
): array {
    return compact('metric', 'label', 'endpoint', 'floor', 'ceiling', 'weight', 'unit', 'rationale', 'invert');
}

/**
 * Voice, market-wide — what the market is saying.
 *
 * This axis is a casualty of the plan. It was designed around trending rank churn,
 * most-visited assets and community post volume; all three are 403 (D14). What is left
 * is the fear and greed index, which CoinMarketCap updates **once a day**. So Voice
 * steps daily while Money moves every ten minutes, and the app says so on the chart
 * rather than letting a flat line read as a calm market.
 *
 * The forbidden inputs stay declared. They are what the axis becomes on a plan that
 * permits them, and leaving them here is the difference between a documented limit and
 * a quiet omission.
 *
 * @return array<int,array<string,mixed>>
 */
function voice_inputs_market(): array
{
    return [
        scoring_input(
            'fear_greed', 'Fear and greed index', 'fear_and_greed',
            0, 100, 0.40, 'index 0-100',
            "CoinMarketCap's own sentiment index, already on a 0-100 scale, so the fixed "
            . 'range is the scale itself and not a judgement call. Updated once a day.'
        ),
        scoring_input(
            'trending_churn', 'Trending rank churn', 'community_trending_token',
            0, 40, 0.35, 'positions moved',
            'How far the trending list reordered since the previous sample. Forbidden on this plan.'
        ),
        scoring_input(
            'post_count', 'Community post volume', 'content_latest',
            0, 100, 0.25, 'posts per sample',
            'Post volume across the community feed. Forbidden on this plan.'
        ),
    ];
}

/**
 * Money, market-wide — what the market has committed.
 *
 * **Read this before trusting the axis.** It was designed around funding rates, open
 * interest and liquidations. None of those exist on the CoinMarketCap API at any
 * version — 38 paths probed, all absent (D10). What is here measures money *moving* and
 * money *at rest*, not money *committed and leveraged*. Those are different things.
 *
 * `derivatives_to_spot` is the partial exception and the reason D10 is amended rather
 * than final: global-metrics does carry derivative volume, so the share of the day's
 * activity happening in contracts rather than in the asset is reachable. It is a
 * volume figure, not a position figure — it says how much was traded, never how much is
 * still held — but it is the only genuinely positioning-shaped number on this API.
 *
 * @return array<int,array<string,mixed>>
 */
function money_inputs_market(): array
{
    return [
        scoring_input(
            'market_turnover', 'Turnover', 'global_metrics',
            0.005, 0.05, 0.35, 'volume / market cap',
            'Total 24h volume over total market cap: money moving relative to the size of '
            . 'the thing it is moving in. The range spans the quiet and busy days measured '
            . 'in the first week of recording.'
        ),
        scoring_input(
            'derivatives_to_spot', 'Derivative share of activity', 'global_metrics',
            2.0, 12.0, 0.30, 'x spot volume',
            'Derivative volume against spot volume. Measured at 7.4x on 13 Sep 2026. The '
            . 'closest this API gets to a leverage reading, and it is still a volume '
            . 'figure: it says how much was traded in contracts, never how much is held.'
        ),
        scoring_input(
            'stablecoin_volume_share', 'Stablecoin share of volume', 'global_metrics',
            0.5, 1.2, 0.20, 'share of total volume',
            'What fraction of the day\'s volume is stablecoins changing hands rather than '
            . 'risk being taken. Inverted: a high stablecoin share is money standing still.',
            true
        ),
        scoring_input(
            'exchange_reserve_change', 'Exchange reserve movement', 'exchange_assets',
            0.0, 0.04, 0.15, 'absolute 24h change',
            'How much the reserve held on the tracked exchange moved, in either direction. '
            . 'Repositioning, not direction — the method makes no claim about which way '
            . 'money leaving an exchange points.'
        ),
        scoring_input(
            'exchange_hhi', 'Exchange concentration', 'exchange_listings',
            0.05, 0.35, 0.00, 'HHI',
            'Concentration of volume across venues. Forbidden on this plan; declared at '
            . 'weight zero so it is visible as a designed input rather than reappearing '
            . 'unannounced if access changes.'
        ),
    ];
}

/**
 * Voice, per asset.
 *
 * **The floor and ceiling below are not used.** Per-asset scores are ranked against the
 * universe at the same instant (D17), which reads neither. They are carried so the
 * declaration has one shape for both scopes, and so they are already correct if a
 * fixed-basis per-asset score is ever wanted. The method page prints "rank vs universe"
 * for this scope rather than showing a range that does not drive the number.
 *
 * There is no per-asset attention data on this plan at all: trending, most-visited and
 * community are the endpoints that carry it and all three are 403. `abs_percent_change_24h`
 * is a proxy and a weak one — the size of the day's move, on the reasoning that an asset
 * that moved 30% is being looked at whichever way it moved. It is price-derived, which
 * means it is partly contaminated by the other axis, and the method page says exactly
 * that rather than presenting the screener as cleaner than it is.
 *
 * `trend_rank` is the real input and it is inverted: rank 1 is the loudest.
 *
 * @return array<int,array<string,mixed>>
 */
function voice_inputs_asset(): array
{
    return [
        scoring_input(
            'trend_rank', 'Trending rank', 'trending_most_visited',
            1, 100, 0.60, 'position',
            'Position on the trending and most-visited lists. Inverted: rank 1 is loudest. '
            . 'Forbidden on this plan.',
            true
        ),
        scoring_input(
            'abs_percent_change_24h', 'Size of 24h move', 'listings_latest',
            0.5, 15.0, 0.40, '%, absolute',
            'How far the asset moved today, direction discarded. A proxy for attention, used '
            . 'because every attention endpoint is forbidden on this plan. It is derived from '
            . 'price, so it is partly contaminated by the Money axis; it is the weakest input '
            . 'in the product and it is labelled as such wherever it is shown.'
        ),
    ];
}

/**
 * Money, per asset. Turnover is the axis; the rest qualify it.
 *
 * @return array<int,array<string,mixed>>
 */
function money_inputs_asset(): array
{
    return [
        scoring_input(
            'turnover', 'Turnover', 'listings_latest',
            0.005, 0.30, 0.60, 'volume / market cap',
            'Volume over market cap, so a large asset and a small one are on the same scale. '
            . 'The single most informative number the Money axis has per asset.'
        ),
        scoring_input(
            'volume_change_24h', 'Volume change, 24h', 'listings_latest',
            -40.0, 80.0, 0.40, '%',
            'Whether the money moving in this asset is more or less than yesterday. Signed, '
            . 'unlike the Voice proxy: money arriving and money leaving are different facts.'
        ),
    ];
}

/**
 * @return array<int,array<string,mixed>>
 */
function axis_inputs(string $axis, string $scope): array
{
    return match ("{$axis}:{$scope}") {
        'voice:market' => voice_inputs_market(),
        'money:market' => money_inputs_market(),
        'voice:asset'  => voice_inputs_asset(),
        'money:asset'  => money_inputs_asset(),
        default        => throw new InvalidArgumentException("no such axis and scope: {$axis}:{$scope}"),
    };
}

/**
 * The inputs that can actually contribute right now: a non-zero weight, and an endpoint
 * this plan permits.
 *
 * Availability is read from the measured access table rather than restated, so one
 * verification run updates the poller, the extractor and the method page together.
 *
 * @return array<int,array<string,mixed>>
 */
function available_inputs(string $axis, string $scope): array
{
    $access = function_exists('endpoint_access_results') ? endpoint_access_results() : [];

    return array_values(array_filter(
        axis_inputs($axis, $scope),
        static function (array $input) use ($access): bool {
            if ($input['weight'] <= 0.0) {
                return false;
            }
            return ($access[$input['endpoint']] ?? 'ok') === 'ok';
        }
    ));
}

/**
 * The whole method as a structure, for the method page and the MCP `get_method` tool.
 *
 * @return array<string,mixed>
 */
function method_description(): array
{
    $axes = [];
    foreach (['market', 'asset'] as $scope) {
        foreach (['voice', 'money'] as $axis) {
            $available = array_column(available_inputs($axis, $scope), 'metric');
            $rows = [];
            foreach (axis_inputs($axis, $scope) as $input) {
                $input['available'] = in_array($input['metric'], $available, true);
                $rows[] = $input;
            }
            $axes["{$scope}.{$axis}"] = $rows;
        }
    }

    return [
        'method_version'          => METHOD_VERSION,
        'fixed_basis_days'        => FIXED_BASIS_DAYS,
        'percentile_window_days'  => PERCENTILE_WINDOW_DAYS,
        'divergence_formula'      => 'money - voice',
        'axes'                    => $axes,
    ];
}
