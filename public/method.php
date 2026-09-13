<?php
/**
 * Method — how every number on this site is produced.
 *
 * Rendered from `scoring/inputs.php`, which is the same declaration the scorer runs
 * from. The page cannot describe a method the code does not implement, because there is
 * only one description of the method and this is a view of it.
 *
 * It states the limits first. Ten of the seventeen endpoints this product was designed
 * around are forbidden on the plan behind the key, and a method page that buried that
 * would be worth less than no method page at all.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

[$pdo, $error] = web_connect();

$health = $pdo !== null ? recording_health($pdo) : ['samples' => 0, 'first_sample' => null, 'latest_sample' => null];
render_head('Method', 'method', $health);

$method = method_description();
$access = endpoint_access_results();
$activity = $pdo !== null ? endpoint_activity($pdo) : [];

$switchover = null;
if ($pdo !== null && $health['first_sample'] !== null) {
    $switchover = gmdate('j F Y, H:i', (int) strtotime($health['first_sample'] . ' UTC') + FIXED_BASIS_DAYS * 86400);
}

$axisTitles = [
    'market.voice' => 'Voice — market-wide',
    'market.money' => 'Money — market-wide',
    'asset.voice'  => 'Voice — per asset',
    'asset.money'  => 'Money — per asset',
];
?>

<section class="prose">
  <h1>Method</h1>
  <p class="lede">
    This tool measures the gap between what the crypto market is <b>saying</b> and what it has
    <b>committed money to</b>. Both halves come from the CoinMarketCap API. Everything below states
    exactly how each number is produced, so any figure on this site can be checked against
    CoinMarketCap directly.
  </p>

  <div class="callout">
    <h2>What this is not</h2>
    <p>
      No advice, no recommendations, no forecasts. Every output describes a condition measured at a
      recorded moment. A quadrant is a label for where a point currently sits — it is not a rating
      and it implies no action. If a sentence anywhere on this site points at the future, it is a
      bug.
    </p>
  </div>

  <h2 id="plan">What the plan permits, and what it costs the method</h2>
  <p>
    The API key behind this deployment is on CoinMarketCap's <b>Basic</b> plan. Measured against
    the live API rather than read off a pricing page: seven of the seventeen endpoints this product
    was designed around are callable and ten answer HTTP&nbsp;403.
  </p>
  <p>
    The Voice axis takes the damage. Trending, most-visited, community and content are all
    forbidden, which leaves the <b>fear and greed index</b> — one input, updated <b>once a day</b>.
    So Voice steps daily while Money moves every sample, and the flat stretches on the market chart
    are that, not a market that went quiet. Per asset it is worse: there is no attention endpoint at
    all, and the proxy used instead is described in the table below and labelled as the weakest
    number in the product.
  </p>
  <p>
    The forbidden inputs are still declared in the method. They carry their intended weights, they
    are shown below marked unavailable, and the day the plan permits them they begin contributing
    with the method version bumped so the change is visible in the recorded data rather than
    rewriting it.
  </p>

  <h3>What the Money axis can and cannot see</h3>
  <p>
    This axis was designed around funding rates, open interest and liquidations. <b>None of those
    exist on the CoinMarketCap API at any version</b> — 38 candidate paths probed, every one absent,
    and not as a plan restriction. What replaced them measures money <i>moving</i> and money
    <i>at rest</i>, not money <i>committed and leveraged</i>. Turnover cannot distinguish a large
    spot rotation from a leveraged build-up, because nothing in the available data carries leverage.
  </p>
  <p>
    The one partial exception is <b>derivative share of activity</b>: global-metrics does carry
    derivative volume, so how much of the day's trading happened in contracts rather than in the
    asset is reachable. It is still a volume figure — it says how much was traded, never how much is
    still held.
  </p>

  <h2 id="inputs">The inputs</h2>
  <p>
    Each raw input is scaled to 0–100, then weighted. Inputs with no data are dropped and the
    surviving weights are renormalised over what remains — a missing input is never counted as a
    zero, because zero is a measurement of a quiet market and absence is a measurement of nothing.
    Each score records how many of its declared inputs it actually saw.
  </p>

  <?php foreach ($method['axes'] as $key => $inputs): ?>
    <h3><?= h($axisTitles[$key] ?? $key) ?></h3>
    <table class="method">
      <thead>
        <tr><th>Input</th><th>Endpoint</th><th>Range</th><th>Weight</th><th>Status</th></tr>
      </thead>
      <tbody>
        <?php foreach ($inputs as $input): ?>
        <tr class="<?= $input['available'] ? '' : 'unavailable' ?>">
          <td>
            <b><?= h($input['label']) ?></b>
            <em><?= h($input['rationale']) ?></em>
          </td>
          <td class="mono"><?= h($input['endpoint']) ?></td>
          <td class="mono">
            <?= h(rtrim(rtrim(number_format((float) $input['floor'], 4, '.', ''), '0'), '.')) ?>
            → <?= h(rtrim(rtrim(number_format((float) $input['ceiling'], 4, '.', ''), '0'), '.')) ?>
            <em><?= h($input['unit']) ?><?= $input['invert'] ? ', inverted' : '' ?></em>
          </td>
          <td class="mono"><?= h(number_format((float) $input['weight'], 2)) ?></td>
          <td>
            <?php if ($input['available']): ?>
              <span class="tag ok">in use</span>
            <?php elseif ((float) $input['weight'] <= 0): ?>
              <span class="tag off">declared, weight 0</span>
            <?php else: ?>
              <span class="tag no">403 on this plan</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endforeach; ?>

  <h2 id="divergence">Divergence</h2>
  <p class="formula">divergence = money − voice</p>
  <p>
    Signed, from −100 to +100. Positive means money committed is running ahead of narrative;
    negative means narrative is running ahead of money committed. The headline figure on the market
    screen is the magnitude, with the direction stated in the sentence beside it.
  </p>

  <h3>Quadrants</h3>
  <p>Midlines at 50 on both axes, on the same basis as the scores themselves.</p>
  <table class="method quadrants">
    <thead><tr><th></th><th>Money &lt; 50</th><th>Money ≥ 50</th></tr></thead>
    <tbody>
      <tr><th>Voice ≥ 50</th><td>Chatter without conviction</td><td>Loud and leveraged</td></tr>
      <tr><th>Voice &lt; 50</th><td>Apathy</td><td>Quiet, but leveraged</td></tr>
    </tbody>
  </table>

  <h2 id="basis">Normalisation, and the two bases</h2>
  <p>
    Which basis produced a given score is stored on the row and never inferred. Scores from
    different bases are not strictly comparable, and this page says so rather than letting one chart
    quietly mix them.
  </p>

  <h3>Market-wide: fixed ranges for the first <?= FIXED_BASIS_DAYS ?> days, then percentile rank</h3>
  <p>
    Percentile rank against trailing history is meaningless when there is no history — the first
    day's scores would be ranked against a handful of samples and the plot would jump between 0 and
    100 for no reason. So each input is min-max scaled against the hand-set reference range in the
    tables above until <?= FIXED_BASIS_DAYS ?> days of recording exist, and after that against its
    percentile rank within a trailing <?= PERCENTILE_WINDOW_DAYS ?>-day window (or all of recorded
    history, whichever is shorter — during this deployment it is the latter).
  </p>
  <?php if ($switchover !== null): ?>
    <p class="stat">
      Recording started <?= h(fmt_time($health['first_sample'])) ?>, so the switchover
      <?= strtotime($health['first_sample'] . ' UTC') + FIXED_BASIS_DAYS * 86400 < time() ? 'happened' : 'falls' ?>
      on <b><?= h($switchover) ?> UTC</b>.
    </p>
  <?php endif; ?>

  <h3>Per asset: ranked against the universe, not against its own past</h3>
  <p>
    Per-asset scores use a third basis, recorded as <span class="mono">cross_section</span>. Each
    asset's inputs are ranked against <b>the rest of the tracked universe at the same instant</b>
    rather than against that asset's own history: turnover in the 92nd percentile of the top 100
    right now. That is the question a screener is actually asked, and it needs no banked history, so
    the table works from the first sample. It is a different measurement from the market chart and
    the two are never plotted together.
  </p>

  <h2 id="endpoints">Endpoints used</h2>
  <p>
    Measured with a real key against the live API. <span class="mono">ok</span> means HTTP 200 with
    a data block; <span class="mono">403</span> means the path exists and this plan may not call it.
  </p>
  <table class="method">
    <thead>
      <tr><th>Endpoint</th><th>Path</th><th>Axis</th><th>Access</th>
      <?php if ($activity !== []): ?><th>Calls</th><th>Credits</th><?php endif; ?></tr>
    </thead>
    <tbody>
      <?php
      $byEndpoint = [];
      foreach ($activity as $row) {
          $byEndpoint[$row['endpoint']] = $row;
      }
      foreach (endpoint_catalogue() as $entry):
          if ($entry['axis'] === 'absent') {
              continue;
          }
          $seen = $byEndpoint[$entry['key']] ?? null;
      ?>
      <tr class="<?= ($access[$entry['key']] ?? '') === 'ok' ? '' : 'unavailable' ?>">
        <td><b><?= h($entry['key']) ?></b><em><?= h($entry['need']) ?></em></td>
        <td class="mono"><?= h($entry['path']) ?></td>
        <td><?= h($entry['axis']) ?></td>
        <td>
          <?php if (($access[$entry['key']] ?? '') === 'ok'): ?>
            <span class="tag ok">ok</span>
          <?php else: ?>
            <span class="tag no">403</span>
          <?php endif; ?>
        </td>
        <?php if ($activity !== []): ?>
          <td class="mono"><?= $seen === null ? '—' : number_format((int) $seen['attempts']) ?></td>
          <td class="mono"><?= $seen === null ? '—' : number_format((int) $seen['credits']) ?></td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h3>Absent from the API entirely</h3>
  <p>
    Not a plan restriction — these paths do not resolve at any version, and the Money axis was
    rebuilt around what does:
    <span class="mono">/v1/derivatives/listings/latest</span>,
    <span class="mono">/v1/derivatives/funding-rate/latest</span>,
    <span class="mono">/v1/derivatives/open-interest/latest</span>,
    <span class="mono">/v1/derivatives/liquidations/latest</span>.
  </p>

  <h2 id="health">Recording health</h2>
  <?php if ($pdo === null): ?>
    <p class="muted"><?= h($error ?? 'The database is unreachable.') ?></p>
  <?php else: ?>
  <p>
    Live from <span class="mono">raw_samples</span> and <span class="mono">fetch_log</span>. Every
    fetch attempt is logged, including the ones that failed, which is what makes the gap handling
    honest: a missed window is shown as a gap and the charts break the line rather than
    interpolating across it.
  </p>
  <dl class="stats">
    <div><dt>Raw samples</dt><dd><?= number_format((int) $health['samples']) ?></dd></div>
    <div><dt>Recording since</dt><dd><?= h(fmt_time($health['first_sample'])) ?></dd></div>
    <div><dt>Latest sample</dt><dd><?= h(fmt_ago($health['latest_sample'])) ?></dd></div>
    <div><dt>Fetch attempts</dt><dd><?= number_format((int) $health['attempts']) ?></dd></div>
    <div><dt>Failure rate</dt><dd><?= h(number_format((float) $health['failure_rate'], 2)) ?>%</dd></div>
    <div><dt>Longest gap</dt><dd><?= $health['longest_gap_minutes'] === null ? '—' : h(number_format((float) $health['longest_gap_minutes'], 0)) . ' min' ?></dd></div>
    <div><dt>Scores computed</dt><dd><?= number_format((int) $health['scores']) ?></dd></div>
    <div><dt>Credits consumed</dt><dd><?= number_format((int) $health['credits_used']) ?></dd></div>
  </dl>
  <?php endif; ?>

  <h2 id="version">Version and provenance</h2>
  <p>
    Method version <b><?= (int) $method['method_version'] ?></b>. Every score row records the method
    version and the basis that produced it, so changing a weight adds a parallel series rather than
    rewriting the one already recorded.
  </p>
  <p>
    Raw API responses are stored verbatim alongside every derived figure, which means the parsing
    and the weighting can both be corrected later without losing a single sample. The
    <span class="mono">raw_sample_id</span> behind any number on this site is recorded in the
    database next to it.
  </p>
</section>

<?php
render_foot();
