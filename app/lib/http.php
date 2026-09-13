<?php
/**
 * The CoinMarketCap client. One function that makes one call and tells the truth
 * about what happened, including when what happened was nothing.
 *
 * The API key travels in the X-CMC_PRO_API_KEY header, never in the query string.
 * CMC accepts both; a key in a query string ends up in access logs, in proxy logs,
 * and in any URL this code prints or stores.
 */

declare(strict_types=1);

/**
 * A single attempt. Never throws — a transport failure is a result, not an exception,
 * because a failed fetch is a row in fetch_log exactly like a successful one.
 *
 * @param array<string,mixed> $config
 * @param array<string,scalar> $query
 * @return array{
 *   url:string, http_status:?int, body:?string, duration_ms:int,
 *   credits:?int, error:?string, api_error:?string, elapsed_api:?float
 * }
 */
function cmc_get(array $config, string $path, array $query = []): array
{
    $url = rtrim((string) $config['cmc_base_url'], '/') . '/' . ltrim($path, '/');
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $timeout = (int) $config['request_timeout'];
    $started = microtime(true);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'X-CMC_PRO_API_KEY: ' . $config['cmc_api_key'],
            'Accept: application/json',
        ],
        // CMC responses are large and shared hosts are not on fast links.
        CURLOPT_ENCODING       => 'gzip, deflate',
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'divergence/0.1 (+https://github.com/geralexgr/coinmarketcap-divergence)',
    ]);

    $body   = curl_exec($ch);
    $errNo  = curl_errno($ch);
    $errStr = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    // No curl_close(): it has done nothing since PHP 8.0 and is deprecated from 8.5,
    // where calling it writes a deprecation line into every cron log. The handle is
    // released when it goes out of scope.
    unset($ch);

    $durationMs = (int) round((microtime(true) - $started) * 1000);

    $result = [
        'url'         => $url,
        'http_status' => $status > 0 ? $status : null,
        'body'        => is_string($body) ? $body : null,
        'duration_ms' => $durationMs,
        'credits'     => null,
        'error'       => $errNo !== 0 ? "curl({$errNo}): {$errStr}" : null,
        'api_error'   => null,
        'elapsed_api' => null,
    ];

    // Every CMC response, success or error, carries a status block. Credits are read
    // from it rather than counted locally, so the budget is observed and not guessed.
    if (is_string($body) && $body !== '') {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['status']) && is_array($decoded['status'])) {
            $s = $decoded['status'];
            if (isset($s['credit_count']) && is_numeric($s['credit_count'])) {
                $result['credits'] = (int) $s['credit_count'];
            }
            if (isset($s['elapsed']) && is_numeric($s['elapsed'])) {
                $result['elapsed_api'] = (float) $s['elapsed'];
            }
            if (!empty($s['error_message'])) {
                $result['api_error'] = (string) $s['error_message'];
            }
        } elseif ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            // An HTML error page from the host's proxy, typically. Worth knowing.
            $result['error'] = trim(($result['error'] ?? '') . ' non-JSON body: ' . substr($body, 0, 200));
        }
    }

    return $result;
}

/**
 * Keeps a run under the plan's requests-per-minute ceiling by spacing calls evenly.
 *
 * Even spacing rather than a burst-then-wait window: a shared host will kill a long
 * sleeping process, and an evenly spaced run of a dozen calls finishes in seconds.
 */
final class RateLimiter
{
    private float $minIntervalSeconds;
    private ?float $lastCallAt = null;

    public function __construct(int $maxPerMinute)
    {
        $this->minIntervalSeconds = $maxPerMinute > 0 ? 60.0 / $maxPerMinute : 0.0;
    }

    public function wait(): void
    {
        if ($this->lastCallAt !== null) {
            $elapsed = microtime(true) - $this->lastCallAt;
            $remaining = $this->minIntervalSeconds - $elapsed;
            if ($remaining > 0) {
                usleep((int) round($remaining * 1_000_000));
            }
        }
        $this->lastCallAt = microtime(true);
    }
}

/**
 * What a response actually means, which is not always what its HTTP status says.
 *
 * Two behaviours of pro-api.coinmarketcap.com make the naive reading wrong:
 *
 *  1. An unknown path under a known version prefix returns **HTTP 200** with
 *     `status.error_code = 500, "The system is busy, please try again later!"`.
 *     Verified 12 Sep 2026: /v3/totally-made-up/xyz and /v5/anything both do this.
 *     Treating that as success would have put six non-existent derivatives endpoints
 *     in the catalogue as available.
 *  2. Path resolution happens before key validation, so an invalid key returns 401 on
 *     a real path and 404 on a fake one. That is what makes bin/probe-paths.php able
 *     to map the API surface without a key at all.
 *
 * @param array{http_status:?int, body:?string, error:?string} $res
 * @return string One of: ok, unauthorized, forbidden, not_found, bad_request,
 *                rate_limited, server_error, no_response
 */
function cmc_outcome(array $res): string
{
    if ($res['http_status'] === null) {
        return 'no_response';
    }

    $errorCode = null;
    $errorMessage = '';
    if (is_string($res['body']) && $res['body'] !== '') {
        $decoded = json_decode($res['body'], true);
        if (is_array($decoded) && isset($decoded['status']['error_code'])) {
            $errorCode = (int) $decoded['status']['error_code'];
            $errorMessage = (string) ($decoded['status']['error_message'] ?? '');
        }
    }

    // The catch-all. A 200 that carries an error code is not a success.
    if ($res['http_status'] === 200 && $errorCode !== null && $errorCode !== 0) {
        return str_contains($errorMessage, 'system is busy') ? 'not_found' : 'server_error';
    }

    return match (true) {
        $res['http_status'] === 200 => 'ok',
        $res['http_status'] === 401 => 'unauthorized',
        $res['http_status'] === 403 => 'forbidden',
        $res['http_status'] === 404 => 'not_found',
        $res['http_status'] === 400 => 'bad_request',
        $res['http_status'] === 429 => 'rate_limited',
        $res['http_status'] >= 500  => 'server_error',
        default                     => 'server_error',
    };
}

/**
 * Does this path exist on the API at all, independent of whether our key may call it?
 *
 * 'yes'     — the router recognised it (401 invalid key, 403 not on plan, 400 missing
 *             parameter, or a real 200)
 * 'no'      — 404, or the 200-shaped catch-all
 * 'unknown' — nothing came back
 */
function cmc_path_exists(array $res): string
{
    return match (cmc_outcome($res)) {
        'ok', 'unauthorized', 'forbidden', 'bad_request', 'rate_limited' => 'yes',
        'not_found' => 'no',
        default     => 'unknown',
    };
}
