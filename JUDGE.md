# Verify this in five minutes

Every number this tool shows is a measurement you can check against CoinMarketCap yourself. This
page is the shortest path to doing that.

**Live:** https://coinmarketcap.geralexgr.com

---

## 1. Check a number against CoinMarketCap (60 seconds)

Open the site. In the right-hand **What went into it** panel, every input carries the endpoint it
came from and the minute it was sampled — for example:

```
Open interest        derivatives_pairs · 11:05 UTC        $90.6bn
```

Fetch the same thing yourself:

```bash
curl -s -H "X-CMC_PRO_API_KEY: $YOUR_KEY" \
  "https://pro-api.coinmarketcap.com/v5/cryptocurrency/derivatives/market-pairs/list/latest?crypto_symbol=BTC&limit=100" \
  | python3 -c "import json,sys; d=json.load(sys.stdin)['data']['market_pairs']; print(sum(p['exchange_reported_quotes'][0].get('open_interest',0) or 0 for p in d))"
```

The figures move between samples, so expect the site's value to match the sample minute it names
rather than the instant you run the command.

The same data as JSON, no browser needed:

```bash
curl -s https://coinmarketcap.geralexgr.com/api/market.php
```

That response carries the two scores, the raw inputs in their native units, the endpoint behind
each, the sample time, and the recording health — enough to check the arithmetic without opening
the site at all.

## 2. Check the arithmetic (60 seconds)

`api/market.php` gives you the inputs and the score. The method is
[docs/method.md](docs/method.md), and it is rendered live at
[/method.php](https://coinmarketcap.geralexgr.com/method.php) from the same declaration the scorer
runs from — `app/scoring/inputs.php`. The page cannot describe a method the code does not implement,
because there is one description and the code is it.

```
divergence = money − voice
```

Each input is scaled 0–100 against the reference range shown on the method page, then weighted.
Inputs with no data are dropped and the survivors renormalised; the score records how many it
actually used, and the app prints that.

## 3. Check that it is really recording (30 seconds)

The header says `recording since 13 Sep, N samples`. That is a live count from `raw_samples`, not a
written-down figure. Reload in ten minutes and it will be larger.

The trail on the quadrant is the part that matters: **CoinMarketCap has no historical endpoint for
sentiment or positioning.** Price history can be fetched retroactively; this cannot. Every point on
that line exists only because something was recording at the time.

Recording health — sample count, longest gap, failure rate, credits consumed — is at the bottom of
[/method.php](https://coinmarketcap.geralexgr.com/method.php), live from `fetch_log`.

## 4. Run the tests (60 seconds, no key needed)

```bash
git clone https://github.com/geralexgr/coinmarketcap-divergence.git
cd coinmarketcap-divergence
php tests/run.php
```

70 tests, no framework, no network, no database, no composer. They cover the parts that fail
*silently*: normalisation boundaries, a missing input being scored as zero, a percentile that peeks
at the future, and the readout copy being grepped for future-tense words.

## 5. Map the API surface without a key (60 seconds, no credits)

```bash
php app/bin/probe-paths.php
```

CoinMarketCap resolves a path *before* it validates the key, so an invalid key returns 401 on a real
path and 404 on an imaginary one. The whole surface can be mapped for free.

Watch the two control paths in the output. CMC answers an unknown path with **HTTP 200** and
`error_code: 500` — judging by HTTP status alone briefly recorded six non-existent endpoints as
working here, so the controls must read as absent on every run or the results mean nothing.

---

## What to look at if you only have one minute

The **quadrant trail** on the market view. Two measurements CoinMarketCap publishes on separate
pages and never puts on the same axis, plotted against each other, with the path the market actually
took between them.

## What this deliberately does not do

No predictions, no recommendations, no signals. Every output describes a condition measured at a
recorded moment. A quadrant is a label for where a point sits, not a rating.

That is a design constraint rather than a disclaimer: a measurement can be checked against
CoinMarketCap in thirty seconds, and a prediction cannot be checked at all. There is a test that
greps the generated copy for future-tense words.

## Where it is weakest, in our own words

We would rather you heard this from us than found it.

- **The Voice axis is one input, updated once a day.** Every trending, community and content
  endpoint answers 403 on this key — re-verified endpoint by endpoint. So Voice steps daily while
  Money moves every fifteen minutes, and the horizontal stretches in the trail are that, not a quiet
  market. [docs/limits.md](docs/limits.md)
- **The per-asset Voice proxy is price-derived**, because there is no per-asset attention data at
  all on this plan. It is the weakest number in the product and it is labelled as such wherever it
  appears. [D18](docs/decisions.md)
- **Open interest is BTC only.** The endpoint takes one symbol per call, so a hundred assets would
  be a hundred credits per sample. Liquidations do not have this limit — 100 assets for one credit.
- **The screener hides stablecoins by default.** High turnover with no narrative is what a
  stablecoin is, so ranking them by that gap measures a definition. They are one click away and the
  page says so. [D21](docs/decisions.md)
- **We were wrong in public once.** This repo spent two days documenting that the CoinMarketCap API
  has no derivatives endpoints, after probing 38 paths. It has them; they live under `/v5/` and our
  probe swept `/v1/`–`/v4/`. [D20](docs/decisions.md) records the correction, how it was found, and
  what changed so the same class of miss cannot recur.

---

| | |
|---|---|
| What it is and why | [README.md](README.md) |
| How every number is produced | [docs/method.md](docs/method.md) |
| What it cannot see | [docs/limits.md](docs/limits.md) |
| Why it was built this way | [docs/decisions.md](docs/decisions.md) — 20 decisions, with the reasoning |
| Where the API got in the way | [docs/api-friction.md](docs/api-friction.md) |
| Which endpoints respond, measured | [docs/endpoint-access.md](docs/endpoint-access.md) |
| Deploying it yourself | [DEPLOY.md](DEPLOY.md) |
