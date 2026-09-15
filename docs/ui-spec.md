# UI spec

Built in `public/`. Original mockup: [`mockups/dashboard-mockup.html`](mockups/dashboard-mockup.html).

Three screens and four JSON endpoints. The first screen is the product; the rest exist to make it
credible. The "lead and lag" screen this spec originally described is **not built** — see the note
at the bottom.

---

## Global

**Header** carries the recording status: `recording since 9 Sep, 4,312 samples`. This is not
decoration — it is the claim the whole product rests on, and it is a live count from
`raw_samples`.

**Nav:** Market · Assets · Method.

**Every number** links to its provenance: the logical endpoint and the minute it was sampled.

**Language rule:** every caption describes a condition. No sentence anywhere on any screen points at
the future. Copy that fails this test is a bug, not a style preference.

---

## 1. Market

The demo screen.

**Left — the quadrant.** Voice on the vertical axis, Money on the horizontal, each 0–100, midlines
at 50. The four quadrants are labelled in place: Quiet but leveraged · Loud and leveraged · Apathy ·
Chatter without conviction. The active quadrant is tinted.

One point per sample over the selected window, joined into a trail, with the current point marked
and time-stamped. The trail is the moment that makes the product obvious in three seconds, and it
only exists because of the recorder.

Window selector: 24h · 7d · 30d · All.

Gaps in recording break the trail. No interpolation. The break is detected from the data — any
interval more than 2.5x the median — rather than from the cron schedule, because the schedule is an
intention and the samples are what happened.

The chart is hand-drawn SVG, not a charting library: it is about 120 lines, a library would be the
only third-party dependency in the project, and none of them break a line at a gap without being
fought about it.

**Right — the readout.**
- Divergence, large, as a magnitude out of 100, with one sentence stating the direction — money ahead
  of narrative, or narrative ahead of money.
- Voice and Money side by side, each with its week-over-week change.
- "What went into it": each raw input at its current value, in its native unit, labelled by axis
  colour, **with the endpoint it came from and the minute it was sampled underneath it**. This is the
  row a judge checks against CoinMarketCap, so provenance is part of the layout rather than a tooltip.
- A line stating how many inputs each axis actually used. On the Basic plan Voice uses one of three,
  and the screen says so next to the chart rather than letting a stepped line read as a flat market.

**Below — the per-asset table.** Symbol, Voice, Money, Gap and the quadrant reading, sorted by gap
magnitude, linking through to the full screener. The sparkline in the mockup is not built: it needs
a query per row and earns less than it costs.

Footer note, verbatim from the mockup: every number is a measurement of a condition that exists right
now; nothing on this screen predicts anything.

---

## 2. Assets

The per-asset table, full length, with the top ~100.

- Sortable on every column
- Filter by quadrant
- Click through to a single-asset view: that asset's own quadrant trail and its recorded quadrant
  crossings

Per-asset scores rank each asset against **the rest of the universe at the same instant**, not
against its own past (D17). The page says so and links to the method page, because it is a different
measurement from the market chart and mixing the two would mislead.

---

## 3. Method

The credibility screen, and the reason the tool is not a black box.

Rendered directly from `scoring/inputs.php` — the same declaration the scorer runs from — so the
page cannot describe a method the code does not implement.

- What the API plan permits, **first**, before the method itself. Ten of twenty endpoints answer
  403 and a method page that buried that would be worth less than none.
- The two axes and every input feeding each, including the forbidden ones, marked unavailable
- The current weights, ranges and the version number
- All three normalisation bases, the basis each axis is on right now, and **the switchover date of
  whichever axis is still on the fixed basis** — computed from the earliest reading that axis's own
  inputs have, not from the deployment's first sample, because the switchover is per axis (D22)
- Which CoinMarketCap endpoints are used, with live call and credit counts from `fetch_log`
- Recording health: samples, longest gap, failure rate, credits — live
- A plain statement that the tool gives no advice and makes no predictions

---

## Not built: lead and lag

The original spec had a fourth screen asking which axis moved first when the two diverged in the
recorded past.

It is not built, and the reason is the honest one: with the Voice axis at daily resolution (D16), a
lead/lag measurement between a daily series and a ten-minute series would be an artefact of the
sampling rates rather than a fact about the market. It would look like a finding and be nothing of
the kind. It becomes worth building the day the Voice axis has more than one input.

---

## JSON endpoints

The same data the pages render, read-only, under `public/api/`:

| Endpoint | Returns |
|---|---|
| `api/market.php` | current reading, with the raw inputs and their provenance |
| `api/series.php?scope=&window=` | the trail, with gaps as an explicit list |
| `api/assets.php?sort=&quadrant=` | the screener |
| `api/method.php` | the method declaration |

---

## Visual language

Taken from the mockup and kept — it reads as an instrument rather than a dashboard. The tokens are
CSS custom properties in `public/assets/app.css`.

| Token | Value | Use |
|---|---|---|
| field | `#EEF1F0` | page background |
| surface | `#FFFFFF` | panels |
| ink | `#1B2227` | primary text |
| graphite | `#6B7780` | secondary text |
| hairline | `#D3D9D7` | borders |
| voice | `#3A6EA5` | everything on the Voice axis |
| money | `#A34428` | everything on the Money axis |

Tabular numerals throughout. Thin weights for large figures. Hairline rules instead of boxes and
shadows. No gradients, no glow, no crypto-dashboard neon.

The two axis colours are load-bearing: once a reader learns blue = voice and rust = money, every
chart, table cell and input row is readable without a legend.
