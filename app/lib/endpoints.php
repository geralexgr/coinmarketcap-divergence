<?php
/**
 * The endpoint catalogue — one list, used by the prober, the verifier and the poller,
 * so the three can never drift apart.
 *
 * 'exists' records what bin/probe-paths.php found on 12 Sep 2026: whether the path
 * resolves at all, independent of whether our plan may call it. 'poll' marks what the
 * recorder writes every run. Plan access is a separate question, answered by
 * bin/verify-endpoints.php with a real key, and recorded in docs/endpoint-access.md.
 *
 * The derivatives block that this design originally assumed is gone: 38 candidate
 * paths, every one absent. See docs/decisions.md D10.
 *
 * 'access' records what bin/verify-endpoints.php measured with a real key, re-verified in
 * full on 13 Sep 2026.
 *
 * The key reports a 15,000 credit month and 50 requests a minute, and /v1/key/info returns
 * no tier name — so this catalogue does not assert one. What it asserts is what was
 * measured call by call, which is the only thing that survives contact with the API:
 *
 *   - Every trending, community and content endpoint answers 403. The Voice axis really is
 *     down to the fear and greed index (D14, D16), and re-verification confirmed it rather
 *     than softening it.
 *   - The whole derivatives family answers 200. D10 recorded these as absent; they were
 *     merely under /v5/, which the original probe never reached. See D20.
 */

declare(strict_types=1);

/**
 * What the plan permits, measured — not read off the pricing page.
 *
 * Run: `php bin/verify-endpoints.php` on 13 September 2026.
 * 'ok' = HTTP 200 with a data block. 'forbidden' = HTTP 403, the path exists and this
 * plan may not call it. A plan upgrade would change these; nothing in this repo will.
 *
 * This gates polling directly (see endpoints_to_poll), so a forbidden endpoint cannot
 * be scheduled by accident. Every 403 is a wasted round trip on a 15,000 credit budget
 * and a line of noise in fetch_log that hides real failures.
 *
 * @return array<string,string>
 */
function endpoint_access_results(): array
{
    return [
        'key_info'                 => 'ok',
        'global_metrics'           => 'ok',
        'listings_latest'          => 'ok',
        'fear_and_greed'           => 'ok',
        'fear_and_greed_historical' => 'ok',
        'quotes_latest'            => 'ok',
        'exchange_assets'          => 'ok',

        // Found 13 Sep 2026, after a competing hackathon entry was seen calling them.
        // The original 38-path probe covered /v1/ to /v4/ only, and the derivatives
        // family lives under /v5/. See D20 — this is the correction to D10.
        'derivatives_pairs'        => 'ok',
        'liquidations'             => 'ok',
        'derivatives_exchanges'    => 'ok',

        // The entire Voice axis except fear and greed.
        'community_trending_topic' => 'forbidden',
        'community_trending_token' => 'forbidden',
        'trending_most_visited'    => 'forbidden',
        'trending_latest'          => 'forbidden',
        'trending_gainers_losers'  => 'forbidden',
        'content_latest'           => 'forbidden',
        'content_posts_top'        => 'forbidden',

        // And three of the five Money candidates.
        'exchange_listings'        => 'forbidden',
        'market_pairs_derivatives' => 'forbidden',
        'price_performance'        => 'forbidden',
    ];
}

/**
 * @return array<int, array{
 *   key:string, path:string, axis:string, scope:string, query:array<string,scalar>,
 *   need:string, poll:bool, exists:string, note:string
 * }>
 */
