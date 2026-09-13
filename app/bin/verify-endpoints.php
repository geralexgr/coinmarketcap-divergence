<?php
/**
 * Phase 0 — call every candidate endpoint once and report what actually happened.
 *
 *     php bin/verify-endpoints.php                  # all of them
 *     php bin/verify-endpoints.php --only=money     # the block that decides the design
 *     php bin/verify-endpoints.php --save-fixtures  # keep the payloads for offline work
 *
 * Run it on the host, not a laptop — the point is partly to prove the host can make
 * these calls at all (open question 5).
 *
 * Reading the output: 200 means the endpoint is ours. 403 means it exists and our plan
 * does not include it, which is a plan question. 404 means the path does not exist and
 * no plan upgrade will produce it — for the derivatives block, 404 everywhere settles
 * open question 2 against us and the Money axis takes the documented fallback.
 *
 * Costs one credit per endpoint that answers. Measured 13 Sep 2026: 6 credits of a
 * 15,000/month budget — forbidden endpoints are charged nothing.
 */

declare(strict_types=1);

require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/http.php';
require __DIR__ . '/../lib/endpoints.php';

$options = getopt('', ['only::', 'save-fixtures', 'out::']);
$onlyAxis = $options['only'] ?? null;
$saveFixtures = array_key_exists('save-fixtures', $options);
$outPath = $options['out'] ?? __DIR__ . '/../../docs/endpoint-access.generated.md';

$config = load_config(['cmc_api_key']);
$limiter = new RateLimiter((int) $config['max_requests_per_minute']);

$fixtureDir = __DIR__ . '/../../tests/fixtures/live';
if ($saveFixtures && !is_dir($fixtureDir)) {
    mkdir($fixtureDir, 0755, true);
}

$catalogue = endpoints_to_verify();
if (is_string($onlyAxis) && $onlyAxis !== '') {
    $catalogue = array_values(array_filter(
        $catalogue,
        static fn(array $e): bool => $e['axis'] === $onlyAxis
    ));
}

echo "\nVerifying " . count($catalogue) . " endpoints against " . $config['cmc_base_url'] . "\n";
echo "Key " . redact((string) $config['cmc_api_key']) . " · " . gmdate('Y-m-d H:i:s') . " UTC\n";
echo str_repeat('-', 100) . "\n";

$rows = [];
$creditsSpent = 0;

foreach ($catalogue as $entry) {
    $limiter->wait();
    $res = cmc_get($config, $entry['path'], $entry['query']);
    $creditsSpent += $res['credits'] ?? 0;

    $status = $res['http_status'];
    $outcome = cmc_outcome($res);
    $shape = describe_shape($res['body']);
    $symbol = outcome_symbol($outcome);

    // The HTTP status alone lies here: an unknown path answers 200 with an error body.
    $note = $outcome === 'ok'
        ? $shape['summary']
        : trim($outcome . ' — ' . ($res['api_error'] ?? $res['error'] ?? ''));

    printf(
        "%s %-3s %-28s %-46s %s\n",
        $symbol,
        $status ?? '—',
        $entry['key'],
        $entry['path'],
        substr($note, 0, 70)
    );

    $rows[] = [
        'entry'   => $entry,
        'status'  => $status,
        'outcome' => $outcome,
        'credits' => $res['credits'],
        'ms'      => $res['duration_ms'],
        'shape'   => $shape,
        'note'    => $note,
        'symbol'  => $symbol,
    ];

    if ($saveFixtures && is_string($res['body']) && $res['body'] !== '') {
        file_put_contents("{$fixtureDir}/{$entry['key']}.json", $res['body']);
    }
}

echo str_repeat('-', 100) . "\n";
printf("%d credits spent.\n", $creditsSpent);

// The question this phase exists to settle, restated now that derivatives are gone:
// how much of the Money axis our plan can actually reach.
$byAxis = [];
foreach ($rows as $r) {
    $byAxis[$r['entry']['axis']][$r['outcome'] === 'ok' ? 'ok' : 'blocked'][] = $r['entry']['key'];
}
foreach (['money' => 'Money axis', 'voice' => 'Voice axis', 'support' => 'Support'] as $axis => $label) {
    $ok = $byAxis[$axis]['ok'] ?? [];
    $blocked = $byAxis[$axis]['blocked'] ?? [];
    printf("\n%-12s %d available: %s\n", $label, count($ok), $ok === [] ? '(none)' : implode(', ', $ok));
    if ($blocked !== []) {
        printf("%-12s %d blocked:   %s\n", '', count($blocked), implode(', ', $blocked));
    }
}
if (($byAxis['money']['ok'] ?? []) === []) {
    echo "\nNo Money input is reachable. Stop and record what that leaves in docs/decisions.md\n"
       . "before writing a fetcher against anything.\n";
}
echo "\n";

