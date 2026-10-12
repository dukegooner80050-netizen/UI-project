// Light/Dark mode for the whole app.
//
// Uses Bootstrap 5.3's built-in color-mode support: setting
// data-bs-theme="dark" on <html> automatically re-themes every Bootstrap
// component (cards, tables, forms, modals, badges, buttons, etc.) via CSS
// variables -- no per-component dark styles needed for those.
//
// The choice is saved in localStorage so it persists across reloads and
// is applied as early as possible (see main.js) to avoid a flash of the
// wrong theme on load.

const STORAGE_KEY = "cims_theme";

export function getStoredTheme() {
  try {
    return localStorage.getItem(STORAGE_KEY);
  } catch {
    return null;
  }
}

export function getTheme() {
  return getStoredTheme() === "dark" ? "dark" : "light";
}

export function applyTheme(theme) {
  const resolved = theme === "dark" ? "dark" : "light";
  document.documentElement.setAttribute("data-bs-theme", resolved);
}

export function setTheme(theme) {
  const resolved = theme === "dark" ? "dark" : "light";

  try {
    localStorage.setItem(STORAGE_KEY, resolved);
  } catch {
    // localStorage unavailable (private browsing, etc.) -- theme just
    // won't persist across reloads, but still applies for this session.
  }

  applyTheme(resolved);
}

// Call once on app boot.
export function initTheme() {
  applyTheme(getTheme());
}
