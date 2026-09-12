# UI spec

Mockup: [`mockups/dashboard-mockup.html`](mockups/dashboard-mockup.html) — open it in a browser.
Rendered: [`mockups/ui-market-view.png`](mockups/ui-market-view.png).

Four screens. The first one is the product; the other three exist to make it credible.

---

## Global

**Header** carries the recording status: `recording since 9 Sep, 4,312 samples`. This is not
decoration — it is the claim the whole product rests on, and it is a live count from
`raw_samples`.

**Nav:** Market · Assets · Lead and lag · Method.

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

Window selector: 24h · 7d · all.

Gaps in recording break the trail. No interpolation.

**Right — the readout.**
- Divergence, large, as a magnitude out of 100, with one sentence stating the direction — money ahead
  of narrative, or narrative ahead of money.
- Voice and Money side by side, each with its week-over-week change.
- "What went into it": each raw input at its current value, in its native unit, labelled by axis
  colour. This is the row a judge checks against CoinMarketCap.

**Below — the per-asset table.** Symbol, Voice, Money, Gap, a 7-day sparkline, and the quadrant
reading. Sorted by gap magnitude by default. This is the screener.

Footer note, verbatim from the mockup: every number is a measurement of a condition that exists right
now; nothing on this screen predicts anything.

---

## 2. Assets

The per-asset table, full length, with the top ~100.

- Sortable on every column
- Filter by quadrant
- Click through to a single-asset view: that asset's own quadrant trail, its inputs, and its
  sampling history

---

## 3. Lead and lag

Only built if the recorded history is long enough to say something honest — with three weeks of data
this may end up as a single modest chart, and that is an acceptable outcome.

The measured question: when the two scores have moved apart in the recorded past, which one moved
first? Stated as a description of recorded history, with the sample count it is based on, and no
claim that it generalises.

If the history cannot support it, the screen says so rather than showing a weak number confidently.

---

## 4. Method

The credibility screen, and the reason the tool is not a black box.

- The two axes and every input feeding each
- The current weights, and the version number
- The normalisation basis, both ranges, and **the switchover date** with its reason
- Which CoinMarketCap endpoints are used, with a real request/response example
- Recording health: samples, longest gap, failure rate — from `fetch_log`
- A plain statement that the tool gives no advice and makes no predictions

---

## Visual language

Taken from the mockup and worth keeping — it reads as an instrument rather than a dashboard.

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
