# Endpoint access

**Nothing in this file is verified yet.** Filling it in is the first task of day 1, and it is done
by making real calls with the real key **from the host**, not by reading the docs and not from a
laptop.

Plan tier: Startup — 23 latest-data endpoints, against Standard's 35. Some of what the design
assumes may 403.

## How to fill this in

For each endpoint: make the call, record the HTTP status, whether the field we need is actually in
the payload, and the credit cost from the response. Paste one real (trimmed) response into the
Payload notes column or alongside it. Replace every ❓.

Status key: ✅ works and has what we need · ⚠️ responds but the field is missing or unusable ·
❌ 403 / not on tier · ❓ not yet tested

## Money axis — test these first

The Money axis is the differentiator and the fallback is materially weaker, so this block is the
highest-priority check of the whole project.

| Endpoint | Field needed | Status | Credits | Notes |
|---|---|---|---|---|
| derivatives — funding rate | per-exchange or aggregate funding | ❓ | ❓ | |
| derivatives — open interest | OI level, per asset | ❓ | ❓ | |
| derivatives — liquidations | 24h liquidation total | ❓ | ❓ | |
| exchange listings / market pairs | volume concentration (fallback) | ❓ | ❓ | fallback input |
| exchange assets / reserves | reserve movement (fallback) | ❓ | ❓ | fallback input |

**Decision gate:** if the first three are ❌, record the fallback decision in `decisions.md` before
any fetcher is written.

## Voice axis

| Endpoint | Field needed | Status | Credits | Notes |
|---|---|---|---|---|
| fear and greed | current index value | ❓ | ❓ | may be a site chart only, not an API |
| community / content — latest posts | post or comment counts per asset | ❓ | ❓ | |
| community trending / topics | trending asset list | ❓ | ❓ | |
| trending — most visited / gainers | attention proxy | ❓ | ❓ | |

## Universe and support

| Endpoint | Field needed | Status | Credits | Notes |
|---|---|---|---|---|
| cryptocurrency listings latest | top 100 by market cap, ids + symbols | ❓ | ❓ | assumed available |
| cryptocurrency quotes latest | volume, market cap per asset | ❓ | ❓ | batched by id list |
| key info / usage | credit balance remaining | ❓ | ❓ | useful for the budget panel |

## Host checks

| Check | Result |
|---|---|
| Outbound HTTPS from PHP CLI to `pro-api.coinmarketcap.com` | ❓ |
| PHP CLI binary path and version | ❓ |
| cPanel cron minimum interval | ❓ |
| MySQL JSON column support (5.7+) | ❓ |
| Writable log dir outside webroot | ❓ |

## Batching notes

Which endpoints accept a comma-separated id list, and the maximum ids per call — this determines
whether 100 assets costs 1 call or 100, and therefore whether the 30/min limit is a problem at all.

| Endpoint | Accepts id list | Max ids/call |
|---|---|---|
| quotes latest | ❓ | ❓ |
| derivatives (per asset) | ❓ | ❓ |
| community (per asset) | ❓ | ❓ |
