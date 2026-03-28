(function () {
  const storageKey = 'site_theme';
  const root = document.documentElement;

  function apply(mode) {
    mode = (mode === 'dark') ? 'dark' : 'light';
    root.setAttribute('data-theme', mode);
    localStorage.setItem(storageKey, mode);

    document.querySelectorAll('[data-theme-switch] [data-theme]').forEach((b) => {
      b.classList.toggle('is-active', b.getAttribute('data-theme') === mode);
    });
  }

  // init
  apply(localStorage.getItem(storageKey) || 'light');

  // ✅ Intro animation trigger (once on load)
  // Adds class after first paint so animation plays smoothly.
  try {
    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(function () {
        document.body && document.body.classList.add('hero-animate');
      });
    });
  } catch (e) {
    document.body && document.body.classList.add('hero-animate');
  }

  document.addEventListener('click', (e) => {
    // theme buttons (all places)
    const themeBtn = e.target && e.target.closest ? e.target.closest('[data-theme-switch] [data-theme]') : null;
    if (themeBtn) {
      e.preventDefault();
      apply(themeBtn.getAttribute('data-theme') || 'light');
      return;
    }

    // mobile drawer toggle
    const toggle = e.target && e.target.closest ? e.target.closest('[data-nav-toggle]') : null;
    if (toggle) {
      e.preventDefault();
      const drawer = document.querySelector('[data-mobile-drawer]');
      if (!drawer) return;

      drawer.classList.toggle('is-open');
      drawer.setAttribute('aria-hidden', drawer.classList.contains('is-open') ? 'false' : 'true');
    }
  });
})();