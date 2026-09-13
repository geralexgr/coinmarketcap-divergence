<?php
/**
 * Assets — the screener, and one asset in detail.
 *
 * The axes here exist nowhere on CoinMarketCap's own site: sort the market by how much
 * an asset is being looked at against how much money is moving through it. That is the
 * "answers a question CMC cannot" criterion, and it is the reason the per-asset table
 * is a screen rather than a footnote on the market view.
 *
 * `?asset=<cmc_id|symbol>` switches to the detail view for one asset.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

[$pdo, $error] = web_connect();

$health = $pdo !== null ? recording_health($pdo) : ['samples' => 0, 'first_sample' => null, 'latest_sample' => null];
render_head('Assets', 'assets', $health);

if ($pdo === null) {
    render_empty_state($error ?? 'The database is unreachable.');
    render_foot();
    exit;
}

$reference = isset($_GET['asset']) ? trim((string) $_GET['asset']) : '';

// ---------------------------------------------------------------------------
// One asset
// ---------------------------------------------------------------------------
if ($reference !== '') {
    $asset = asset_by_reference($pdo, $reference);
    if ($asset === null) {
        render_empty_state("No asset matching '{$reference}' has been recorded in the tracked universe.", $health);
        render_foot();
        exit;
    }

    $window = query_choice('window', ['24h', '7d', '30d', 'all'], '7d');
    $series = asset_series($pdo, METHOD_VERSION, (int) $asset['cmc_id'], $window);
    $current = $series === [] ? null : $series[count($series) - 1];
    $transitions = array_reverse(quadrant_transitions($series));
    ?>

  <section class="assethead">
    <p class="crumb"><a href="assets.php">← All assets</a></p>
    <h1><?= h($asset['symbol']) ?> <span><?= h($asset['name']) ?></span></h1>
    <p class="muted">
      CoinMarketCap id <?= (int) $asset['cmc_id'] ?><?= $asset['rank_last'] !== null ? ', rank ' . (int) $asset['rank_last'] : '' ?>.
      In the tracked universe since <?= h(fmt_time($asset['first_seen'])) ?>, last seen
      <?= h(fmt_ago($asset['last_seen'])) ?>.
    </p>
  </section>

  <?php if ($current === null): ?>
    <?php render_empty_state('This asset is in the universe but has no score in the selected window yet.', $health); ?>
  <?php else: ?>
  <section class="stage">
    <div class="plotcol">
      <div class="plothead">
        <h2>Where <?= h($asset['symbol']) ?> sits</h2>
        <div class="windows">
          <?php foreach (['24h' => '24h', '7d' => '7d', '30d' => '30d', 'all' => 'All'] as $key => $label): ?>
            <?php if ($key === $window): ?><b><?= h($label) ?></b>
            <?php else: ?><a href="?asset=<?= (int) $asset['cmc_id'] ?>&amp;window=<?= h($key) ?>"><?= h($label) ?></a><?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
      <p class="plotsub">
        <?= count($series) ?> recorded sample<?= count($series) === 1 ? '' : 's' ?>. Each position is
        this asset ranked against the rest of the tracked universe at that same instant.
      </p>
      <?php
      // Per-asset axes are fed by a different set of inputs from the market chart —
      // no fear and greed, no exchange reserve — so the hints come from the per-asset
      // declaration rather than being assumed.
      $hint = static fn(string $axis): string => implode(', ', array_map(
          static fn(array $i): string => strtolower((string) $i['label']),
          available_inputs($axis, 'asset')
      ));
      ?>
      <div id="quadrant" class="quadrant"
           data-voice-hint="<?= h($hint('voice')) ?>"
           data-money-hint="<?= h($hint('money')) ?>"
           data-series='<?= h(json_encode($series, JSON_UNESCAPED_SLASHES)) ?>'
           data-gaps='<?= h(json_encode(gaps_in($series), JSON_UNESCAPED_SLASHES)) ?>'></div>
    </div>

    <aside class="readout">
      <div>
        <div class="bigcap">Gap</div>
        <div class="bignum"><?= h(fmt_score(abs($current['divergence']))) ?><small> of 100</small></div>
        <p class="bignote"><?= h(divergence_sentence($current['divergence'])) ?></p>
        <p class="provenance">
          <?= h(quadrant_label($current['quadrant'])) ?> · sampled <?= h(fmt_time($current['sampled_at'])) ?>
        </p>
      </div>

      <div class="pair">
        <div>
          <div class="pk v">Voice</div>
          <div class="pv"><?= h(fmt_score($current['voice'])) ?></div>
          <div class="pd">vs the universe</div>
        </div>
        <div>
          <div class="pk m">Money</div>
          <div class="pv"><?= h(fmt_score($current['money'])) ?></div>
          <div class="pd">vs the universe</div>
        </div>
      </div>

      <div class="inputs">
        <h3>Quadrant changes</h3>
        <p>When this asset crossed a midline in the recorded window. A description of what happened,
           not of what it means.</p>
        <?php if ($transitions === []): ?>
          <p class="muted">It has stayed in <?= h(quadrant_label($current['quadrant'])) ?> for the whole window.</p>
        <?php else: ?>
          <?php foreach (array_slice($transitions, 0, 6) as $t): ?>
            <div class="row">
              <span><?= h(quadrant_label($t['from'])) ?> → <?= h(quadrant_label($t['to'])) ?></span>
              <i><?= h(fmt_time($t['at'])) ?></i>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </aside>
  </section>
  <?php endif; ?>

<?php
    render_foot();
    exit;
}

// ---------------------------------------------------------------------------
// The screener
// ---------------------------------------------------------------------------
$sort = query_choice('sort', ['gap', 'voice', 'money', 'symbol', 'rank'], 'gap');
$quadrants = ['loud_and_leveraged', 'chatter_without_conviction', 'quiet_but_leveraged', 'apathy'];
$filter = query_choice('quadrant', $quadrants, '') ?: null;

$rows = latest_asset_scores($pdo, METHOD_VERSION, $sort, $filter, 200);

if ($rows === []) {
    render_empty_state(
        $filter === null
            ? 'No per-asset scores have been computed yet.'
            : 'No asset is currently in ' . quadrant_label($filter) . '.',
        $health
    );
    render_foot();
    exit;
}

/** Keeps the current filter when a sort link is followed, and the reverse. */
$link = static function (array $overrides) use ($sort, $filter): string {
    $params = array_filter(['sort' => $sort, 'quadrant' => $filter] + [], static fn($v) => $v !== null);
    return 'assets.php?' . http_build_query(array_filter($overrides + $params, static fn($v) => $v !== null && $v !== ''));
};
?>

