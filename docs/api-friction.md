# Where the API got in the way

The submission asks for this note. It is written from evidence rather than recollection —
`fetch_log` records every attempt, status, and error, so this file is a summary of queryable facts.

**Nothing recorded yet.** Fill in as it happens, with dates. Do not save it up for the last day;
the details are what make it worth reading and they are the first thing to be forgotten.

## Template for an entry

### [Date] — short title
**What we wanted:** the measurement we were trying to take.
**What happened:** the actual API behaviour — status codes, payload shape, missing field.
**Evidence:** the `fetch_log` query or sample ids that show it.
**What we did instead:** the workaround, and what it cost in accuracy or coverage.

---

## Expected entries, based on what is already known

These are anticipated rather than observed. Confirm or delete each one.

- **No historical endpoints for sentiment or positioning.** The single largest constraint on the
  product, and the reason the recorder exists at all. Price history is retroactively available;
  social volume, funding and open interest are not.
- **Tier gaps.** Startup gives 23 latest-data endpoints against Standard's 35. Which specific ones
  were missing goes here once `endpoint-access.md` is filled in.
- **Fear and greed.** Published as a chart on the site; whether it is reachable via API is
  unconfirmed. If it is not, that is a notable asymmetry worth describing.
- **Rate limit vs breadth.** 30 requests/minute against a desire to track the top 100 assets. What
  batching support actually exists decided the universe size.
- **Credit accounting.** Whether credits per call are visible in the response, and what that made
  possible or impossible to budget.

## Running notes

Append as you go, dated, even when it is small.
