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
  Chart.defaults.animation = reduceMotion ? false : { duration: 500 };
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
    var datasets = [{
      label: 'Avg viewers',
      data: data.viewers.map(function (d) { return d.viewers; }),
      borderColor: color('--accent'),
      backgroundColor: fill,
      pointBackgroundColor: color('--accent'),
      fill: true,
      tension: 0.35,
      borderWidth: 2,
      pointRadius: 2.5,
      pointHoverRadius: 5
    }];
    if (data.tierAvg) {
      datasets.push({
        label: data.tierLabel,
        data: data.viewers.map(function () { return data.tierAvg; }),
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
      data: { labels: data.viewers.map(function (d) { return shortDate(d.date); }), datasets: datasets },
      options: {
        interaction: { mode: 'index', intersect: false },
        scales: { x: xAxis, y: { beginAtZero: true, ticks: { precision: 0, maxTicksLimit: 4, color: color('--faint') } } },
        plugins: { tooltip: { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + c.parsed.y.toLocaleString('en-GB'); } } } }
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
