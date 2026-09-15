<?php
/**
 * Shared setup for every page and endpoint under public/.
 *
 * The web app is read-only. There is no write path anywhere in this directory: the only
 * writer in the system is cron, which means a bad request cannot corrupt history and a
 * page can be cached, mirrored or served from a stale replica without risk.
 *
 * This file is under the webroot but is never served: it emits nothing on its own, and
 * `.htaccess` blocks direct requests for it. Only `index.php`, `assets.php`,
 * `method.php` and `api/*.php` are entry points.
 */

declare(strict_types=1);

/**
 * Refuse to run when requested directly.
 *
 * `.htaccess` denies this file, and on Apache that is enough. LiteSpeed — which is what
 * cPanel actually runs — served it anyway: the live deployment returned HTTP 200 for
 * /bootstrap.php. It emitted nothing, because the file only defines functions, so the
 * exposure was small. It was still a file the server was willing to execute on request.
 *
 * A guard in PHP does not care which web server is in front of it, so this is the check
 * that actually holds. The .htaccess rule stays as the first line of defence.
 */
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../app/lib/config.php';
require_once __DIR__ . '/../app/lib/db.php';
require_once __DIR__ . '/../app/lib/endpoints.php';
require_once __DIR__ . '/../app/lib/queries.php';
require_once __DIR__ . '/../app/scoring/inputs.php';
require_once __DIR__ . '/../app/scoring/normalise.php';
require_once __DIR__ . '/../app/scoring/score.php';
require_once __DIR__ . '/../app/scoring/recompute.php';

/**
 * Refuse to serve anything if the config file is inside the document root.
 *
 * The config holds the API key and the database password. If a deployment puts it
 * somewhere a browser can request, the correct behaviour is to stop, loudly — a working
 * site with a downloadable key is far worse than a site that says it is misconfigured.
 *
 * This is the web-side twin of the check `app/bin/preflight.php` makes over SSH. It is
 * here because the failure it guards against is a deployment mistake, and deployment
 * mistakes are made by people who are not running preflight.
 *
 * @param array<string,mixed> $config
 */
