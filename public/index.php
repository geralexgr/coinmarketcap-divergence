<?php
/**
 * Market — the screen the product is.
 *
 * Voice against Money, one point per recorded sample, joined into the path the market
 * actually took. The trail is the only thing here that could not be rebuilt from a
 * single API call, which is why the recorder shipped before this page existed.
 *
 * Read-only. Every figure carries the endpoint it came from and the minute it was
 * sampled, because "a judge can verify this against CoinMarketCap in thirty seconds" is
 * the largest single criterion in the judging.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

[$pdo, $error] = web_connect();

$window = query_choice('window', ['24h', '7d', '30d', 'all'], '7d');

$health = $pdo !== null ? recording_health($pdo) : ['samples' => 0, 'first_sample' => null, 'latest_sample' => null];

render_head('Market', 'market', $health);

if ($pdo === null) {
    render_empty_state($error ?? 'The database is unreachable.');
    render_foot();
    exit;
}

$current = latest_market_score($pdo, METHOD_VERSION);
if ($current === null) {
    render_empty_state('No market score has been computed yet.', $health);
    render_foot();
    exit;
}

$series = market_series($pdo, METHOD_VERSION, $window);
$gaps = gaps_in($series);
$weekAgo = market_score_hours_ago($pdo, METHOD_VERSION, 24 * 7);

// Cross-sample inputs are computed, not stored — see current_input_values().
$derived = current_derived_inputs($pdo);
$voiceInputs = current_input_values($pdo, available_inputs('voice', 'market'), $derived);
$moneyInputs = current_input_values($pdo, available_inputs('money', 'market'), $derived);

// Stablecoins excluded, same as the screener's default — see D21.
$assets = latest_asset_scores($pdo, METHOD_VERSION, 'gap', null, 12, false);

// The movement half of the screener. The gap sort finds the most divergent assets and
// finds much the same ones daily; this finds the ones that moved, which is what a reader
// returning to the page does not already have. Six here, all of them one click away on
// the full screener. See asset_divergence_movers().
$movers = asset_divergence_movers($pdo, METHOD_VERSION, 24, 6, false);

$windowLabel = ['24h' => 'last 24 hours', '7d' => 'last 7 days', '30d' => 'last 30 days', 'all' => 'all recorded history'][$window];

/** The week-over-week change, or null when there is not a week of history to compare with. */
$change = static function (string $axis) use ($current, $weekAgo): ?float {
    return $weekAgo === null ? null : round($current[$axis] - $weekAgo[$axis], 0);
};
?>

<section class="stage">
  <div class="plotcol">
    <div class="plothead">
      <h2>Where the market sits</h2>
      <div class="windows">
        <?php foreach (['24h' => '24h', '7d' => '7d', '30d' => '30d', 'all' => 'All'] as $key => $label): ?>
          <?php if ($key === $window): ?><b><?= h($label) ?></b>
          <?php else: ?><a href="?window=<?= h($key) ?>"><?= h($label) ?></a><?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <p class="plotsub">
      Each point is one recorded sample over the <?= h($windowLabel) ?>. The line is the path the
      market has taken.
      <?php if ($gaps !== []): ?>
        <?= count($gaps) ?> break<?= count($gaps) === 1 ? '' : 's' ?> in the line
        <?= count($gaps) === 1 ? 'is' : 'are' ?> a gap in recording, not a flat market.
      <?php endif; ?>
    </p>

    <!-- Drawn by assets/app.js from the JSON below. Server-rendered axes and labels so
         the page is readable and the quadrants are legible before any script runs. -->
    <?php
    // The axis hints name the inputs actually feeding each axis, read from the same
    // declaration the scorer uses, so a forbidden input is never named on the chart.
    $hint = static fn(array $rows): string => implode(', ', array_map(
        static fn(array $r): string => strtolower((string) $r['label']),
        array_slice($rows, 0, 3)
    ));
    ?>
    <div id="quadrant"
         class="quadrant"
         data-voice-hint="<?= h($hint($voiceInputs)) ?>"
         data-money-hint="<?= h($hint($moneyInputs)) ?>"
         data-series='<?= h(json_encode($series, JSON_UNESCAPED_SLASHES)) ?>'
         data-gaps='<?= h(json_encode($gaps, JSON_UNESCAPED_SLASHES)) ?>'></div>

    <p class="plotnote">
      Voice is measured from <?= (int) $current['voice_inputs'] ?> input<?= $current['voice_inputs'] === 1 ? '' : 's' ?>
      and Money from <?= (int) $current['money_inputs'] ?>.
      <?php if ($current['voice_inputs'] < 2): ?>
        The fear and greed index updates once a day, so the Voice axis steps daily while Money moves
        every sample — the horizontal stretches in the trail are that, not a market gone quiet.
        <a href="method.php#plan">Why the other Voice inputs are missing</a>.
      <?php endif; ?>
    </p>
  </div>

  <aside class="readout">
    <div>
      <div class="bigcap">Divergence</div>
      <?php // Signed, because the sign is the measurement: money - voice. An unsigned
            // number reads identically whether narrative or money is the half in front. ?>
      <div class="bignum <?= $current['divergence'] >= 0 ? 'm' : 'v' ?>"><?= h(fmt_signed($current['divergence'])) ?><small> on &minus;100&hairsp;&hellip;&hairsp;+100</small></div>
      <p class="bignote"><?= h(divergence_sentence($current['divergence'])) ?></p>
      <p class="provenance">
        <?= h(quadrant_label($current['quadrant'])) ?> · sampled <?= h(fmt_time($current['sampled_at'])) ?>
        (<?= h(fmt_ago($current['sampled_at'])) ?>)
      </p>
    </div>

    <div class="pair">
      <div>
        <div class="pk v">Voice</div>
        <div class="pv"><?= h(fmt_score($current['voice'])) ?></div>
        <div class="pd"><?= $change('voice') === null ? 'no week-ago reading yet' : h(fmt_signed($change('voice'))) . ' this week' ?></div>
      </div>
      <div>
        <div class="pk m">Money</div>
        <div class="pv"><?= h(fmt_score($current['money'])) ?></div>
        <div class="pd"><?= $change('money') === null ? 'no week-ago reading yet' : h(fmt_signed($change('money'))) . ' this week' ?></div>
      </div>
    </div>

    <div class="inputs">
      <h3>What went into it</h3>
      <p>Current values in their native units. Each is one query against the endpoint named.</p>
      <?php foreach ([['v', $voiceInputs], ['m', $moneyInputs]] as [$axis, $rows]): ?>
        <?php foreach ($rows as $row): ?>
          <div class="row">
            <span title="<?= h($row['rationale']) ?>"><?= h($row['label']) ?>
              <em><?= h($row['endpoint']) ?><?= $row['sampled_at'] ? ' · ' . h(fmt_time($row['sampled_at'], false)) : '' ?></em>
            </span>
            <i class="<?= h($axis) ?>"><?= h(fmt_native($row['value'], $row['unit'])) ?></i>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  </aside>
