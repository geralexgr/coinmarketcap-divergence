<?php
/**
 * Config loading.
 *
 * The real config file is never committed and never sits under the webroot. Both
 * supported locations satisfy that, because the webroot is `public/`, not the repo root:
 *
 *     /home/USER/
 *     ├── config.php                 ← option B
 *     └── site/                      ← the repo
 *         ├── config.php             ← option A, gitignored
 *         ├── app/lib/config.php     ← this file
 *         └── public/                ← the document root. Nothing above it is served.
 *
 * Option A keeps the whole deployment in one directory, which is what a cPanel account
 * with a per-subdomain folder wants: upload one folder, and the config travels with it
 * while still being unreachable over HTTP. Option B is right when several deployments
 * share one config, or when the repo directory is replaced wholesale on each update.
 *
 * Search order, first hit wins:
 *   1. $DIVERGENCE_CONFIG            — explicit path, for anything unusual
 *   2. ../config.php                 — one above the repo root
 *   3. ./config.php                  — repo root, gitignored
 *
 * Whichever is used, `public/bootstrap.php` refuses to serve if the resolved path turns
 * out to be inside the document root, so a wrong choice fails loudly instead of quietly
 * publishing the API key.
 */

declare(strict_types=1);

const DIVERGENCE_DEFAULTS = [
    'cmc_base_url'            => 'https://pro-api.coinmarketcap.com',
    'db_host'                 => 'localhost',
    'db_port'                 => 3306,
    // Resolved in apply_config_defaults() to <deployment root>/logs/divergence.log, so a
    // deployment is one self-contained folder with nothing to create by hand. Set an
    // absolute path to override, or false to disable file logging entirely.
    'log_path'                => null,
    // Top N by market cap. 200 because CMC prices listings/latest per 200 data points
    // returned, so 200 assets and 100 assets cost the same single credit (D23). Raising
    // it past 200 costs a credit per further 200 and multiplies asset_metric row volume,
    // which is the real ceiling on a shared host rather than the credit budget.
    'asset_universe'          => 200,
    'request_timeout'         => 10,
    // Measured 13 Sep 2026 on the Basic plan (D14). bin/health.php prefers the figure
    // CoinMarketCap itself reports via /v1/key/info and falls back to this.
    'max_requests_per_minute' => 50,
    'credit_limit_monthly'    => 15000,
];

/**
 * The deployment root — the directory holding app/ and public/.
 *
 * Two levels up, not one: this file is app/lib/config.php, so dirname(__DIR__) is app/.
 * Getting this wrong would search for the config inside app/, and would put the log file
 * there too.
 */
function divergence_root(): string
{
    return dirname(dirname(__DIR__));
}

/**
 * Defaults, plus the paths that can only be known once the root is.
 *
 * `log_path` defaults to `<root>/logs/divergence.log` so everything a deployment writes
 * stays inside the deployment folder. On shared hosting that matters: a folder you can
 * delete in one action is a deployment you can remove in one action, and nothing is left
 * scattered around the home directory afterwards.
 *
 * @param array<string,mixed> $config
 * @return array<string,mixed>
 */
function apply_config_defaults(array $config, string $foundPath): array
{
    $config = array_merge(DIVERGENCE_DEFAULTS, $config);
    $config['_config_path'] = $foundPath;

    if ($config['log_path'] === null) {
        $config['log_path'] = divergence_root() . '/logs/divergence.log';
    }

    return $config;
}

/**
 * Create the log directory if it is not there yet.
 *
 * Called by the writers, never by the web app. One less manual step in a runbook is one
 * less step to get wrong, and a missing log directory otherwise fails silently: the
 * write is suppressed, so the poller runs correctly and logs nothing.
 *
 * @param array<string,mixed> $config
 */
function ensure_log_dir(array $config): void
{
    if (empty($config['log_path'])) {
        return;
    }

    $dir = dirname((string) $config['log_path']);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

/**
 * @return string[] The paths that will be searched, in order.
 */
function config_candidate_paths(): array
{
    $repoRoot = divergence_root();
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

    $config = apply_config_defaults($config, $found);

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
        return [apply_config_defaults($config, $path), null];
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
