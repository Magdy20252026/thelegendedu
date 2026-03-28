(function () {
  function closest(el, sel) {
    while (el && el.nodeType === 1) {
      if (el.matches(sel)) return el;
      el = el.parentElement;
    }
    return null;
  }

  function init() {
    // all grade cards
    const cards = document.querySelectorAll('.grade-cardx');
    if (!cards.length) return;

    cards.forEach((card) => {
      const link = card.querySelector('.grade-cardx__mediaLink');
      if (!link) return;

      let armed = false; // first tap => animate only, second tap => navigate
      let timer = null;

      function animate() {
        card.classList.add('is-tap');
        window.clearTimeout(timer);
        timer = window.setTimeout(() => {
          card.classList.remove('is-tap');
        }, 600);
      }

      // Touch devices + devtools emulation
      link.addEventListener('click', function (e) {
        // If user clicked with mouse on desktop, allow normal click.
        // We only intercept when it's likely a touch or when we want the first-tap effect.
        const isProbablyTouch = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);

        if (!isProbablyTouch) return;

        if (!armed) {
          // first tap: show animation and prevent navigation
          e.preventDefault();
          armed = true;
          animate();

          // disarm after short time
          window.setTimeout(() => { armed = false; }, 900);
        } else {
          // second tap: allow navigation
          armed = false;
        }
      }, { passive: false });

      // Also add animation when touching anywhere inside card (optional)
      card.addEventListener('touchstart', function (e) {
        const t = e.target;
        if (!t) return;
        const inside = closest(t, '.grade-cardx');
        if (!inside) return;
        animate();
      }, { passive: true });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();