function endpoint_catalogue(): array
{
    $e = static function (
        string $key,
        string $path,
        string $axis,
        string $need,
        array $query = [],
        bool $poll = false,
        string $scope = 'market',
        string $exists = 'unknown',
        string $note = '',
        int $everyMinutes = 15
    ): array {
        return compact('key', 'path', 'axis', 'need', 'query', 'poll', 'scope', 'exists', 'note')
            + ['every_minutes' => $everyMinutes];
    };

    $catalogue = [
        // -------------------------------------------------------------------
        // Support — needed whichever inputs survive.
        // -------------------------------------------------------------------
        $e('key_info', '/v1/key/info', 'support',
            'credits used and remaining this month', [], true, 'market', 'yes',
            'Costs no credits. Gives the budget panel real numbers instead of a local tally.', 60),

        $e('global_metrics', '/v1/global-metrics/quotes/latest', 'support',
            'total market cap, total volume, BTC dominance, stablecoin and derivative volume',
            ['convert' => 'USD'], true, 'market', 'yes',
            'Carries derivatives_volume_24h, confirmed. Polled at the core cadence because '
            . 'turnover genuinely moves within fifteen minutes.', 15),

        $e('listings_latest', '/v1/cryptocurrency/listings/latest', 'support',
            'top N by market cap: id, symbol, rank, volume, market cap',
            ['start' => 1, 'limit' => 100, 'convert' => 'USD'], true, 'market', 'yes',
            'Defines the asset universe and carries the per-asset Money inputs in the same call. '
            . 'Half the core cadence: the universe does not reshuffle every quarter hour.', 30),

        // -------------------------------------------------------------------
        // Voice — what the market is saying.
        // -------------------------------------------------------------------
        $e('fear_and_greed', '/v3/fear-and-greed/latest', 'voice',
            'current index value 0-100', [], true, 'market', 'yes',
            'Updated once a day by CoinMarketCap, so polling it more than a few times a day '
            . 'buys nothing and costs a credit every time. Three-hourly is already generous.', 180),

        $e('fear_and_greed_historical', '/v3/fear-and-greed/historical', 'voice',
            'index history — would backfill the Voice axis before recording started',
            ['start' => 1, 'limit' => 500], false, 'market', 'yes',
            'The one input that may have real history behind it. Fetch once, not on a cron.'),

        $e('community_trending_topic', '/v1/community/trending/topic', 'voice',
            'trending topic list and rank', [], true, 'market', 'yes',
            'Rank churn between samples is itself a Voice signal.'),

        $e('community_trending_token', '/v1/community/trending/token', 'voice',
            'trending token list and rank', [], true, 'market', 'yes', ''),

        $e('trending_most_visited', '/v1/cryptocurrency/trending/most-visited', 'voice',
            'most-viewed assets on coinmarketcap.com',
            ['start' => 1, 'limit' => 100, 'time_period' => '24h'], true, 'market', 'yes',
            'Page views are attention that has not yet become a trade — the cleanest Voice input.'),

        $e('trending_latest', '/v1/cryptocurrency/trending/latest', 'voice',
            'trending search list', ['start' => 1, 'limit' => 100, 'time_period' => '24h'], true, 'market', 'yes', ''),

        $e('trending_gainers_losers', '/v1/cryptocurrency/trending/gainers-losers', 'voice',
            'largest movers, as an attention proxy',
            ['start' => 1, 'limit' => 100, 'time_period' => '24h'], false, 'market', 'yes',
            'Price-derived, so it leans Money-ish. Recorded for later, not an input yet.'),

        $e('content_latest', '/v1/content/latest', 'voice',
            'post volume across the community feed', ['limit' => 100], true, 'market', 'yes',
            'Post counts per window are the closest thing to a market-wide social volume number.'),

        $e('content_posts_top', '/v1/content/posts/top', 'voice',
            'top posts for one asset, with comment and like counts', ['id' => 1], false, 'asset', 'yes',
            'Per-asset and needs an id, so one call per asset. Only affordable for a short list.'),

        // -------------------------------------------------------------------
        // Money — what the market has committed.
        //
        // Not what this design assumed. Funding rate, open interest and liquidations
        // have no endpoint on the CMC API at any version — probed and recorded in
        // docs/endpoint-access.md. What is left measures committed money indirectly:
        // turnover, where that turnover happens, and what sits on exchanges.
        // -------------------------------------------------------------------
        // -------------------------------------------------------------------
        // Money — the leverage inputs. D10 said these did not exist; D20 records
        // why that was wrong. They live under /v5/, which the original probe never
        // reached, and they are callable on this plan.
        // -------------------------------------------------------------------
        $e('derivatives_pairs', '/v5/cryptocurrency/derivatives/market-pairs/list/latest', 'money',
            'open interest, funding rate and basis per derivative pair',
            ['crypto_symbol' => 'BTC', 'limit' => 100], true, 'market', 'yes',
            'The endpoint this product was designed around and spent two days believing did '
            . 'not exist. One symbol per call — a list is rejected — so BTC alone stands for '
            . 'market-wide leverage, which is what the funding and open-interest literature '
            . 'uses anyway. 1 credit.', 15),

        $e('liquidations', '/v5/derivatives/liquidations/cryptocurrency/list/latest', 'money',
            'long and short liquidations at 1h, 4h and 24h, per asset',
            ['limit' => 100], true, 'market', 'yes',
            'The best value call in the product: 100 assets for one credit, carrying both '
            . 'market-wide totals and per-asset detail. Forced positioning unwinds — the one '
            . 'thing in the whole API that is unambiguously money under stress.', 15),

        $e('derivatives_exchanges', '/v5/exchange/derivatives/list', 'money',
            'per-venue derivative volume and open interest, for concentration',
            ['limit' => 100], false, 'market', 'yes',
            'Replaces the forbidden exchange_listings for the concentration input. Recorded '
            . 'but not yet scored — the HHI input is declared at weight zero until there is '
            . 'enough history to set a reference range from measurement rather than instinct.', 60),

        $e('quotes_latest', '/v2/cryptocurrency/quotes/latest', 'money',
            'volume_24h, market_cap, volume_change_24h per asset — turnover',
            ['id' => '1,1027,825', 'convert' => 'USD'], true, 'asset', 'yes',
            'Turnover = volume_24h / market_cap. Money moving relative to the size of '
            . 'the thing it is moving in. Batched by id list, so 100 assets is one call.', 30),

        $e('exchange_listings', '/v1/exchange/listings/latest', 'money',
            'per-exchange 24h volume, for concentration',
            ['start' => 1, 'limit' => 100, 'convert' => 'USD'], true, 'market', 'yes',
            'Concentration across exchanges: whether flow is broad or sitting in one venue.'),

        $e('exchange_assets', '/v1/exchange/assets', 'money',
            'exchange wallet balances, for reserve movement', ['id' => 270], true, 'market', 'yes',
            'Reserve movement. id 270 = Binance. One call per exchange, so a short list only. '
            . 'Exchange balances move slowly and the input is a 24h change, so two-hourly '
            . 'sampling loses nothing the score can see.', 120),

        $e('market_pairs_derivatives', '/v2/cryptocurrency/market-pairs/latest', 'money',
            'derivative pair volume for one asset',
            ['id' => 1, 'category' => 'derivatives', 'limit' => 100], false, 'asset', 'yes',
            'The last route to anything derivative-shaped: the pairs endpoint filtered to '
            . 'the derivatives category. Whether it returns open interest is unconfirmed — '
            . 'this is the highest-value payload to inspect once a key exists.'),

        $e('price_performance', '/v2/cryptocurrency/price-performance-stats/latest', 'money',
            'rolling performance per asset', ['id' => '1,1027', 'time_period' => '24h'], false, 'asset', 'yes', ''),

        // -------------------------------------------------------------------
        // Absent — kept so the record of what was checked survives, and so nobody
        // re-guesses these paths in three weeks. Never polled, never verified.
        // -------------------------------------------------------------------
        $e('derivatives_listings', '/v1/derivatives/listings/latest', 'absent',
            'open interest and funding per contract', [], false, 'market', 'no',
            'Probed 12 Sep 2026 across v1-v4: absent at every version.'),

        $e('derivatives_funding', '/v1/derivatives/funding-rate/latest', 'absent',
            'funding rate', [], false, 'market', 'no', 'Absent at every version.'),

        $e('derivatives_open_interest', '/v1/derivatives/open-interest/latest', 'absent',
            'open interest level', [], false, 'market', 'no', 'Absent at every version.'),

        $e('derivatives_liquidations', '/v1/derivatives/liquidations/latest', 'absent',
            '24h liquidation total', [], false, 'market', 'no', 'Absent at every version.'),
    ];

    // Annotated rather than hard-coded per entry, so the measurement and its date live
    // in one place and cannot drift out of step with the catalogue.
    $access = endpoint_access_results();
    foreach ($catalogue as $i => $entry) {
        $catalogue[$i]['access'] = $access[$entry['key']] ?? ($entry['exists'] === 'no' ? 'absent' : 'unknown');
    }

    return $catalogue;
}

