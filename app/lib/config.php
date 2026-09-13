<?php
/**
 * Config loading.
 *
 * The real config file never lives in the repo and never lives under the webroot.
 * The deploy layout puts it one level above the repo:
 *
 *     /home/USER/config.php          ← the real one, chmod 600
 *     /home/USER/divergence/         ← this repo
 *         app/lib/config.php         ← this file
 *         public/                    ← the only web-served directory
 *
 * Search order, first hit wins:
 *   1. $DIVERGENCE_CONFIG            — explicit path, for anything unusual
 *   2. ../config.php                 — one above the repo root, the deploy layout
 *   3. ./config.php                  — repo root, gitignored, for local work only
 */

declare(strict_types=1);

const DIVERGENCE_DEFAULTS = [
    'cmc_base_url'            => 'https://pro-api.coinmarketcap.com',
    'db_host'                 => 'localhost',
    'db_port'                 => 3306,
    'log_path'                => null,
    'asset_universe'          => 100,
    'request_timeout'         => 10,
    // Measured 13 Sep 2026 on the Basic plan (D14). bin/health.php prefers the figure
    // CoinMarketCap itself reports via /v1/key/info and falls back to this.
    'max_requests_per_minute' => 50,
    'credit_limit_monthly'    => 15000,
];

/**
 * @return string[] The paths that will be searched, in order.
 */
function config_candidate_paths(): array
{
    // Two levels up, not one: this file is app/lib/config.php, so dirname(__DIR__) is
    // app/ and the repo root is above that. Getting this wrong would search inside the
    // repo for a file whose whole purpose is to live outside it.
    $repoRoot = dirname(dirname(__DIR__));
    $paths = [];

    $fromEnv = getenv('DIVERGENCE_CONFIG');
    if (is_string($fromEnv) && $fromEnv !== '') {
        $paths[] = $fromEnv;
    }

    $paths[] = dirname($repoRoot) . '/config.php';
    $paths[] = $repoRoot . '/config.php';

    return $paths;
}

/**
 * Load and validate the config, or exit with an explanation of where it looked.
 *
 * @param string[] $required Keys that must be present and non-empty for this caller.
 *                           bin/preflight.php needs none; the poller needs the db and the key.
 * @return array<string,mixed>
 */
function load_config(array $required = ['cmc_api_key', 'db_name', 'db_user']): array
{
    $found = null;
    foreach (config_candidate_paths() as $path) {
        if (is_readable($path)) {
            $found = $path;
            break;
        }
    }

    if ($found === null) {
        fwrite(STDERR, "No config file found. Looked in:\n");
        foreach (config_candidate_paths() as $path) {
            fwrite(STDERR, "  - {$path}\n");
        }
        fwrite(STDERR, "\nCopy config.example.php to one of those and fill it in.\n");
        exit(2);
    }

    /** @var mixed $config */
    $config = require $found;
    if (!is_array($config)) {
        fwrite(STDERR, "Config at {$found} did not return an array.\n");
        exit(2);
    }

    $config = array_merge(DIVERGENCE_DEFAULTS, $config);
    $config['_config_path'] = $found;

    $missing = [];
    foreach ($required as $key) {
        if (!isset($config[$key]) || $config[$key] === '' || $config[$key] === 'YOUR_KEY_HERE') {
            $missing[] = $key;
        }
    }
    if ($missing !== []) {
        fwrite(STDERR, "Config at {$found} is missing: " . implode(', ', $missing) . "\n");
        exit(2);
    }

    return $config;
}

/**
 * Same search, but a missing or broken config is a return value rather than an exit.
 * bin/preflight.php needs to report on a host that has no config yet.
 *
 * @return array{0:?array<string,mixed>, 1:?string} [config, error]
 */
function try_load_config(): array
{
    foreach (config_candidate_paths() as $path) {
        if (!is_readable($path)) {
            continue;
        }
        /** @var mixed $config */
        $config = require $path;
        if (!is_array($config)) {
            return [null, "Config at {$path} did not return an array."];
        }
        $config = array_merge(DIVERGENCE_DEFAULTS, $config);
        $config['_config_path'] = $path;
        return [$config, null];
    }

    return [null, 'No config file found.'];
}

/**
 * Anything that would print a config value goes through this first.
 * Keys end up in logs, in terminal scrollback, and in pasted output otherwise.
 */
function redact(string $secret): string
{
    $len = strlen($secret);
    if ($len <= 8) {
        return str_repeat('*', $len);
    }
    return substr($secret, 0, 4) . str_repeat('*', $len - 8) . substr($secret, -4);
}
