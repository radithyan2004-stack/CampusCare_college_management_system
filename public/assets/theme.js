(() => {
  const root = document.documentElement;
  const toggle = document.querySelector('[data-theme-toggle]');
  const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');

  function activeTheme() {
    return root.dataset.theme || (systemTheme.matches ? 'dark' : 'light');
  }

  function updateToggle() {
    if (!toggle) return;

    const dark = activeTheme() === 'dark';
    toggle.setAttribute('aria-pressed', String(dark));
    toggle.setAttribute('aria-label', `Switch to ${dark ? 'light' : 'dark'} theme`);
    toggle.querySelector('.theme-toggle-icon').textContent = dark ? '☀' : '☾';
    toggle.querySelector('.theme-toggle-label').textContent = dark ? 'Light mode' : 'Dark mode';
  }

  if (toggle) {
    toggle.addEventListener('click', () => {
      const theme = activeTheme() === 'dark' ? 'light' : 'dark';
      root.dataset.theme = theme;
      updateToggle();

      try {
        localStorage.setItem('campuscare-theme', theme);
      } catch (error) {
        console.warn('Unable to save the theme preference.', error);
      }
    });
  }

  systemTheme.addEventListener('change', () => {
    if (!root.dataset.theme) updateToggle();
  });

  window.addEventListener('storage', (event) => {
    if (event.key !== 'campuscare-theme') return;
    if (event.newValue === 'light' || event.newValue === 'dark') {
      root.dataset.theme = event.newValue;
    } else {
      delete root.dataset.theme;
    }
    updateToggle();
  });

  updateToggle();
})();
