<?php
/**
 * Extraction: one stored payload in, typed metric rows out.
 *
 * Deliberately pure. Nothing here touches the database, the clock or the network,
 * which is what makes it testable against saved fixtures and re-runnable over the
 * whole of history (bin/extract.php --rebuild). The writer is bin/extract.php; this
 * file only ever decides what a payload means.
 *
 * Three rules it follows throughout:
 *
 *  1. A field that is absent is absent. Nothing is defaulted to zero — a zero plots
 *     as a real reading and a gap plots as a gap, and the difference matters on a
 *     chart that claims to be a measurement.
 *  2. Nothing cross-sample. Churn, reserve *movement* and every percentile need two
 *     samples; they belong to the scoring layer, which can see the series. Extraction
 *     sees exactly one payload and must stay that way for the rebuild to be
 *     order-independent.
 *  3. Unknown shapes are skipped with a reason, never guessed at. The reason lands in
 *     extraction_log and is the first thing bin/health.php reports.
 */

declare(strict_types=1);

/**
 * Bump this when the parsing changes.
 *
 * bin/extract.php reprocesses every raw sample that has no extraction_log row at the
 * current version, so incrementing this and re-running rebuilds all derived rows from
 * payloads already on disk. That is the whole point of storing them verbatim (D3).
 *
 * 1 — first extractor. Written 13 Sep 2026 against documented response shapes; the
 *     field names are confirmed against real payloads the moment a key exists.
 */
const EXTRACTOR_VERSION = 1;

/**
 * @return array{
 *   status:string, note:?string,
 *   market:array<int,array{metric:string,value:float}>,
 *   asset:array<int,array{cmc_id:int,symbol:string,metric:string,value:float}>,
 *   universe:array<int,array{cmc_id:int,symbol:string,name:string,rank:?int}>
 * }
 */
function extract_sample(string $endpoint, ?string $payload): array
{
    $out = ['status' => 'ok', 'note' => null, 'market' => [], 'asset' => [], 'universe' => []];

    if ($payload === null || trim($payload) === '') {
        return ['status' => 'skipped', 'note' => 'no body stored (the request never returned one)'] + $out;
    }

    $body = json_decode($payload, true);
    if (!is_array($body)) {
        return ['status' => 'skipped', 'note' => 'body is not JSON: ' . json_last_error_msg()] + $out;
    }

    // CMC answers an unknown path with HTTP 200 and error_code 500 (see bin/README.md),
    // so the status block is the only trustworthy signal that a payload holds data.
    $errorCode = (int) ($body['status']['error_code'] ?? 0);
    if ($errorCode !== 0) {
        $message = (string) ($body['status']['error_message'] ?? 'no message');
        return ['status' => 'skipped', 'note' => "API error {$errorCode}: {$message}"] + $out;
    }

    $data = $body['data'] ?? null;
    if ($data === null) {
        return ['status' => 'skipped', 'note' => 'status was ok but there is no data block'] + $out;
    }

    $extractor = extractor_for($endpoint);
    if ($extractor === null) {
        return ['status' => 'skipped', 'note' => "no extractor for endpoint '{$endpoint}'"] + $out;
    }

    $result = $extractor($data, $body);

    $merged = array_merge($out, $result);
    if ($merged['market'] === [] && $merged['asset'] === [] && $merged['universe'] === []) {
        $merged['status'] = 'skipped';
        $merged['note'] ??= 'payload parsed but carried none of the fields this extractor needs';
    }

    return $merged;
}

