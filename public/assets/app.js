/*
 * The quadrant plot.
 *
 * Hand-drawn SVG rather than a charting library, for three reasons: the whole plot is
 * about 120 lines, a library would be the only third-party dependency in the project,
 * and none of them break a line at a recording gap without being fought about it —
 * which is the one behaviour here that is not negotiable.
 *
 * Everything it draws comes from `data-series` and `data-gaps` on the container, which
 * the server rendered from the same query the JSON API answers. There is no fetch on
 * page load and nothing here writes anything anywhere.
 */

(function () {
  'use strict';

  var NS = 'http://www.w3.org/2000/svg';

  // Plot geometry, in the SVG's own coordinate space. The viewBox scales it to whatever
  // width the column ends up being.
  var W = 440, H = 330;
  var L = 58, T = 18, R = 402, B = 286;

  function el(name, attrs, text) {
    var node = document.createElementNS(NS, name);
    for (var key in attrs) {
      if (Object.prototype.hasOwnProperty.call(attrs, key)) {
        node.setAttribute(key, String(attrs[key]));
      }
    }
    if (text !== undefined) { node.textContent = text; }
    return node;
  }

  /* Score space (0-100, origin bottom left) to SVG space (origin top left). */
  function px(score) { return L + (score / 100) * (R - L); }
  function py(score) { return B - (score / 100) * (B - T); }

  function parse(node, attribute) {
    try {
      return JSON.parse(node.getAttribute(attribute) || '[]');
    } catch (e) {
      return [];
    }
  }

  function utcLabel(sampledAt) {
    // MySQL DATETIME(3), which Safari will not parse without the T and the Z.
    var date = new Date(String(sampledAt).replace(' ', 'T') + 'Z');
    if (isNaN(date.getTime())) { return String(sampledAt); }
    return date.toLocaleString('en-GB', {
      day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
      timeZone: 'UTC', hour12: false
    }) + ' UTC';
  }

  function quadrantAt(voice, money) {
    if (voice >= 50) { return money >= 50 ? 'loud' : 'chatter'; }
    return money >= 50 ? 'quiet' : 'apathy';
  }

  function draw(container) {
    var series = parse(container, 'data-series');
    var gaps = parse(container, 'data-gaps');
    if (!series.length) { return; }

    var last = series[series.length - 1];
    var live = quadrantAt(last.voice, last.money);

    var svg = el('svg', {
      viewBox: '0 0 ' + W + ' ' + H,
      role: 'img',
      'aria-label': 'Voice plotted against money. The current reading is voice ' +
        Math.round(last.voice) + ', money ' + Math.round(last.money) + '.'
    });

    /* The tint marks where the point currently is, so the reading is visible before any
     * label is read.
     *
     * The geometry, which was wrong here until 13 Sep 2026 and is worth spelling out:
     * y is Voice and grows UPWARD (py inverts it), x is Money and grows RIGHT. So the
     * top half is high Voice and the right half is high Money, and the two off-diagonal
     * quadrants are the easy ones to swap —
     *
     *   top-left     high Voice, low  Money  = chatter without conviction
     *   top-right    high Voice, high Money  = loud and leveraged
     *   bottom-left  low  Voice, low  Money  = apathy
     *   bottom-right low  Voice, high Money  = quiet, but leveraged
     *
     * which is exactly the table in docs/method.md and exactly what quadrant_of() in
     * app/scoring/score.php returns. */
    var tint = {
      chatter: { x: L, y: T },
      loud:    { x: (L + R) / 2, y: T },
      apathy:  { x: L, y: (T + B) / 2 },
      quiet:   { x: (L + R) / 2, y: (T + B) / 2 }
    }[live];
    svg.appendChild(el('rect', {
      x: tint.x, y: tint.y, width: (R - L) / 2, height: (B - T) / 2, class: 'qfill'
    }));

    svg.appendChild(el('rect', { x: L, y: T, width: R - L, height: B - T, class: 'qline' }));
    svg.appendChild(el('line', { x1: (L + R) / 2, y1: T, x2: (L + R) / 2, y2: B, class: 'qdash' }));
    svg.appendChild(el('line', { x1: L, y1: (T + B) / 2, x2: R, y2: (T + B) / 2, class: 'qdash' }));

    [
      // All four sit just inside the TOP of their own box. The lower two used to sit on
      // the bottom edge, which is exactly where a low-Voice reading plots — the current
      // point and its "Now" label landed on top of the quadrant name whenever the market
      // was quiet, which is half the time.
      ['Chatter without conviction', L + 10,           T + 16,               'chatter'],
      ['Loud and leveraged',         (L + R) / 2 + 10, T + 16,               'loud'],
      ['Apathy',                     L + 10,           (T + B) / 2 + 16,     'apathy'],
      ['Quiet, but leveraged',       (L + R) / 2 + 10, (T + B) / 2 + 16,     'quiet']
    ].forEach(function (q) {
      var lx = q[1], anchor = 'start';
      /* Move the label to the far side of its own box if the current point is sitting on
       * top of it. Only the live quadrant can collide — it is the only one holding the
       * pin — and at the extremes (very high Voice with very low Money, say) the point
       * lands within a few pixels of the text. Flipping to the other end of the same
       * quadrant keeps the label in the right box and out of the way. */
      if (q[3] === live) {
        var near = Math.abs(px(last.money) - lx) < 90 && Math.abs(py(last.voice) - q[2]) < 26;
        if (near) {
          lx = q[1] + (R - L) / 2 - 20;
          anchor = 'end';
        }
      }
      svg.appendChild(el('text', {
        x: lx, y: q[2], class: 'qname' + (q[3] === live ? ' live' : ''), 'text-anchor': anchor
      }, q[0]));
    });

    [0, 50, 100].forEach(function (v) {
      svg.appendChild(el('text', { x: L - 6, y: py(v) + 3, class: 'axtick', 'text-anchor': 'end' }, String(v)));
      svg.appendChild(el('text', { x: px(v), y: B + 12, class: 'axtick', 'text-anchor': 'middle' }, String(v)));
    });

    /* The trail, split at every recording gap.
     *
     * A gap is a window the poller missed, and drawing through one would show a path the
     * market is not known to have taken. So the line breaks and the points either side
     * stay: the reader sees both that there is history and that some of it is missing. */
    var breakAfter = {};
    gaps.forEach(function (gap) { breakAfter[gap.after] = true; });

    var run = [];
    var runs = [];
    series.forEach(function (point) {
      run.push(point);
      if (breakAfter[point.sampled_at]) { runs.push(run); run = []; }
    });
    if (run.length) { runs.push(run); }

    runs.forEach(function (segment) {
      if (segment.length < 2) { return; }
      var d = segment.map(function (p, i) {
        return (i === 0 ? 'M ' : 'L ') + px(p.money).toFixed(1) + ' ' + py(p.voice).toFixed(1);
      }).join(' ');
      svg.appendChild(el('path', { d: d, class: 'trail' }));
    });

    // Thinned so a long window does not become a solid band of dots. The line already
    // carries the path; the dots only need to show that it is made of samples.
    var step = Math.max(1, Math.floor(series.length / 60));
    series.forEach(function (point, i) {
      if (i === series.length - 1 || i % step !== 0) { return; }
      var dot = el('circle', { cx: px(point.money), cy: py(point.voice), r: 2.4, class: 'tdot' });
      dot.appendChild(el('title', {}, utcLabel(point.sampled_at) +
        ' — voice ' + Math.round(point.voice) + ', money ' + Math.round(point.money)));
      svg.appendChild(dot);
    });

    var cx = px(last.money), cy = py(last.voice);
    svg.appendChild(el('circle', { cx: cx, cy: cy, r: 14, class: 'pinring' }));
    svg.appendChild(el('circle', { cx: cx, cy: cy, r: 5.5, class: 'pin' }));

    // The label flips to the other side near the right edge so it never runs off, and
    // sits above the point in the lower half of the plot, where the quadrant names are
    // printed along the bottom and the two would otherwise overlap.
    var labelRight = cx < R - 90;
    var lx = labelRight ? cx + 20 : cx - 20;
    var anchor = labelRight ? 'start' : 'end';
    var low = last.voice < 22;
    var ly = low ? cy - 36 : cy - 3;
    svg.appendChild(el('text', { x: lx, y: ly, class: 'pinlab', 'text-anchor': anchor }, 'Now'));
    svg.appendChild(el('text', { x: lx, y: ly + 12, class: 'pintime', 'text-anchor': anchor }, utcLabel(last.sampled_at)));

    if (series.length > 1) {
      var first = series[0];
      svg.appendChild(el('text', {
        x: px(first.money), y: py(first.voice) + 14, class: 'pintime', 'text-anchor': 'middle'
      }, utcLabel(first.sampled_at)));
    }

    /* The axis hints name the inputs actually feeding each axis, and those differ
     * between the market chart and an asset chart — per asset there is no fear and
     * greed and no exchange reserve. The page supplies them, because hardcoding the
     * market's inputs here labelled the asset chart with numbers it does not contain. */
    var moneyHint = container.getAttribute('data-money-hint') || '';
    var voiceHint = container.getAttribute('data-voice-hint') || '';

    svg.appendChild(el('text', { x: (L + R) / 2, y: B + 30, class: 'axname m', 'text-anchor': 'middle' }, 'Money committed'));
    if (moneyHint) {
      svg.appendChild(el('text', { x: (L + R) / 2, y: B + 41, class: 'axhint', 'text-anchor': 'middle' }, moneyHint));
    }

    svg.appendChild(el('text', { x: -(T + B) / 2, y: 20, class: 'axname v', 'text-anchor': 'middle', transform: 'rotate(-90)' }, 'Voice'));
    if (voiceHint) {
      svg.appendChild(el('text', { x: -(T + B) / 2, y: 31, class: 'axhint', 'text-anchor': 'middle', transform: 'rotate(-90)' }, voiceHint));
    }

    container.innerHTML = '';
    container.appendChild(svg);
  }

  function init() {
    var nodes = document.querySelectorAll('.quadrant[data-series]');
    for (var i = 0; i < nodes.length; i++) { draw(nodes[i]); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
