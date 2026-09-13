<?php
/**
 * The test runner. No framework, no composer, no network, no database.
 *
 *     php tests/run.php
 *     php tests/run.php extract       # only files matching this
 *
 * A dependency here would be a dependency the shared host has to satisfy before the
 * tests can be run where it matters, which is on the host. So: plain functions, and
 * a non-zero exit when anything fails.
 *
 * Tests live in tests/*_test.php and register cases with test().
 */

declare(strict_types=1);

$GLOBALS['divergence_tests'] = [];

/** @param callable():void $fn */
function test(string $name, callable $fn): void
{
    $GLOBALS['divergence_tests'][] = [$name, $fn];
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%s\n     expected: %s\n     actual:   %s",
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

/** Floats are compared with a tolerance, because turnover is a division. */
function assert_close(float $expected, float $actual, string $message, float $epsilon = 1e-9): void
{
    if (abs($expected - $actual) > $epsilon) {
        throw new RuntimeException(sprintf('%s (expected %.12g, got %.12g)', $message, $expected, $actual));
    }
}

/** Reads a fixture file and fails loudly rather than returning null. */
function fixture(string $relativePath): string
{
    $path = __DIR__ . '/fixtures/' . $relativePath;
    $body = @file_get_contents($path);
    if ($body === false) {
        throw new RuntimeException("Missing fixture: {$path}");
    }
    return $body;
}

// ---------------------------------------------------------------------------

$filter = $argv[1] ?? null;

foreach (glob(__DIR__ . '/*_test.php') ?: [] as $file) {
    if ($filter !== null && strpos(basename($file), $filter) === false) {
        continue;
    }
    require $file;
}

$passed = 0;
$failed = 0;

echo "\n";
foreach ($GLOBALS['divergence_tests'] as [$name, $fn]) {
    try {
        $fn();
        $passed++;
        echo "  ok   {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL {$name}\n       " . $e->getMessage() . "\n";
    }
}

printf("\n%d passed, %d failed\n\n", $passed, $failed);

exit($failed > 0 ? 1 : 0);
