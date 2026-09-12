<?php
/**
 * Copy to ../config.php — OUTSIDE the webroot — and fill in.
 * Never commit the filled-in version. A key in git history is a direct hit on
 * the code quality score.
 */
return [
    'cmc_api_key'   => 'YOUR_KEY_HERE',
    'cmc_base_url'  => 'https://pro-api.coinmarketcap.com',

    'db_host'       => 'localhost',
    'db_name'       => '',
    'db_user'       => '',
    'db_pass'       => '',

    // Operational
    'log_path'          => '/home/USER/logs/divergence.log',
    'asset_universe'    => 100,   // top N by market cap
    'request_timeout'   => 10,    // seconds; shared hosts kill long runs
    'max_requests_per_minute' => 30,
];
