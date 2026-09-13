<?php
/**
 * Host capability check. Run this on the host before anything else, over SSH:
 *
 *     php bin/preflight.php
 *
 * It answers open questions 4 and 5 and most of the deploy checklist, and prints a
 * markdown table ready to paste into the "Host checks" section of
 * docs/endpoint-access.md. It writes nothing and costs at most one API credit.
 *
 * Exit code 0 = everything the poller needs is present. 1 = something blocks it.
 */

declare(strict_types=1);

require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/http.php';
require __DIR__ . '/../lib/db.php';

$results = [];   // [check, result, ok]
$blocking = 0;

function check(string $name, bool $ok, string $detail, bool $blocks = true): void
{
    global $results, $blocking;
    $results[] = [$name, $detail, $ok];
    if (!$ok && $blocks) {
        $blocking++;
    }
    printf("  %s  %-46s %s\n", $ok ? 'ok  ' : 'FAIL', $name, $detail);
}

echo "\nPreflight — " . gmdate('Y-m-d H:i:s') . " UTC\n";
echo str_repeat('-', 78) . "\n";

// --- PHP itself -------------------------------------------------------------
// The cron entry needs the absolute binary path; cron's PATH is not a login shell's.
check('PHP CLI binary path', PHP_BINARY !== '', PHP_BINARY ?: 'unknown');
// 8.0, not 7.4: lib/http.php uses match() and str_contains(), so a 7.4 host does not
// fail at runtime with a clear message — it fails at parse time, before the poller can
// say anything at all. The floor this checks has to be the floor the code actually has.
check('PHP version', PHP_VERSION_ID >= 80000, PHP_VERSION . ' (need 8.0+)');
check('SAPI is CLI', PHP_SAPI === 'cli', PHP_SAPI);

foreach (['curl' => true, 'pdo_mysql' => true, 'json' => true, 'openssl' => true, 'zlib' => false] as $ext => $blocks) {
    check("Extension: {$ext}", extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'missing', $blocks);
}

// A shared host that caps max_execution_time in CLI will kill a run mid-fetch.
$maxExec = (int) ini_get('max_execution_time');
check('max_execution_time', $maxExec === 0 || $maxExec >= 60,
    $maxExec === 0 ? 'unlimited (CLI default)' : "{$maxExec}s", false);

// --- Config -----------------------------------------------------------------
[$config, $configError] = try_load_config();
check('Config file found', $config !== null, $config['_config_path'] ?? ($configError ?? 'not found'));

$hasKey = is_array($config) && !empty($config['cmc_api_key']) && $config['cmc_api_key'] !== 'YOUR_KEY_HERE';
check('API key present', $hasKey, $hasKey ? redact((string) $config['cmc_api_key']) : 'not set');

// The whole point of keeping it above the webroot. If this path is inside public/,
// the key is one HTTP request away from anyone.
if (is_array($config)) {
    $publicDir = realpath(__DIR__ . '/../public');
    $configReal = realpath((string) $config['_config_path']);
    $outside = $publicDir === false || $configReal === false || !str_starts_with($configReal, $publicDir);
    check('Config is outside the webroot', $outside, $outside ? 'yes' : "INSIDE {$publicDir}");
}

// --- Outbound HTTPS (open question 5) ---------------------------------------
// Some shared hosts firewall CLI outbound differently from the web SAPI, which would
// be a bad thing to discover after the poller is written.
$host = 'pro-api.coinmarketcap.com';
$ip = gethostbyname($host);
check('DNS resolves ' . $host, $ip !== $host, $ip !== $host ? $ip : 'resolution failed');

$errNo = 0;
$errStr = '';
$socket = @fsockopen('ssl://' . $host, 443, $errNo, $errStr, 8);
check('TLS connect to ' . $host . ':443', $socket !== false, $socket !== false ? 'connected' : "{$errStr} ({$errNo})");
if ($socket !== false) {
    fclose($socket);
}

if ($hasKey) {
    // One real authenticated call. /v1/key/info is free and tells us the plan tier,
    // which decides how much of the design survives.
    $res = cmc_get($config, '/v1/key/info');
    $ok = $res['http_status'] === 200;
    $detail = $ok ? 'HTTP 200' : ('HTTP ' . ($res['http_status'] ?? 'none') . ' ' . ($res['error'] ?? $res['api_error'] ?? ''));
    check('Authenticated call from PHP CLI', $ok, $detail);

    if ($ok && is_string($res['body'])) {
        $info = json_decode($res['body'], true);
        $plan = $info['data']['plan'] ?? [];
        $usage = $info['data']['usage'] ?? [];
        check('Plan tier', true, (string) ($plan['name'] ?? 'unknown'), false);
        check('Credits / month', true, sprintf(
            '%s used of %s',
            $usage['current_month']['credits_used'] ?? '?',
            $plan['credit_limit_monthly'] ?? '?'
        ), false);
        check('Rate limit / minute', true, (string) ($plan['rate_limit_minute'] ?? 'unknown'), false);
    }
}

// --- MySQL ------------------------------------------------------------------
if (is_array($config) && !empty($config['db_name'])) {
    try {
        $pdo = db_connect($config);
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        check('MySQL connect', true, $version);

        // 5.7+ matters for docs/data-model.md's original JSON-column plan; we store
        // payloads as LONGTEXT instead, so this is informational now.
        check('MySQL 5.7+ (JSON column support)', version_compare($version, '5.7', '>='), $version, false);

        $present = schema_is_present($pdo);
        check('Schema applied (001_init.sql)', $present,
            $present ? 'raw_samples + fetch_log exist' : 'run: mysql -u USER -p DB < sql/001_init.sql');

        // Not blocking: the recorder runs without the derived tables, and on day 1 it
        // should. Only the extractor needs these, and recording comes first.
        $derived = derived_schema_is_present($pdo);
        check('Derived tables (002_derived.sql)', $derived,
            $derived ? 'market_metric + asset_metric + scores exist' : 'not migrated yet — only needed for bin/extract.php',
            false);

        // Writing is the only privilege that matters; SELECT alone records nothing.
        $pdo->query('SELECT 1');
        $grants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        $canWrite = false;
        foreach ($grants as $grant) {
            if (stripos($grant, 'INSERT') !== false || stripos($grant, 'ALL PRIVILEGES') !== false) {
                $canWrite = true;
            }
        }
        check('DB user can INSERT', $canWrite, $canWrite ? 'yes' : 'no INSERT grant found', false);
    } catch (Throwable $e) {
        check('MySQL connect', false, $e->getMessage());
    }
} else {
    check('MySQL connect', false, 'db_name not set in config');
}

// --- Log directory ----------------------------------------------------------
if (is_array($config) && !empty($config['log_path'])) {
    $logDir = dirname((string) $config['log_path']);
    $writable = is_dir($logDir) && is_writable($logDir);
    check('Log directory writable', $writable, $writable ? $logDir : "{$logDir} (missing or read-only)", false);
}

// --- Summary ----------------------------------------------------------------
echo str_repeat('-', 78) . "\n";
echo $blocking === 0
    ? "All blocking checks passed. The poller can run here.\n"
    : "{$blocking} blocking check(s) failed. The poller will not record until they pass.\n";

echo "\nPaste into docs/endpoint-access.md under \"Host checks\":\n\n";
echo "| Check | Result |\n|---|---|\n";
foreach ($results as [$name, $detail, $ok]) {
    printf("| %s | %s %s |\n", $name, $ok ? '✅' : '❌', str_replace('|', '\\|', $detail));
}
echo "\n";

exit($blocking === 0 ? 0 : 1);