/** @return null|callable(mixed, array<string,mixed>):array<string,mixed> */
function extractor_for(string $endpoint): ?callable
{
    $map = [
        'key_info'                 => 'extract_key_info',
        'global_metrics'           => 'extract_global_metrics',
        'listings_latest'          => 'extract_listings_latest',
        'quotes_latest'            => 'extract_quotes_latest',
        'fear_and_greed'           => 'extract_fear_and_greed',
        'exchange_listings'        => 'extract_exchange_listings',
        'content_latest'           => 'extract_content_latest',
        'community_trending_topic' => 'extract_trending_topic',
        'community_trending_token' => 'extract_trending_ranked',
        'trending_most_visited'    => 'extract_trending_ranked',
        'trending_latest'          => 'extract_trending_ranked',
    ];

    $fn = $map[$endpoint] ?? null;

    return $fn !== null && function_exists($fn) ? $fn : null;
}

// ---------------------------------------------------------------------------
// Support
// ---------------------------------------------------------------------------

/**
 * Credit budget, straight from the API rather than tallied locally.
 * Costs nothing to call, and makes the budget panel a measurement like everything else.
 */
function extract_key_info(mixed $data): array
{
    $usage = $data['usage'] ?? [];
    $month = $usage['current_month'] ?? [];

    $market = [];
    foreach ([
        'credits_used_month' => $month['credits_used'] ?? null,
        'credits_left_month' => $month['credits_left'] ?? null,
        'credits_used_today' => $usage['current_day']['credits_used'] ?? null,
    ] as $metric => $value) {
        if (is_numeric($value)) {
            $market[] = ['metric' => $metric, 'value' => (float) $value];
        }
    }

    return ['market' => $market];
}

// ---------------------------------------------------------------------------
// Money — market-wide
// ---------------------------------------------------------------------------

/**
 * The Money axis starts here. market_turnover — total volume over total market cap —
 * is the headline input: money moving relative to the size of the thing it moves in.
 *
 * derivatives_volume_24h is read if it is there. D10 rebuilt the axis around turnover
 * because no derivatives endpoint exists, but global-metrics carrying that one figure
 * would put a genuinely positioning-shaped number back on the axis, and this extractor
 * picks it up the first time it appears rather than needing to be remembered.
 */
function extract_global_metrics(mixed $data): array
{
    $quote = $data['quote']['USD'] ?? null;
    if (!is_array($quote)) {
        return ['status' => 'skipped', 'note' => 'no quote.USD block in global-metrics'];
    }

    $market = [];
    $push = static function (string $metric, mixed $value) use (&$market): void {
        if (is_numeric($value)) {
            $market[] = ['metric' => $metric, 'value' => (float) $value];
        }
    };

    $push('total_market_cap', $quote['total_market_cap'] ?? null);
    $push('total_volume_24h', $quote['total_volume_24h'] ?? null);
    $push('stablecoin_volume_24h', $quote['stablecoin_volume_24h'] ?? null);
    $push('altcoin_volume_24h', $quote['altcoin_volume_24h'] ?? null);
    $push('btc_dominance', $data['btc_dominance'] ?? null);
    $push('eth_dominance', $data['eth_dominance'] ?? null);
    $push('active_cryptocurrencies', $data['active_cryptocurrencies'] ?? null);

    // Confirmed present and callable on 13 Sep 2026 — the single positioning-shaped
    // number reachable on this API, and the reason D10 is amended rather than final.
    // Both spellings are accepted because only one has been seen.
    $push('derivatives_volume_24h', $quote['derivatives_volume_24h'] ?? $quote['derivative_volume_24h'] ?? null);
    $push('derivatives_24h_change', $quote['derivatives_24h_percentage_change'] ?? $data['derivatives_24h_percentage_change'] ?? null);

    // Reported volume is what exchanges claim; total_volume_24h is CMC's adjusted
    // figure. On 13 Sep 2026 reported was 5.1x adjusted. The ratio is a measurement of
    // how much of the day's stated activity survives CMC's own filtering — which is a
    // Money-axis question, and one CMC does not put on a chart anywhere.
    $reported = $quote['total_volume_24h_reported'] ?? null;
    $push('total_volume_24h_reported', $reported);
    if (is_numeric($reported) && is_numeric($quote['total_volume_24h'] ?? null) && (float) $quote['total_volume_24h'] > 0) {
        $push('reported_volume_ratio', (float) $reported / (float) $quote['total_volume_24h']);
    }

    // The only inputs in the product that carry their own history. Everything else
    // needs recording; these arrive pre-differenced, which is what makes the charts
    // non-empty on day one rather than after a week of banked samples.
    $push('total_volume_24h_change', $quote['total_volume_24h_yesterday_percentage_change'] ?? null);
    $push('total_market_cap_change', $quote['total_market_cap_yesterday_percentage_change'] ?? null);
    $push('btc_dominance_change', $data['btc_dominance_24h_percentage_change'] ?? null);
    $push('stablecoin_24h_change', $quote['stablecoin_24h_percentage_change'] ?? null);

    $push('defi_volume_24h', $quote['defi_volume_24h'] ?? null);
    $push('defi_market_cap', $quote['defi_market_cap'] ?? null);
    $push('stablecoin_market_cap', $quote['stablecoin_market_cap'] ?? null);
    $push('altcoin_market_cap', $quote['altcoin_market_cap'] ?? null);
    $push('active_exchanges', $data['active_exchanges'] ?? null);
    $push('active_market_pairs', $data['active_market_pairs'] ?? null);

    $cap = $quote['total_market_cap'] ?? null;
    $vol = $quote['total_volume_24h'] ?? null;
    if (is_numeric($cap) && is_numeric($vol) && (float) $cap > 0) {
        $push('market_turnover', (float) $vol / (float) $cap);
    }

    // What share of the day's volume is stablecoins changing hands rather than risk
    // being taken. A measurement, not a signal: the method page says so in those words.
    if (is_numeric($vol) && (float) $vol > 0 && is_numeric($quote['stablecoin_volume_24h'] ?? null)) {
        $push('stablecoin_volume_share', (float) $quote['stablecoin_volume_24h'] / (float) $vol);
    }

    return ['market' => $market];
}

