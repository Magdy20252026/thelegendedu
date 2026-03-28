(function () {
  function parseTarget(str) {
    str = (str || '').toString().trim();
    if (!str) return { n: 0, suffix: '', decimals: 0 };

    const s = str.replace(/\s+/g, '').replace(/,/g, '');

    let suffix = '';
    let core = s;

    const last = s.slice(-1).toLowerCase();
    if (last === 'k' || last === 'm' || last === 'b') {
      suffix = s.slice(-1); // keep original case if user typed K/M
      core = s.slice(0, -1);
    }

    const num = Number(core);
    if (!isFinite(num)) return { n: 0, suffix: '', decimals: 0 };

    const dot = core.indexOf('.');
    const decimals = dot >= 0 ? Math.min(2, core.length - dot - 1) : 0;

    return { n: num, suffix, decimals };
  }

  function formatNumber(val, decimals) {
    if (decimals > 0) return val.toFixed(decimals);
    return Math.round(val).toString();
  }

  function animateCounter(el, targetStr, durationMs) {
    const info = parseTarget(targetStr);

    if (!info.n || info.n <= 0) {
      el.textContent = targetStr || '0';
      return;
    }

    const start = 0;
    const end = info.n;
    const decimals = info.decimals;
    const suffix = info.suffix;

    const startTs = performance.now();
    const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);

    function tick(now) {
      const t = Math.min(1, (now - startTs) / durationMs);
      const eased = easeOutCubic(t);

      const current = start + (end - start) * eased;
      el.textContent = formatNumber(current, decimals) + suffix;

      if (t < 1) requestAnimationFrame(tick);
      else el.textContent = formatNumber(end, decimals) + suffix;
    }

    requestAnimationFrame(tick);
  }

  function runCounters(root) {
    const els = (root || document).querySelectorAll('.js-counter[data-target]');
    if (!els.length) return;

    els.forEach((el, i) => {
      const target = el.getAttribute('data-target') || '0';
      const delay = 120 * i; // stagger احترافي

      setTimeout(() => {
        animateCounter(el, target, 1100);
      }, delay);
    });
  }

  function init() {
    const section = document.querySelector('.hero-stats');
    if (!section) return;

    let done = false;

    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver((entries) => {
        entries.forEach((e) => {
          if (!done && e.isIntersecting) {
            done = true;
            runCounters(section);
            io.disconnect();
          }
        });
      }, { threshold: 0.35 });

      io.observe(section);
    } else {
      runCounters(section);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();