/**
 * @param array<string,mixed> $config
 * @return array<int, array<string,mixed>> What the recorder writes for this scope.
 */
function endpoints_to_poll(array $config, string $scope): array
{
    $override = $config[$scope === 'asset' ? 'asset_endpoints' : 'market_endpoints'] ?? null;

    $selected = [];
    foreach (endpoint_catalogue() as $entry) {
        if ($entry['scope'] !== $scope || $entry['axis'] === 'absent') {
            continue;
        }
        // Measured plan access overrides intent, including an explicit config override.
        // A 403 costs a round trip, returns nothing, and buries real failures in
        // fetch_log. If the plan changes, endpoint_access_results() changes with it.
        if ($entry['access'] === 'forbidden') {
            continue;
        }
        $wanted = is_array($override)
            ? in_array($entry['key'], $override, true)
            : $entry['poll'];
        if ($wanted) {
            $selected[] = $entry;
        }
    }

    return $selected;
}

/** Endpoints worth calling with a real key. Absent paths are not worth the round trip. */
function endpoints_to_verify(): array
{
    return array_values(array_filter(
        endpoint_catalogue(),
        static fn(array $e): bool => $e['exists'] !== 'no'
    ));
}

/** @return array<string,mixed>|null */
function endpoint_by_key(string $key): ?array
{
    foreach (endpoint_catalogue() as $entry) {
        if ($entry['key'] === $key) {
            return $entry;
        }
    }
    return null;
}
