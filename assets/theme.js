(() => {
  const root = document.documentElement;
  try {
    if (localStorage.getItem('bk-theme') === 'dark') root.dataset.theme = 'dark';
  } catch (error) { /* storage may be disabled */ }

  document.addEventListener('DOMContentLoaded', () => {
    const button = document.querySelector('[data-theme-toggle]');
    if (!button) return;
    function update() {
      button.setAttribute('aria-pressed', String(root.dataset.theme === 'dark'));
      button.querySelector('[data-theme-label]').textContent = root.lang === 'fil' ? 'Madilim na tema' : 'Dark mode';
    }
    button.addEventListener('click', () => {
      const theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
      root.dataset.theme = theme;
      try { localStorage.setItem('bk-theme', theme); } catch (error) { /* storage may be disabled */ }
      update();
    });
    new MutationObserver(update).observe(root, { attributeFilter: ['lang'] });
    update();
  });
})();
