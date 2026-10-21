// Team page: fee check and the two small charts.
(function () {
  'use strict';

  var money = function (n) {
    var d = n < 100 ? 2 : 0;
    return '€' + n.toLocaleString('en-GB', { minimumFractionDigits: d, maximumFractionDigits: d });
  };

  // Fee check: ≈ €X per 1,000 match views = fee ÷ (season views ÷ 1,000), plus the same fee for a typical team in the tier.
  var form = document.getElementById('fee-form');
  if (form) {
    var input = document.getElementById('fee');
    var out = document.getElementById('fee-result');
    var compare = document.getElementById('fee-compare');
    var views = Number(form.dataset.seasonViews);
    var tierViews = Number(form.dataset.tierViews);
    var update = function () {
      var fee = Number(input.value);
      if (!(fee > 0 && views > 0)) {
        out.innerHTML = '<span>Enter a fee to see the cost per 1,000 match views</span>';
        compare.textContent = '';
        return;
      }
      out.innerHTML = '≈ ' + money(fee / (views / 1000)) + ' <span>per 1,000 match views</span>';
      compare.textContent = tierViews > 0
        ? 'A typical team at ' + form.dataset.tierLabel + ': ≈ ' + money(fee / (tierViews / 1000)) + ' per 1,000 at the same fee.'
        : '';
    };
    input.addEventListener('input', update);
    form.addEventListener('submit', function (e) { e.preventDefault(); update(); });
  }

  var dataEl = document.getElementById('team-data');
  if (!window.Chart || !dataEl) return;
  var data = JSON.parse(dataEl.textContent);

  var css = getComputedStyle(document.documentElement);
  var color = function (name) { return css.getPropertyValue(name).trim(); };
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var shortDate = function (iso) {
    return new Date(iso + 'T00:00:00Z').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', timeZone: 'UTC' });
  };

  Chart.defaults.color = color('--muted');
  Chart.defaults.borderColor = color('--grid');
  Chart.defaults.scale.border = { display: false };
  Chart.defaults.font.family = css.getPropertyValue('--font').trim() || 'system-ui';
  Chart.defaults.font.size = 12;
  // Change only the duration: replacing the whole animation object drops Chart.js's easing,
  // which left tooltips stuck almost invisible.
  if (reduceMotion) {
    Chart.defaults.animation = false;
  } else {
    Chart.defaults.animation.duration = 500;
  }
  Chart.defaults.plugins.legend.display = false;
  Chart.defaults.plugins.tooltip.backgroundColor = color('--raised');
  Chart.defaults.plugins.tooltip.titleColor = color('--text');
  Chart.defaults.plugins.tooltip.bodyColor = color('--text');
  Chart.defaults.plugins.tooltip.borderColor = color('--line');
  Chart.defaults.plugins.tooltip.borderWidth = 1;
  Chart.defaults.plugins.tooltip.padding = 10;
  Chart.defaults.maintainAspectRatio = false;

  var xAxis = { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 5, color: color('--faint') } };

  var viewers = document.getElementById('viewers-chart');
  if (viewers && data.viewers.length) {
    var fill = viewers.getContext('2d').createLinearGradient(0, 0, 0, viewers.clientHeight || 220);
    fill.addColorStop(0, color('--accent-soft'));
    fill.addColorStop(1, 'transparent');
    // Real dates on the x axis (ms since 1970), so quiet spells show as gaps rather than evenly spaced points.
    var GAP = 7 * 86400000;
    var points = data.viewers.map(function (d) { return { x: d.at || Date.parse(d.date + 'T12:00:00Z'), y: d.viewers, opp: d.opp, league: d.league }; });
    var first = points[0].x;
    var last = points[points.length - 1].x;
    var datasets = [{
      label: 'Avg viewers',
      data: points,
      segment: {
        // Long breaks between matches: dotted and faint, so the line doesn't suggest viewers in between.
        borderDash: function (s) { return s.p1.parsed.x - s.p0.parsed.x > GAP ? [1, 5] : undefined; },
        borderColor: function (s) { return s.p1.parsed.x - s.p0.parsed.x > GAP ? color('--accent-dim') : undefined; }
      },
      borderCapStyle: 'round',
      cubicInterpolationMode: 'monotone', // smooth, but never overshoots between same-day matches
      borderColor: color('--accent'),
      backgroundColor: fill,
      pointBackgroundColor: color('--accent'),
      fill: true,
      
      borderWidth: 2,
      pointRadius: 2,
      pointHoverRadius: 5
    }];
    if (data.tierAvg) {
      datasets.push({
        label: data.tierLabel,
        data: [{ x: first, y: data.tierAvg }, { x: last, y: data.tierAvg }],
        borderColor: color('--faint'),
        borderDash: [3, 4],
        borderWidth: 1,
        pointRadius: 0,
        pointHoverRadius: 0,
        fill: false
      });
    }
    new Chart(viewers, {
      type: 'line',
      data: { datasets: datasets },
      options: {
        interaction: { mode: 'nearest', axis: 'x', intersect: false },
        scales: {
          x: Object.assign({}, xAxis, {
            type: 'linear',
            min: first === last ? first - 86400000 : first,
            max: first === last ? last + 86400000 : last,
            ticks: Object.assign({}, xAxis.ticks, {
              callback: function (v) { return shortDate(new Date(v).toISOString().slice(0, 10)); }
            })
          }),
          y: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 4, color: color('--faint') } }
        },
        plugins: {
          tooltip: {
            filter: function (i) { return i.datasetIndex === 0; },
            callbacks: {
              title: function (items) { var d = new Date(items[0].parsed.x); return shortDate(d.toISOString().slice(0, 10)) + ', ' + d.toISOString().slice(11, 16) + ' UTC'; },
              label: function (c) { return ' ' + c.parsed.y.toLocaleString('en-GB') + ' avg viewers'; },
              afterLabel: function (c) {
                var p = c.raw;
                return [p.opp ? ' vs ' + p.opp : '', p.league || ''].filter(Boolean).join(' · ');
              },
              footer: function () { return data.tierAvg ? data.tierLabel + ': ' + data.tierAvg.toLocaleString('en-GB') : ''; }
            }
          }
        }
      }
    });
  }

  var winRate = document.getElementById('winrate-chart');
  if (winRate && data.winRate.length) {
    new Chart(winRate, {
      type: 'bar',
      data: {
        labels: data.winRate.map(function (d) { return shortDate(d.date); }),
        datasets: [{
          label: 'Win rate (last 10)',
          data: data.winRate.map(function (d) { return Math.round(d.win_rate * 100); }),
          backgroundColor: data.winRate.map(function (d) { return d.win_rate >= 0.5 ? color('--accent') : color('--accent-dim'); }),
          borderRadius: 3,
          maxBarThickness: 12
        }, {
          type: 'line',
          label: '50%',
          data: data.winRate.map(function () { return 50; }),
          borderColor: color('--faint'),
          borderDash: [3, 4],
          borderWidth: 1,
          pointRadius: 0,
          pointHoverRadius: 0
        }]
      },
      options: {
        scales: { x: xAxis, y: { min: 0, max: 100, ticks: { stepSize: 50, color: color('--faint'), callback: function (v) { return v + '%'; } } } },
        plugins: { tooltip: { filter: function (i) { return i.datasetIndex === 0; }, callbacks: { label: function (c) { return ' ' + c.parsed.y + '% of last 10 won'; } } } }
      }
    });
  }
  var load = document.getElementById('load-chart');
  if (load && data.load.length) {
    new Chart(load, {
      type: 'bar',
      data: {
        labels: data.load.map(function (w) { return shortDate(w.week); }),
        datasets: [{
          label: 'Maps',
          data: data.load.map(function (w) { return w.maps; }),
          backgroundColor: color('--mark'),
          borderRadius: 3,
          maxBarThickness: 18
        }]
      },
      options: {
        scales: { x: xAxis, y: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 4 } } },
        plugins: { tooltip: { callbacks: {
          title: function (items) { return 'Week of ' + items[0].label; },
          label: function (c) { var w = data.load[c.dataIndex]; return ' ' + w.maps + ' maps · ' + w.matches + ' matches · ' + w.won + ' won'; }
        } } }
      }
    });
  }
})();
