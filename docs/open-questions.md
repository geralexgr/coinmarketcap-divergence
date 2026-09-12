# Open questions

These block real design decisions. Check them by making actual calls with the real key from the
real host, not by reading documentation. Answers go into `endpoint-access.md`; decisions that follow
from them go into `decisions.md`.

Status: all open as of 12 September 2026.

---

## 1. Which endpoints respond on Startup tier — ❓ open

The plan gives 23 latest-data endpoints against Standard's 35. Some of what this design assumes may
403.

**How to settle it:** endpoint by endpoint, from the host. Write results into `endpoint-access.md`.

**What it changes:** potentially the entire input list for both axes.

---

## 2. Derivatives access — ❓ open · highest priority

Funding rates, open interest and liquidations are the best inputs to the Money axis, and the Money
axis is the half of the product that CoinMarketCap's own site cannot show alongside sentiment.

**How to settle it:** call the derivatives endpoints first, before anything else gets written.

**If unavailable:** fall back to volume concentration and exchange reserve movement. Weaker, but
survivable, and the method page states the substitution plainly. Record the decision before writing
a fetcher against the fallback.

---

## 3. Fear and greed via API — ❓ open

It is a chart page on the CoinMarketCap site. Whether it is an endpoint at all is unconfirmed.

**If not available:** social / community volume alone can carry the Voice axis. The axis label and
the method page change accordingly; the product still works.

---

## 4. cPanel cron minimum interval — ❓ open

Most shared hosts allow every minute; some enforce a 5 or 15 minute floor.

**What it changes:** the market-wide cadence, and therefore the density of the trail on the main
chart. A 15-minute floor is still fine. An hourly floor would need a rethink.

---

## 5. Outbound HTTPS from PHP CLI — ❓ open

Confirm from the server that PHP CLI can reach `pro-api.coinmarketcap.com`. Some shared hosts
firewall outbound connections from CLI differently than from the web SAPI, and this would be a bad
thing to discover after building the poller.

---

## 6. Batching support per endpoint — ❓ open

Whether the per-asset endpoints accept comma-separated id lists, and the maximum ids per call.

**What it changes:** whether 100 assets costs 1 request or 100, which determines whether the
30 requests/minute limit constrains the asset universe at all.

---

## 7. Normalisation window length — ❓ open, not blocking

Once percentile ranking switches on, how long is the trailing window? 30 days is the instinct, but
the hackathon only runs three weeks, so the window can never exceed the recording length. Decide
once there is data to look at.

---

## Answered

Nothing yet. When an item is settled, move it here with the answer and the date, and add the
resulting decision to `decisions.md`.