file_put_contents($outPath, render_markdown($rows, $creditsSpent, (string) $config['cmc_base_url']));
echo "\nWritten to {$outPath} — paste the tables into docs/endpoint-access.md.\n\n";

// ---------------------------------------------------------------------------

function outcome_symbol(string $outcome): string
{
    return match ($outcome) {
        'ok'           => '✅',
        'unauthorized',
        'forbidden'    => '❌',
        'not_found'    => '⛔',
        'rate_limited' => '⏳',
        'no_response'  => '💥',
        default        => '⚠️',
    };
}

/**
 * What came back, in one line. Enough to tell whether the field we need is in there
 * without pasting a 200 KB payload into a markdown table.
 *
 * @return array{summary:string, keys:string[], count:?int}
 */
function describe_shape(?string $body): array
{
    if ($body === null || $body === '') {
        return ['summary' => 'empty body', 'keys' => [], 'count' => null];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['summary' => 'non-JSON: ' . substr($body, 0, 60), 'keys' => [], 'count' => null];
    }

    $data = $decoded['data'] ?? null;
    if ($data === null) {
        return ['summary' => 'no data block', 'keys' => array_keys($decoded), 'count' => null];
    }

    if (is_array($data) && array_is_list($data)) {
        $first = $data[0] ?? null;
        $keys = is_array($first) ? array_keys($first) : [];
        return [
            'summary' => count($data) . ' items; first item keys: ' . implode(', ', array_slice($keys, 0, 12)),
            'keys'    => $keys,
            'count'   => count($data),
        ];
    }

    if (is_array($data)) {
        $keys = array_keys($data);
        $firstChild = reset($data);
        $childKeys = is_array($firstChild) ? array_keys($firstChild) : [];
        return [
            'summary' => count($keys) . ' keys: ' . implode(', ', array_slice($keys, 0, 8))
                . ($childKeys !== [] ? ' → ' . implode(', ', array_slice($childKeys, 0, 8)) : ''),
            'keys'    => $keys,
            'count'   => count($keys),
        ];
    }

    return ['summary' => 'scalar data: ' . substr((string) $data, 0, 60), 'keys' => [], 'count' => null];
}

/** @param array<int,array<string,mixed>> $rows */
function render_markdown(array $rows, int $creditsSpent, string $baseUrl): string
{
    $out = "# Endpoint access — generated\n\n";
    $out .= 'Generated by `bin/verify-endpoints.php` on ' . gmdate('Y-m-d H:i:s') . " UTC against `{$baseUrl}`.\n";
    $out .= "Every row below is a real call, not a reading of the documentation. {$creditsSpent} credits spent.\n\n";
    $out .= "Status key: ✅ 200 · ❌ 401/403 not on plan · ⛔ 404 path does not exist · ⚠️ other · 💥 no response\n\n";

    foreach (['money' => 'Money axis', 'voice' => 'Voice axis', 'support' => 'Universe and support'] as $axis => $title) {
        $axisRows = array_values(array_filter($rows, static fn(array $r): bool => $r['entry']['axis'] === $axis));
        if ($axisRows === []) {
            continue;
        }

        $out .= "## {$title}\n\n";
        $out .= "| Endpoint | Path | Field needed | Outcome | Credits | ms | What came back |\n";
        $out .= "|---|---|---|---|---|---|---|\n";
        foreach ($axisRows as $r) {
            $out .= sprintf(
                "| `%s` | `%s` | %s | %s %s | %s | %d | %s |\n",
                $r['entry']['key'],
                $r['entry']['path'],
                $r['entry']['need'],
                $r['symbol'],
                $r['outcome'],
                $r['credits'] ?? '—',
                $r['ms'],
                str_replace('|', '\\|', substr($r['note'], 0, 160))
            );
        }
        $out .= "\n";
    }

    return $out;
}