/**
 * Exchange volume concentration. HHI over each venue's share of the 24h total: 1.0 is
 * one venue carrying everything, near zero is flow spread evenly. Whether the money
 * moving is broad or sitting in one place.
 */
function extract_exchange_listings(mixed $data): array
{
    if (!is_array($data)) {
        return ['status' => 'skipped', 'note' => 'exchange listings data is not a list'];
    }

    $volumes = [];
    foreach ($data as $exchange) {
        $volume = $exchange['quote']['USD']['volume_24h'] ?? null;
        if (is_numeric($volume) && (float) $volume > 0) {
            $volumes[] = (float) $volume;
        }
    }

    if ($volumes === []) {
        return ['status' => 'skipped', 'note' => 'no exchange carried a usable 24h volume'];
    }

    $total = array_sum($volumes);
    rsort($volumes);

    $hhi = 0.0;
    foreach ($volumes as $volume) {
        $hhi += ($volume / $total) ** 2;
    }

    $top5 = array_sum(array_slice($volumes, 0, 5));

    return ['market' => [
        ['metric' => 'exchange_hhi',          'value' => $hhi],
        ['metric' => 'exchange_top5_share',   'value' => $top5 / $total],
        ['metric' => 'exchange_volume_total', 'value' => $total],
        ['metric' => 'exchange_count',        'value' => (float) count($volumes)],
    ]];
}

// ---------------------------------------------------------------------------
// Voice — market-wide
// ---------------------------------------------------------------------------

/**
 * The one Voice input with real history behind it (open question 3) — and, since the
 * plan check on 13 Sep 2026, very nearly the only Voice input there is at all.
 *
 * /v3/fear-and-greed/latest returns an object; /historical returns a list, and that
 * list arrives **newest first**. An earlier version of this function took the last
 * element, which on the real payload is the *oldest* point — sixteen months stale and
 * entirely plausible-looking on a chart. So the most recent entry is now chosen by
 * comparing timestamps rather than by trusting the order.
 */
