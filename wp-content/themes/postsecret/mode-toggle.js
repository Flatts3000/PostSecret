(function () {
  const root = document.documentElement;
  const KEY = 'ps-theme'; // 'dark' | 'light'
  const saved = localStorage.getItem(KEY);

  console.log('Theme toggle initialized. Saved theme:', saved);

  // Initialize with saved theme or default to light
  if (saved === 'dark' || saved === 'light') {
    root.setAttribute('data-theme', saved);
    console.log('Applied saved theme:', saved);
  } else {
    // Default to light theme if no preference saved
    root.setAttribute('data-theme', 'light');
    localStorage.setItem(KEY, 'light');
    console.log('Defaulted to light theme');
  }

  function updateLogo(mode) {
    const logos = document.querySelectorAll('.ps-logo');
    if (logos.length === 0) {
      console.log('No logo elements found');
      return;
    }

    console.log('Updating logos for mode:', mode);

    logos.forEach(logo => {
      const lightSrc = logo.getAttribute('data-light-src');
      const darkSrc = logo.getAttribute('data-dark-src');

      if (mode === 'dark' && darkSrc) {
        logo.src = darkSrc;
        console.log('Set logo to dark version');
      } else if (lightSrc) {
        logo.src = lightSrc;
        console.log('Set logo to light version');
      }
    });
  }

  function setMode(mode) {
    console.log('Setting mode to:', mode);

    // Update DOM and storage
    root.setAttribute('data-theme', mode);
    localStorage.setItem(KEY, mode);

    console.log('DOM data-theme attribute set to:', root.getAttribute('data-theme'));

    // Force a style recalculation to ensure CSS variables are applied
    document.body.offsetHeight; // This forces a reflow

    // Update logo after DOM changes
    updateLogo(mode);
  }

  // Update year in footer
  function updateYear() {
    const yearElements = document.querySelectorAll('.ps-year');
    const currentYear = new Date().getFullYear();
    yearElements.forEach(element => {
      element.textContent = currentYear;
    });
  }

  // Initialize logo and year on page load
  document.addEventListener('DOMContentLoaded', () => {
    // Update year immediately
    updateYear();

    // Small delay to ensure CSS is loaded for logo
    setTimeout(() => {
      const currentMode = root.getAttribute('data-theme');
      updateLogo(currentMode);
    }, 100);
  });

  // Handle theme toggle clicks
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-ps-theme-toggle]');
    if (!t) return;

    const current = root.getAttribute('data-theme');
    console.log('Current theme before toggle:', current);

    // Simple toggle: light ↔ dark
    const next = current === 'dark' ? 'light' : 'dark';
    console.log('Next theme:', next);

    setMode(next);

    // Update button state
    t.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false');
  });

  // Remove system theme change handler - we only use explicit light/dark modes
})();