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
 */

declare(strict_types=1);

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
        string $note = ''
    ): array {
        return compact('key', 'path', 'axis', 'need', 'query', 'poll', 'scope', 'exists', 'note');
    };

    return [
        // -------------------------------------------------------------------
        // Support — needed whichever inputs survive.
        // -------------------------------------------------------------------
        $e('key_info', '/v1/key/info', 'support',
            'credits used and remaining this month', [], true, 'market', 'yes',
            'Costs no credits. Gives the budget panel real numbers instead of a local tally.'),

        $e('global_metrics', '/v1/global-metrics/quotes/latest', 'support',
            'total market cap, total volume, BTC dominance, stablecoin and derivative volume',
            ['convert' => 'USD'], true, 'market', 'yes',
            'CHECK THE PAYLOAD: if it carries derivatives_volume_24h, that is the one '
            . 'positioning number still reachable and it belongs on the Money axis.'),

        $e('listings_latest', '/v1/cryptocurrency/listings/latest', 'support',
            'top N by market cap: id, symbol, rank, volume, market cap',
            ['start' => 1, 'limit' => 100, 'convert' => 'USD'], true, 'market', 'yes',
            'Defines the asset universe and carries the per-asset Money inputs in the same call.'),

        // -------------------------------------------------------------------
        // Voice — what the market is saying.
        // -------------------------------------------------------------------
        $e('fear_and_greed', '/v3/fear-and-greed/latest', 'voice',
            'current index value 0-100', [], true, 'market', 'yes',
            'Open question 3 answered: the path exists. Plan access still to confirm.'),

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
        $e('quotes_latest', '/v2/cryptocurrency/quotes/latest', 'money',
            'volume_24h, market_cap, volume_change_24h per asset — turnover',
            ['id' => '1,1027,825', 'convert' => 'USD'], true, 'asset', 'yes',
            'Turnover = volume_24h / market_cap. Money moving relative to the size of '
            . 'the thing it is moving in. Batched by id list, so 100 assets is one call.'),

        $e('exchange_listings', '/v1/exchange/listings/latest', 'money',
            'per-exchange 24h volume, for concentration',
            ['start' => 1, 'limit' => 100, 'convert' => 'USD'], true, 'market', 'yes',
            'Concentration across exchanges: whether flow is broad or sitting in one venue.'),

        $e('exchange_assets', '/v1/exchange/assets', 'money',
            'exchange wallet balances, for reserve movement', ['id' => 270], false, 'market', 'yes',
            'Reserve movement. id 270 = Binance. One call per exchange, so a short list only.'),

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
