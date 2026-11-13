/*
 * Colour theme: light by default; "dark" pins dark and "system" follows the OS (prefers-color-scheme).
 * The choice is stored per browser and applied as <html data-theme> ("system" removes the attribute);
 * index.html applies it before first paint.
 */
import { useCallback, useEffect, useState } from "react";

const KEY = "avp.theme";
export const THEMES = ["system", "light", "dark"];

/** Light unless the user picked another theme in the top bar. */
export const DEFAULT_THEME = "light";

export function readTheme() {
  try {
    const value = localStorage.getItem(KEY);
    return THEMES.includes(value) ? value : DEFAULT_THEME;
  } catch {
    return DEFAULT_THEME;
  }
}

export function applyTheme(theme) {
  const root = document.documentElement;
  if (theme === "system") root.removeAttribute("data-theme");
  else root.setAttribute("data-theme", theme);
}

export function useTheme() {
  const [theme, setThemeState] = useState(readTheme);
  useEffect(() => applyTheme(theme), [theme]);
  const setTheme = useCallback((next) => {
    try {
      if (next === DEFAULT_THEME) localStorage.removeItem(KEY);
      else localStorage.setItem(KEY, next);
    } catch {
      /* storage blocked: the choice still applies for this page view */
    }
    setThemeState(next);
  }, []);
  return [theme, setTheme];
}
