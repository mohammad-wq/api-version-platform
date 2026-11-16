function appRootPath(pathname) {
  const route = pathname.match(/\/projects(?:\/|$)/);
  if (route) return pathname.slice(0, route.index + 1);

  const withoutIndex = pathname.replace(/index\.html?$/i, "");
  if (withoutIndex !== pathname) return withoutIndex.endsWith("/") ? withoutIndex : `${withoutIndex}/`;
  if (pathname.endsWith("/")) return pathname;
  return pathname.slice(0, pathname.lastIndexOf("/") + 1) || "/";
}

// Vite is built with a relative asset base so the deployment prefix can change.
// React Router needs the app's absolute URL path as its basename.
export const BASE = appRootPath(window.location.pathname);

/** Relative PHP API paths resolve from the app's deployment root, not the current SPA route. */
function apiBase() {
  if (import.meta.env.DEV) return "/backend";

  // The API is a sibling of the UI's physical html folder. Some Apache setups map
  // that folder to the parent URL, where the API is adjacent instead.
  const defaultApiPath = BASE.replace(/\/+$/, "").endsWith("/html") ? "../backend" : "./backend";
  const configured = import.meta.env.VITE_API_BASE ?? defaultApiPath;
  if (/^(?:[a-z][a-z\d+.-]*:)?\/\//i.test(configured) || configured.startsWith("/")) {
    return configured.replace(/\/+$/, "");
  }

  const rootUrl = new URL(BASE, window.location.origin);
  return new URL(configured, rootUrl).pathname.replace(/\/+$/, "");
}

export const API_BASE = apiBase();

/** An in-app path as a URL path that includes the app's deployment subfolder. */
export const appPath = (path) => `${BASE.replace(/\/$/, "")}${path}`;
