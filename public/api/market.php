<?php
/**
 * The current market reading, with the raw inputs behind it.
 *
 *     api/market.php
 *
 * The inputs are included in their native units with the endpoint and sample minute
 * attached, so this response is enough on its own to check the score against
 * CoinMarketCap without opening the site.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');

[$pdo, $error] = web_connect();
if ($pdo === null) {
    http_response_code(503);
    echo json_encode(['error' => $error ?? 'The database is unreachable.'], JSON_PRETTY_PRINT);
    exit;
}

$current = latest_market_score($pdo, METHOD_VERSION);
$health = recording_health($pdo);

if ($current === null) {
    http_response_code(503);
    echo json_encode([
        'error'  => 'No market score has been computed yet.',
        'health' => $health,
    ], JSON_PRETTY_PRINT);
    exit;
}

echo json_encode([
    'sampled_at'     => $current['sampled_at'],
    'voice'          => $current['voice'],
    'money'          => $current['money'],
    'divergence'     => $current['divergence'],
    'quadrant'       => $current['quadrant'],
    'quadrant_label' => quadrant_label($current['quadrant']),
    'reading'        => divergence_sentence($current['divergence']),
    // The row summary is the weaker of the two axes, so the per-axis values are what a
    // consumer needs to know how each score was produced (D22).
    'basis'          => $current['basis'],
    'voice_basis'    => $current['voice_basis'] ?? null,
    'money_basis'    => $current['money_basis'] ?? null,
    'method_version' => $current['method_version'],
    'inputs_used'    => ['voice' => $current['voice_inputs'], 'money' => $current['money_inputs']],
    'inputs' => (static function (PDO $pdo): array {
        $derived = current_derived_inputs($pdo);
        return [
            'voice' => current_input_values($pdo, available_inputs('voice', 'market'), $derived),
            'money' => current_input_values($pdo, available_inputs('money', 'market'), $derived),
        ];
    })($pdo),
    'health' => $health,
    'note'   => 'A measurement of a condition at the recorded sample time. Not advice, and not a forecast.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