<section class="tablewrap wide">
  <div class="plothead">
    <h2>The screener</h2>
    <div class="windows">
      <?php if ($filter === null): ?><b>All</b><?php else: ?><a href="<?= h($link(['quadrant' => ''])) ?>">All</a><?php endif; ?>
      <?php foreach ($quadrants as $q): ?>
        <?php if ($q === $filter): ?><b><?= h(quadrant_label($q)) ?></b>
        <?php else: ?><a href="<?= h($link(['quadrant' => $q])) ?>"><?= h(quadrant_label($q)) ?></a><?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <p class="plotsub">
    <?= count($rows) ?> assets, sampled <?= h(fmt_time($rows[0]['sampled_at'])) ?>. Each asset's two
    scores are its rank against the rest of the tracked universe at that instant — turnover in the
    92nd percentile of the top 100 means exactly that, and nothing about what happens next.
    <a href="method.php#basis">How this differs from the market chart</a>.
  </p>

  <table class="screener">
    <thead>
      <tr>
        <th><a href="<?= h($link(['sort' => 'symbol'])) ?>">Asset</a></th>
        <th><a href="<?= h($link(['sort' => 'rank'])) ?>">Rank</a></th>
        <th><a href="<?= h($link(['sort' => 'voice'])) ?>">Voice</a></th>
        <th><a href="<?= h($link(['sort' => 'money'])) ?>">Money</a></th>
        <th><a href="<?= h($link(['sort' => 'gap'])) ?>">Gap</a></th>
        <th class="reading">Reading</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
      <tr>
        <td class="tick"><a href="assets.php?asset=<?= (int) $row['cmc_id'] ?>"><?= h($row['symbol']) ?></a>
            <em><?= h($row['name']) ?></em></td>
        <td class="muted"><?= $row['rank_last'] !== null ? (int) $row['rank_last'] : '—' ?></td>
        <td><?= h(fmt_score($row['voice'])) ?></td>
        <td><?= h(fmt_score($row['money'])) ?></td>
        <td class="gap <?= $row['divergence'] >= 0 ? 'm' : 'v' ?>"><?= h(fmt_signed($row['divergence'])) ?></td>
        <td class="quad-tag reading"><?= h(quadrant_label($row['quadrant'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="tfoot">
    A positive gap is money moving ahead of attention; a negative one is attention ahead of money.
    Both are readings of a recorded moment. Nothing on this screen is a recommendation.
  </p>
</section>

<?php
render_foot();
