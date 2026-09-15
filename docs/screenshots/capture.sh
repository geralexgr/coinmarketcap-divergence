#!/usr/bin/env bash
#
# Reshoot the README screenshots from the live deployment.
#
# The screenshots go stale silently — the README spent two days apologising for a hero
# image that the live site had already outgrown — so reshooting is one command rather
# than a remembered ritual.
#
#   ./docs/screenshots/capture.sh                       # the live deployment
#   ./docs/screenshots/capture.sh http://localhost:8080 # a local run
#
# 1104 is the width the page is designed for: the container is 1080 plus its gutters, so
# nothing in the frame is wasted on empty background once GitHub scales it into the README
# column. Device scale factor 2 because these are read on retina displays.
set -euo pipefail

BASE="${1:-https://coinmarketcap.geralexgr.com}"
OUT="$(cd "$(dirname "$0")" && pwd)"
CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"

[ -x "$CHROME" ] || { echo "No Chrome at: $CHROME  (set CHROME=/path/to/chrome)" >&2; exit 1; }

shoot() { # page  file  height
  echo "  $2.png  <-  $BASE/$1"
  "$CHROME" --headless --disable-gpu --hide-scrollbars \
    --window-size="1104,$3" --force-device-scale-factor=2 \
    --virtual-time-budget=6000 \
    --screenshot="$OUT/$2.png" "$BASE/$1" 2>/dev/null
}

echo "Capturing from $BASE"
shoot "index.php"                 market   830
shoot "assets.php"                screener 1100
shoot "method.php"                method   1100
shoot "assets.php?asset=BTC"      asset    830

echo "Done. Check them before committing — a screenshot of a broken page still looks like a screenshot."
