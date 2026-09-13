<?php
/**
 * The method as data — the same declaration the scorer runs from.
 *
 *     api/method.php
 *
 * Exists so a consumer can explain where a number came from rather than asserting it.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

echo json_encode(method_description() + [
    'quadrants' => [
        'loud_and_leveraged'         => 'Voice >= 50, Money >= 50',
        'chatter_without_conviction' => 'Voice >= 50, Money < 50',
        'quiet_but_leveraged'        => 'Voice < 50, Money >= 50',
        'apathy'                     => 'Voice < 50, Money < 50',
    ],
    'note' => 'A quadrant is a label for where a point sits. It is not a rating and implies no action.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
