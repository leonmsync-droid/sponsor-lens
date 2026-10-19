// Team page: fee check and the two small charts.
(function () {
  'use strict';

  // Fee check: ≈ €X per 1,000 match views = fee ÷ (season views ÷ 1,000)
  var form = document.getElementById('fee-form');
  if (form) {
    var input = document.getElementById('fee');
    var out = document.getElementById('fee-result');
    var views = Number(form.dataset.seasonViews);
    var money = function (n) {
      return '€' + n.toLocaleString('en-GB', { minimumFractionDigits: n < 100 ? 2 : 0, maximumFractionDigits: n < 100 ? 2 : 0 });
    };
    var update = function () {
      var fee = Number(input.value);
      out.textContent = fee > 0 && views > 0 ? '≈ ' + money(fee / (views / 1000)) + ' per 1,000 match views' : '';
    };
    input.addEventListener('input', update);
    form.addEventListener('submit', function (e) { e.preventDefault(); update(); });
  }

  if (!window.Chart || !window.teamData) return;

  var css = getComputedStyle(document.documentElement);
  var color = function (name) { return css.getPropertyValue(name).trim(); };
  Chart.defaults.color = color('--muted');
  Chart.defaults.borderColor = color('--grid');
  Chart.defaults.font.family = css.getPropertyValue('font-family');

  var base = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { x: { grid: { display: false } } }
  };

  var viewers = document.getElementById('viewers-chart');
  if (viewers && teamData.viewers.length) {
    new Chart(viewers, {
      type: 'line',
      data: {
        labels: teamData.viewers.map(function (d) { return d.date; }),
        datasets: [{ data: teamData.viewers.map(function (d) { return d.viewers; }), borderColor: color('--accent'), backgroundColor: color('--accent'), tension: 0.25, pointRadius: 3 }]
      },
      options: Object.assign({}, base, {
        scales: { x: base.scales.x, y: { beginAtZero: true, ticks: { precision: 0 } } },
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return c.parsed.y.toLocaleString('en-GB') + ' avg viewers'; } } } }
      })
    });
  }

  var winRate = document.getElementById('winrate-chart');
  if (winRate && teamData.winRate.length) {
    new Chart(winRate, {
      type: 'bar',
      data: {
        labels: teamData.winRate.map(function (d) { return d.date; }),
        datasets: [{ data: teamData.winRate.map(function (d) { return Math.round(d.win_rate * 100); }), backgroundColor: color('--accent'), borderRadius: 2 }]
      },
      options: Object.assign({}, base, {
        scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 6 } }, y: { min: 0, max: 100, ticks: { callback: function (v) { return v + '%'; } } } },
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return c.parsed.y + '% of last 10 won'; } } } }
      })
    });
  }
})();