function config_is_web_exposed(array $config): bool
{
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $configPath = realpath((string) ($config['_config_path'] ?? ''));

    if ($docRoot === false || $configPath === false) {
        return false;
    }

    return str_starts_with($configPath, rtrim($docRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
}

/**
 * Connect, or hand back the reason.
 *
 * A page that cannot reach the database says so plainly rather than showing a 500 or,
 * worse, an empty chart that reads as a market with nothing happening in it.
 *
 * @return array{0:?PDO, 1:?string}
 */
function web_connect(): array
{
    [$config, $error] = try_load_config();
    if ($config === null) {
        return [null, $error ?? 'No config file found.'];
    }

    if (config_is_web_exposed($config)) {
        return [null,
            'Refusing to start: the config file holding the API key and database password is inside '
            . 'the document root, where a browser can request it. Move it above the webroot — the '
            . 'layout is in DEPLOY.md — and rotate the API key, because it may already have been fetched.'];
    }

    try {
        return [db_connect($config), null];
    } catch (Throwable $e) {
        return [null, 'Cannot reach the database.'];
    }
}

/**
 * One query parameter, constrained to a known set.
 *
 * Written as a helper because the obvious inline form has a bug in it:
 *
 *     $sort = in_array($_GET['sort'] ?? 'gap', $allowed, true) ? (string) $_GET['sort'] : 'gap';
 *
 * When the parameter is absent the default passes the check, so the *true* branch runs
 * and reads the key that is not there. Every page had a version of it. Doing the
 * defaulting once, in one place, is what stops the fifth page repeating it.
 *
 * @param array<int,string> $allowed
 */
function query_choice(string $key, array $allowed, string $fallback): string
{
    $value = isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : $fallback;

    return in_array($value, $allowed, true) ? $value : $fallback;
}

/** HTML escaping, short enough to use everywhere it is needed. */
function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** A score, to the precision the product actually claims. */
function fmt_score(?float $value): string
{
    return $value === null ? '—' : number_format($value, 0);
}

/** A signed figure, where the sign is the point. */
function fmt_signed(?float $value, int $decimals = 0): string
{
    if ($value === null) {
        return '—';
    }
    // A true minus sign, not a hyphen: it aligns with the tabular figures either side
    // of it. Double-quoted, because the escape does nothing in a single-quoted string.
    return ($value > 0 ? '+' : ($value < 0 ? "\u{2212}" : '')) . number_format(abs($value), $decimals);
}

/**
 * A raw input in its native unit.
 *
 * Turnover is a small fraction, derivative share is a multiple and the fear and greed
 * index is a whole number. One format for all three would render two of them as zero.
 */
function fmt_native(?float $value, string $unit): string
{
    if ($value === null) {
        return 'no reading';
    }

    return match (true) {
        str_contains($unit, 'index')          => number_format($value, 0),
        // Dollar figures run to eleven digits. $76.7bn is readable; 76,702,040,900 is not.
        str_contains($unit, 'USD')            => fmt_usd_short($value),
        str_contains($unit, 'x spot')         => number_format($value, 2) . '×',
        // A funding rate is ~0.00004 per interval. Two decimals renders it as 0.00 —
        // an input that is doing real work in the score, displayed as nothing. Shown as
        // a percentage, signed, because the sign is which side is paying.
        str_contains($unit, 'funding interval') => ($value >= 0 ? '+' : '−') . number_format(abs($value) * 100, 4) . '%',
        str_contains($unit, '%')              => number_format($value, 2) . '%',
        str_contains($unit, 'share'), str_contains($unit, 'volume / market cap'),
        str_contains($unit, 'change'), str_contains($unit, 'HHI')
                                              => number_format($value, 4),
        str_contains($unit, 'ratio')          => number_format($value, 2),
        str_contains($unit, 'position')       => number_format($value, 0),
        default                               => number_format($value, 2),
    };
}

/** $76,702,040,900 as $76.7bn. Large money is read at a glance or not at all. */
function fmt_usd_short(float $value): string
{
    $abs = abs($value);
    $sign = $value < 0 ? '−' : '';

    return match (true) {
        $abs >= 1e12 => $sign . '$' . number_format($abs / 1e12, 2) . 'tn',
        $abs >= 1e9  => $sign . '$' . number_format($abs / 1e9, 1) . 'bn',
        $abs >= 1e6  => $sign . '$' . number_format($abs / 1e6, 1) . 'm',
        $abs >= 1e3  => $sign . '$' . number_format($abs / 1e3, 1) . 'k',
        default      => $sign . '$' . number_format($abs, 0),
    };
}

/** A UTC timestamp as the app writes them: short, and always marked UTC. */
function fmt_time(?string $utc, bool $withDate = true): string
{
    if ($utc === null) {
        return '—';
    }
    $ts = strtotime($utc . ' UTC');
    if ($ts === false) {
        return '—';
    }

    return gmdate($withDate ? 'j M, H:i' : 'H:i', $ts) . ' UTC';
}

/** "4 minutes ago" — a description of the recording, never of the market. */
function fmt_ago(?string $utc): string
{
    if ($utc === null) {
        return 'never';
    }
    $ts = strtotime($utc . ' UTC');
    if ($ts === false) {
        return 'never';
    }

    $seconds = max(0, time() - $ts);
    return match (true) {
        $seconds < 90      => 'just now',
        $seconds < 5400    => intdiv($seconds, 60) . ' min ago',
        $seconds < 172800  => intdiv($seconds, 3600) . ' hours ago',
        default            => intdiv($seconds, 86400) . ' days ago',
    };
}

/**
 * The line under an axis name on the chart, naming the inputs that feed it.
 *
 * Two things this has to get right, both of which it got wrong:
 *
 *  1. **The separator cannot be a comma.** Two of the input labels contain one —
 *     "Liquidations, 24h" and "Volume change, 24h" — so a comma-joined list reads as
 *     one more input than it has, and "24h" arrives looking like a measurement of its
 *     own. A middot cannot be confused for part of a label.
 *  2. **A truncated list has to say it is truncated.** The Money axis has five inputs
 *     and there is room for three, and naming three of five without a word about the
 *     other two states something false about the axis on the axis itself.
 *
 * @param array<int,array<string,mixed>> $inputs From `available_inputs()`.
 */
function axis_hint(array $inputs, int $show = 3): string
{
    $labels = array_map(static fn(array $i): string => strtolower((string) $i['label']), $inputs);
    if ($labels === []) {
        return '';
    }

    $rest = count($labels) - $show;
    if ($rest > 0) {
        $labels = array_slice($labels, 0, $show);
        $labels[] = '+' . $rest . ' more';
    }

    return implode(' · ', $labels);
}

/** The header claim, live from `raw_samples`. */
function recording_line(array $health): string
{
    if ($health['samples'] === 0) {
        return 'not recording yet';
    }

    $since = $health['first_sample'] !== null
        ? gmdate('j M', (int) strtotime($health['first_sample'] . ' UTC'))
        : '—';

    return sprintf('recording since %s, %s samples', $since, number_format($health['samples']));
}

/** @param array<string,mixed> $health */
function render_head(string $title, string $active, array $health): void
{
    $nav = [
        'market' => ['index.php', 'Market'],
        'assets' => ['assets.php', 'Assets'],
        'method' => ['method.php', 'Method'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — Divergence</title>
<meta name="description" content="The gap between what the crypto market is saying and what it has committed money to. A measurement, not a forecast.">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css">
<!-- Link previews. Absolute URLs, because a relative og:image is ignored by most
     scrapers — and a judge sharing the link is exactly when the preview matters. -->
<meta property="og:type" content="website">
<meta property="og:title" content="Divergence — <?= h($title) ?>">
<meta property="og:description" content="What the crypto market is saying, plotted against what it has committed money to. Two measurements CoinMarketCap publishes separately and never puts on the same axis.">
<meta property="og:image" content="https://coinmarketcap.geralexgr.com/assets/og.png">
<meta name="twitter:card" content="summary_large_image">
</head>
<body>
<div class="page">
  <header class="appbar">
    <div class="wordmark">Divergence<span class="status"><?= h(recording_line($health)) ?></span></div>
    <nav class="nav">
      <?php foreach ($nav as $key => [$href, $label]): ?>
        <?php if ($key === $active): ?><b><?= h($label) ?></b><?php else: ?><a href="<?= h($href) ?>"><?= h($label) ?></a><?php endif; ?>
      <?php endforeach; ?>
    </nav>
  </header>
<?php
}

function render_foot(): void
{
    ?>
  <footer class="pagefoot">
    <p>Every number on this site is a measurement of a condition that exists now, or existed at a
       recorded past moment. Nothing here is advice, a recommendation, or a prediction. Source data
       from the CoinMarketCap API; the <a href="method.php">method page</a> states exactly how each
       figure is derived.</p>
  </footer>
</div>
<script src="assets/app.js"></script>
</body>
</html>
<?php
}

/**
 * What every page shows when there is nothing recorded yet.
 *
 * This is a real state, not an error: the recorder runs before anything can be scored,
 * and a fresh deployment sits here until the first cron ticks land. Saying so is better
 * than an empty chart, which reads as a measurement of a market where nothing is
 * happening.
 */
function render_empty_state(string $reason, ?array $health = null): void
{
    ?>
  <section class="panel empty">
    <h1>Nothing to plot yet</h1>
    <p><?= h($reason) ?></p>
    <?php if ($health !== null && $health['samples'] > 0): ?>
      <p class="muted"><?= number_format($health['samples']) ?> raw samples recorded, latest
         <?= h(fmt_ago($health['latest_sample'])) ?>. Scores are written by
         <code>bin/score.php</code>, which runs on its own cron entry.</p>
    <?php else: ?>
      <p class="muted">The recorder writes first and everything else reads from what it stored.
         Once <code>poller/run.php</code> has landed a few samples and <code>bin/extract.php</code>
         and <code>bin/score.php</code> have run, this page fills in.</p>
    <?php endif; ?>
  </section>
<?php
}