function extract_fear_and_greed(mixed $data): array
{
    if (!is_array($data)) {
        return ['status' => 'skipped', 'note' => 'fear-and-greed payload is neither an object nor a list'];
    }

    $point = null;
    if (isset($data['value'])) {
        $point = $data;
    } else {
        $newest = null;
        foreach ($data as $entry) {
            if (!is_array($entry) || !is_numeric($entry['value'] ?? null)) {
                continue;
            }
            $at = (int) ($entry['timestamp'] ?? 0);
            if ($newest === null || $at > $newest) {
                $newest = $at;
                $point = $entry;
            }
        }
    }

    if (!is_array($point) || !is_numeric($point['value'] ?? null)) {
        return ['status' => 'skipped', 'note' => 'no numeric value in the fear-and-greed payload'];
    }

    return ['market' => [['metric' => 'fear_greed', 'value' => (float) $point['value']]]];
}

/**
 * Post volume across the community feed — the closest thing CMC exposes to a
 * market-wide social volume number.
 *
 * The count is of posts in the window the endpoint returned, so it is only comparable
 * with itself at a fixed limit. That caveat belongs on the method page; it is recorded
 * here as post_count alongside the engagement totals so the two can be read together.
 */
function extract_content_latest(mixed $data): array
{
    if (!is_array($data)) {
        return ['status' => 'skipped', 'note' => 'content latest data is not a list'];
    }

    $posts = 0;
    $comments = 0.0;
    $likes = 0.0;
    foreach ($data as $post) {
        if (!is_array($post)) {
            continue;
        }
        $posts++;
        $comments += (float) ($post['comment_count'] ?? 0);
        $likes    += (float) ($post['like_count'] ?? 0);
    }

    if ($posts === 0) {
        return ['status' => 'skipped', 'note' => 'content feed returned no posts'];
    }

    return ['market' => [
        ['metric' => 'post_count',        'value' => (float) $posts],
        ['metric' => 'post_comments',     'value' => $comments],
        ['metric' => 'post_likes',        'value' => $likes],
        ['metric' => 'post_engagement',   'value' => ($comments + $likes) / $posts],
    ]];
}

/**
 * Trending topics carry no asset id, so they are recorded as a market-wide count and
 * nothing else. The signal in them is churn between consecutive samples, which needs
 * two payloads and therefore belongs to the scoring layer, not here.
 */
function extract_trending_topic(mixed $data): array
{
    if (!is_array($data)) {
        return ['status' => 'skipped', 'note' => 'trending topic data is not a list'];
    }

    return ['market' => [['metric' => 'trending_topic_count', 'value' => (float) count($data)]]];
}

/**
 * Trending, most-visited and trending-search all return an ordered list of assets.
 * Rank 1 is the top of the list; position in the payload is the ranking, and CMC does
 * not always include an explicit rank field.
 *
 * Storing rank per asset per sample is what later makes "how long has this been in the
 * top ten" and rank churn answerable without another call.
 */
function extract_trending_ranked(mixed $data): array
{
    if (!is_array($data)) {
        return ['status' => 'skipped', 'note' => 'trending data is not a list'];
    }

    $asset = [];
    $rank = 0;
    foreach ($data as $entry) {
        if (!is_array($entry) || !is_numeric($entry['id'] ?? null)) {
            continue;
        }
        $rank++;
        $asset[] = [
            'cmc_id' => (int) $entry['id'],
            'symbol' => (string) ($entry['symbol'] ?? ''),
            'metric' => 'trend_rank',
            'value'  => (float) $rank,
        ];
    }

    if ($asset === []) {
        return ['status' => 'skipped', 'note' => 'trending list carried no asset ids'];
    }

    return [
        'asset'  => $asset,
        'market' => [['metric' => 'trending_asset_count', 'value' => (float) count($asset)]],
    ];
}

