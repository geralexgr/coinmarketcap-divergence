<?php
/**
 * Copy this to `config.php` and fill it in. Two valid locations:
 *
 *   1. Beside this file, in the repo root — gitignored, and NOT web-reachable
 *      because the document root is `public/`, one level below it. Simplest for a
 *      cPanel subdomain: everything lives in one folder.
 *   2. One directory above the repo root — use this when several deployments share
 *      a config, or when the repo folder is replaced wholesale on each update.
 *
 * Never commit the filled-in version, and never put it inside `public/` — the app
 * refuses to start if it finds it there.
 *
 * chmod 600 once it holds the real key.
 */
return [
    'cmc_api_key'   => 'YOUR_KEY_HERE',
    'cmc_base_url'  => 'https://pro-api.coinmarketcap.com',

    'db_host'       => 'localhost',
    'db_name'       => '',
    'db_user'       => '',
    'db_pass'       => '',

    // Operational
    // Logs go to <this folder>/logs/divergence.log, created automatically on the first
    // run — everything a deployment writes stays inside the deployment folder.
    // Uncomment to put them elsewhere, or set false to disable the file entirely
    // (cron still captures output through its own redirect).
    // 'log_path'       => '/absolute/path/to/divergence.log',
    'asset_universe'    => 200,   // top N by market cap. 200 costs the same credit as 100 (D23)
    'request_timeout'   => 10,    // seconds; shared hosts kill long runs

    // Basic plan, measured 13 Sep 2026 — see docs/decisions.md D14.
    // Both are reported by /v1/key/info; these are the fallback before the first sample.
    'max_requests_per_minute' => 50,
    'credit_limit_monthly'    => 15000,
];
