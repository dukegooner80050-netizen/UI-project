/* Light/dark mode via Bootstrap's data-bs-theme; saved in localStorage and applied early (main.js). */

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
    /* localStorage unavailable: the theme applies for this session but won't persist. */
  }

  applyTheme(resolved);
}

// Call once on app boot.
export function initTheme() {
  applyTheme(getTheme());
}