// ---------------------------------------------------------------------------
// Per asset
// ---------------------------------------------------------------------------

/**
 * listings/latest defines the universe and carries the per-asset Money inputs in the
 * same call, which is why it is polled market-wide rather than per asset.
 */
function extract_listings_latest(mixed $data): array
{
    if (!is_array($data)) {
        return ['status' => 'skipped', 'note' => 'listings data is not a list'];
    }

    $asset = [];
    $universe = [];
    foreach ($data as $entry) {
        if (!is_array($entry) || !is_numeric($entry['id'] ?? null)) {
            continue;
        }
        $asset = array_merge($asset, asset_money_metrics($entry));
        $universe[] = [
            'cmc_id' => (int) $entry['id'],
            'symbol' => (string) ($entry['symbol'] ?? ''),
            'name'   => (string) ($entry['name'] ?? ''),
            'rank'   => is_numeric($entry['cmc_rank'] ?? null) ? (int) $entry['cmc_rank'] : null,
        ];
    }

    if ($universe === []) {
        return ['status' => 'skipped', 'note' => 'listings payload carried no assets'];
    }

    return [
        'asset'    => $asset,
        'universe' => $universe,
        'market'   => [['metric' => 'listings_count', 'value' => (float) count($universe)]],
    ];
}

/**
 * quotes/latest is keyed by id rather than being a list, because it is called with an
 * id batch. Open question 6 — whether 100 ids in one call is actually accepted — is
 * answered by counting the keys here against what was requested.
 */
function extract_quotes_latest(mixed $data): array
{
    if (!is_array($data)) {
        return ['status' => 'skipped', 'note' => 'quotes data is not an object'];
    }

    $asset = [];
    $count = 0;
    foreach ($data as $entry) {
        // v2 returns a list per id when the same symbol maps to several assets.
        foreach (is_array($entry) && array_is_list($entry) ? $entry : [$entry] as $item) {
            if (!is_array($item) || !is_numeric($item['id'] ?? null)) {
                continue;
            }
            $asset = array_merge($asset, asset_money_metrics($item));
            $count++;
        }
    }

    if ($asset === []) {
        return ['status' => 'skipped', 'note' => 'quotes payload carried no usable assets'];
    }

    return [
        'asset'  => $asset,
        'market' => [['metric' => 'quotes_asset_count', 'value' => (float) $count]],
    ];
}

/**
 * The per-asset Money inputs, shared by listings/latest and quotes/latest because both
 * carry the identical quote block. turnover is the one that matters: volume over market
 * cap, so a large asset and a small one are on the same scale.
 *
 * @return array<int,array{cmc_id:int,symbol:string,metric:string,value:float}>
 */
function asset_money_metrics(array $entry): array
{
    $quote = $entry['quote']['USD'] ?? null;
    if (!is_array($quote)) {
        return [];
    }

    $id = (int) $entry['id'];
    $symbol = (string) ($entry['symbol'] ?? '');

    $rows = [];
    $push = static function (string $metric, mixed $value) use (&$rows, $id, $symbol): void {
        if (is_numeric($value)) {
            $rows[] = ['cmc_id' => $id, 'symbol' => $symbol, 'metric' => $metric, 'value' => (float) $value];
        }
    };

    $push('volume_24h', $quote['volume_24h'] ?? null);
    $push('market_cap', $quote['market_cap'] ?? null);
    $push('volume_change_24h', $quote['volume_change_24h'] ?? null);
    $push('percent_change_24h', $quote['percent_change_24h'] ?? null);
    $push('price', $quote['price'] ?? null);
    $push('cmc_rank', $entry['cmc_rank'] ?? null);

    $cap = $quote['market_cap'] ?? null;
    $vol = $quote['volume_24h'] ?? null;
    if (is_numeric($cap) && is_numeric($vol) && (float) $cap > 0) {
        $push('turnover', (float) $vol / (float) $cap);
    }

    return $rows;
}
