<?php
/**
 * Copy tests — the sentences on the pages, checked against the declaration behind them.
 *
 * The method page is rendered from `scoring/inputs.php` and `lib/endpoints.php`, but the
 * paragraphs around those tables are hand-written, and that is exactly where this product
 * went wrong once: the page went on telling readers that funding rate, open interest and
 * liquidations do not exist on the CoinMarketCap API for two method versions after they
 * were found under `/v5/`, rebuilt into the Money axis, and printed in use in the table
 * directly below the claim. A method page contradicted by its own table is worth less
 * than no method page.
 *
 * Prose cannot be generated from data without becoming unreadable, so what is checked
 * here is the part that can be: no page may name a path as absent that the measured
 * catalogue knows, and an axis hint may not misstate how many inputs the axis has.
 *
 * No database, no network, no key.
 */

declare(strict_types=1);

require_once __DIR__ . '/../public/bootstrap.php';

/** The pages, read once. */
function page_source(string $file): string
{
    $path = __DIR__ . '/../public/' . $file;
    $body = @file_get_contents($path);
    if ($body === false) {
        throw new RuntimeException("Missing page: {$path}");
    }
    return $body;
}

test('a path the catalogue calls absent is not one it also reaches', function (): void {
    // The method page renders its absent list from `exists`, so the page can only be
    // wrong if the catalogue is. This is that check: an entry marked absent must not
    // also carry a measured access result, and must not be polled. Both together are
    // the D20 mistake in data form — a path called unreachable while being recorded.
    foreach (endpoint_catalogue() as $entry) {
        if ($entry['exists'] !== 'no') {
            continue;
        }

        assert_same(
            'absent',
            $entry['access'],
            "{$entry['path']} is marked absent but carries a measured access result"
        );
        assert_true(
            $entry['poll'] === false,
            "{$entry['path']} is marked absent but is scheduled for polling"
        );
    }

    // And no path is in the catalogue twice under two different answers.
    $seen = [];
    foreach (endpoint_catalogue() as $entry) {
        $path = (string) $entry['path'];
        assert_true(
            !isset($seen[$path]) || $seen[$path] === $entry['exists'],
            "{$path} appears twice with different existence answers"
        );
        $seen[$path] = $entry['exists'];
    }
});

test('the method page names no endpoint the catalogue does not measure', function (): void {
    // The reverse drift: a path printed in prose that no longer exists anywhere in the
    // catalogue is a path nothing is checking. Absent-path sections are exempt, since
    // naming a path that is not in the catalogue is the whole point of those.
    $known = array_column(endpoint_catalogue(), 'path');
    $source = preg_replace(
        '/<h3>Absent from the API entirely<\/h3>.*?<\/p>/s',
        '',
        page_source('method.php')
    ) ?? '';

    preg_match_all('#/v\d+/[a-z0-9\-/]+#', $source, $paths);
    foreach (array_unique($paths[0]) as $path) {
        assert_true(
            in_array($path, $known, true),
            "the method page names {$path}, which is in no catalogue entry"
        );
    }
});

test('an axis hint names every input when they all fit', function (): void {
    $hint = axis_hint([
        ['label' => 'Turnover'],
        ['label' => 'Volume change, 24h'],
    ]);

    assert_same('turnover · volume change, 24h', $hint, 'both inputs, middot-separated');
});

test('an axis hint that drops inputs says how many it dropped', function (): void {
    // Three of five named as a plain list states something false about the axis on the
    // axis itself — the market Money hint read "open interest, funding rate,
    // liquidations, 24h" while the axis was scoring five inputs.
    $hint = axis_hint(array_map(
        static fn(string $l): array => ['label' => $l],
        ['Open interest', 'Funding rate', 'Liquidations, 24h', 'Open interest vs volume', 'Turnover']
    ));

    assert_true(str_contains($hint, '+2 more'), "a truncated hint says what it left out: {$hint}");
    assert_true(!str_contains($hint, 'turnover'), 'and does not also name them');
});

test('an axis hint never separates inputs with a comma', function (): void {
    // Two declared labels contain a comma. A comma-joined hint reads as one more input
    // than the axis has, and "24h" arrives looking like an input of its own.
    foreach (['market', 'asset'] as $scope) {
        foreach (['voice', 'money'] as $axis) {
            $inputs = available_inputs($axis, $scope);
            $hint = axis_hint($inputs);
            if (count($inputs) < 2) {
                continue;
            }

            $parts = explode(' · ', $hint);
            assert_same(
                min(count($inputs), 4),
                count($parts),
                "{$scope}.{$axis} hint splits into one part per named input: {$hint}"
            );
        }
    }
});

test('an axis with no callable input has no hint to print', function (): void {
    assert_same('', axis_hint([]), 'an empty axis prints nothing rather than a stray separator');
});