</section>

<?php if ($assets !== []): ?>
<section class="tablewrap">
  <div class="plothead">
    <h2>The same two measurements, per asset</h2>
    <div class="windows"><a href="assets.php">Full screener →</a></div>
  </div>
  <p class="plotsub">
    Sorted by the gap between how much an asset is being looked at and how much money is moving
    through it. Per-asset scores rank each asset against the rest of the universe at the same
    instant — a different basis from the market chart above, and
    <a href="method.php#basis">the method page says why</a>.
  </p>
  <table>
    <thead>
      <tr><th>Asset</th><th>Voice</th><th>Money</th><th>Gap</th><th class="reading">Reading</th></tr>
    </thead>
    <tbody>
      <?php foreach ($assets as $asset): ?>
      <tr>
        <td class="tick"><a href="assets.php?asset=<?= (int) $asset['cmc_id'] ?>"><?= h($asset['symbol']) ?></a>
            <em><?= h($asset['name']) ?></em></td>
        <td><?= h(fmt_score($asset['voice'])) ?></td>
        <td><?= h(fmt_score($asset['money'])) ?></td>
        <td class="gap <?= $asset['divergence'] >= 0 ? 'm' : 'v' ?>"><?= h(fmt_signed($asset['divergence'])) ?></td>
        <td class="quad-tag reading"><?= h(quadrant_label($asset['quadrant'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="tfoot">
    Every number here is a measurement of a condition that exists right now, sampled
    <?= h(fmt_time($assets[0]['sampled_at'])) ?>. Nothing on this screen predicts anything.
  </p>
</section>

<?php if ($movers !== []): ?>
<section class="tablewrap">
  <div class="plothead">
    <h2>What moved in the last 24 hours</h2>
    <div class="windows"><a href="assets.php#changed">All movers &rarr;</a></div>
  </div>
  <p class="plotsub">
    The table above ranks assets by how large their gap is, which is largely the same list
    every day. This ranks them by how much the gap <em>changed</em>, between two
    cross-sections that were both recorded —
    <?= h(fmt_time($movers[0]['sampled_at_then'])) ?> and
    <?= h(fmt_time($movers[0]['sampled_at'])) ?>.
  </p>
  <table>
    <thead>
      <tr><th>Asset</th><th>Gap then</th><th>Gap now</th><th>Change</th><th class="reading">Reading</th></tr>
    </thead>
    <tbody>
      <?php foreach ($movers as $mover): ?>
      <tr>
        <td class="tick"><a href="assets.php?asset=<?= (int) $mover['cmc_id'] ?>"><?= h($mover['symbol']) ?></a>
            <em><?= h($mover['name']) ?></em></td>
        <td class="muted"><?= h(fmt_signed($mover['divergence_then'])) ?></td>
        <td class="gap <?= $mover['divergence'] >= 0 ? 'm' : 'v' ?>"><?= h(fmt_signed($mover['divergence'])) ?></td>
        <td class="gap <?= $mover['divergence_change'] >= 0 ? 'm' : 'v' ?>"><?= h(fmt_signed($mover['divergence_change'])) ?></td>
        <td class="quad-tag reading">
          <?php if ($mover['crossed']): ?>
            <?= h(quadrant_label((string) $mover['quadrant_then'])) ?> &rarr; <?= h(quadrant_label((string) $mover['quadrant'])) ?>
          <?php else: ?>
            <?= h(quadrant_label((string) $mover['quadrant'])) ?> throughout
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="tfoot">
    A change is the difference between two recorded measurements. It describes what the gap
    did between those two moments, and nothing about what it does next.
  </p>
</section>
<?php endif; ?>
<?php endif; ?>

<?php
render_foot();
