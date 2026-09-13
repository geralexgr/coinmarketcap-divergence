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
 * 1 — first published method, 13 Sep 2026. Voice is fear and greed alone; Money is
 *     turnover and its substitutes, because the leverage endpoints were believed absent.
 * 2 — the Money axis as it was always meant to be. Open interest, funding rate and
 *     liquidations are real, reachable and callable (D20), so the axis now measures money
 *     *committed and at risk* rather than money changing hands. The substitutes that stood
 *     in for them are kept at weight zero rather than deleted, so the history of what the
 *     axis used to be stays legible.
 */
const METHOD_VERSION = 2;

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
 * **This axis was rebuilt on 13 September 2026 and the rebuild is the point.** It was
 * designed around funding rates, open interest and liquidations; D10 recorded them as
 * absent from the CoinMarketCap API after probing 38 paths, and the axis was rebuilt
 * around turnover as a weaker substitute — money *moving* rather than money *committed*.
 *
 * That was wrong. The probe covered /v1/ to /v4/ and the derivatives family lives under
 * /v5/. Every input the axis originally wanted is real and callable on this plan (D20),
 * so the substitutes step aside for the measurements they were standing in for.
 *
 * What the axis now measures, in order of weight:
 *
 *  - **Open interest** — dollars currently committed to derivative positions. Not volume:
 *    a level, held right now, which is the single most direct answer to "how much money
 *    is at risk in this market".
 *  - **Funding rate** — what it costs per interval to hold a long. Positive means longs
 *    are paying shorts, which is what a crowded long side looks like from the inside.
 *    Weighted by each pair's open interest, so a dead venue cannot outvote Binance.
 *  - **Liquidations** — positions closed by force rather than by choice. The only figure
 *    in the whole API that is unambiguously money under stress.
 *  - **Turnover** and **OI-to-volume** — money moving, and how much of that movement
 *    became a held position rather than churn.
 *
 * The four substitutes below it are kept at weight zero rather than deleted. They are the
 * record of what this axis was for its first day, and `method_version` on every score row
 * says which of the two produced it.
 *
 * @return array<int,array<string,mixed>>
 */
function money_inputs_market(): array
{
    return [
        scoring_input(
            'open_interest', 'Open interest', 'derivatives_pairs',
            4.0e10, 1.2e11, 0.30, 'USD held in open positions',
            'Dollars committed to derivative positions on BTC right now, summed across every '
            . 'venue CoinMarketCap tracks. Measured at $76.6bn on 13 Sep 2026. A level, not a '
            . 'flow: this is the number the axis was always supposed to be built on.'
        ),
        scoring_input(
            'funding_rate', 'Funding rate', 'derivatives_pairs',
            -0.0003, 0.0008, 0.20, 'per funding interval',
            'What longs pay shorts to keep a perpetual open, weighted by each pair\'s open '
            . 'interest. Positive means the long side is crowded enough to pay for the '
            . 'privilege. Measured at +0.0043% on 13 Sep 2026.'
        ),
        scoring_input(
            'liquidations_24h', 'Liquidations, 24h', 'liquidations',
            5.0e7, 1.5e9, 0.20, 'USD closed by force',
            'Positions closed by the exchange rather than by their owner, across the top 100 '
            . 'assets. Money that was committed and then taken off the table involuntarily — '
            . 'the clearest evidence in the API that leverage was actually there.'
        ),
        scoring_input(
            'oi_to_volume', 'Open interest vs volume', 'derivatives_pairs',
            0.3, 1.5, 0.15, 'ratio',
            'Open interest over 24h derivative volume: how much of the day\'s trading turned '
            . 'into positions still being held rather than churn. High means the market is '
            . 'carrying what it bought.'
        ),
        scoring_input(
            'market_turnover', 'Turnover', 'global_metrics',
            0.005, 0.05, 0.15, 'volume / market cap',
            'Total 24h volume over total market cap: money moving relative to the size of the '
            . 'thing it is moving in. Demoted from the headline input now that the axis can '
            . 'measure commitment directly, but still the broadest measure of activity.'
        ),

        // --- Superseded by the four above. Declared, not deleted: these are what the
        // --- axis was built from under method_version 1, and a reader comparing an old
        // --- score to a new one should be able to see exactly what changed.
        scoring_input(
            'derivatives_to_spot', 'Derivative share of activity', 'global_metrics',
            2.0, 12.0, 0.00, 'x spot volume',
            'Derivative volume against spot volume. The closest thing to a leverage reading '
            . 'available before the real ones were found. Superseded by open interest, which '
            . 'measures positions held rather than contracts traded.'
        ),
        scoring_input(
            'stablecoin_volume_share', 'Stablecoin share of volume', 'global_metrics',
            0.5, 1.2, 0.00, 'share of total volume',
            'A proxy for how much of the day\'s volume was risk rather than rotation. '
            . 'Superseded: funding and open interest answer that directly.',
            true
        ),
        scoring_input(
            'exchange_reserve_change', 'Exchange reserve movement', 'exchange_assets',
            0.0, 0.04, 0.00, 'absolute 24h change',
            'Movement in the reserve held on the tracked exchange. Superseded by liquidations, '
            . 'which measure positioning being unwound rather than balances being shuffled.'
        ),
        scoring_input(
            'derivative_exchange_hhi', 'Derivative venue concentration', 'derivatives_exchanges',
            0.02, 0.30, 0.00, 'HHI',
            'Concentration of derivative volume across venues — the replacement for the '
            . 'forbidden spot equivalent. Recorded every hour but not yet scored: the '
            . 'reference range should come from measured history, not instinct.'
        ),
        scoring_input(
            'exchange_hhi', 'Spot venue concentration', 'exchange_listings',
            0.05, 0.35, 0.00, 'HHI',
            'Concentration of spot volume across venues. Forbidden on this plan; kept so the '
            . 'designed input is visible rather than silently absent.'
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
