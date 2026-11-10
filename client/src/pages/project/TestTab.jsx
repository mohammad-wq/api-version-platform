import { useEffect, useMemo, useRef, useState } from "react";
import { api } from "../../api.js";
import { useAsync, usePersistentState } from "../../hooks.js";
import { EmptyState, ErrorBanner, MethodBadge, Notice, SkeletonList } from "../../components/ui.jsx";
import { Icon } from "../../components/icons.jsx";
import { OperationNotes, RequestBuilder } from "../../components/request_builder.jsx";
import EnvironmentSwitcher from "../../components/EnvironmentSwitcher.jsx";
import EnvironmentManager from "../../components/EnvironmentManager.jsx";
import LoginPanel from "../../components/LoginPanel.jsx";
import { baseUrlError, environmentLabel } from "../../lib/project-environments.js";
import { useTestSession } from "../../lib/test-session.js";

const METHODS = ["GET", "POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS"];
const AUTH_FILTERS = [
  { id: "all", label: "Any" },
  { id: "required", label: "Auth", icon: "lock" },
  { id: "public", label: "Public" },
];
const NO_ENDPOINTS = [];

/** `text` with every case-aware occurrence of `needle` wrapped in a <mark>. */
function Highlight({ text, needle }) {
  const value = text ?? "";
  if (!needle) return value;
  const escaped = needle.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const parts = value.split(new RegExp(`(${escaped})`, "gi"));
  return parts.map((part, index) =>
    part.toLowerCase() === needle.toLowerCase() ? (
      <mark className="hl" key={index}>
        {part}
      </mark>
    ) : (
      part
    ),
  );
}

/** The path with its {placeholders} highlighted. */
function PathText({ path }) {
  return (
    <code className="op-path">
      {path.split(/(\{[^}]+\})/g).map((part, index) =>
        part.startsWith("{") ? (
          <span className="op-path-param" key={index}>
            {part}
          </span>
        ) : (
          part
        ),
      )}
    </code>
  );
}

/** Keeps `--tb-h` on the container in sync with the sticky toolbar's height, so group headers stick below it. */
function useToolbarHeight() {
  const containerRef = useRef(null);
  const toolbarRef = useRef(null);
  useEffect(() => {
    const toolbar = toolbarRef.current;
    if (!toolbar || typeof ResizeObserver === "undefined") return undefined;
    const observer = new ResizeObserver(() => containerRef.current?.style.setProperty("--tb-h", `${toolbar.offsetHeight}px`));
    observer.observe(toolbar);
    return () => observer.disconnect();
  }, []);
  return [containerRef, toolbarRef];
}

/**
 * Remembers which version the endpoint list was built from and, when a live reload swaps it for another
 * (or for an edited copy of the same one), returns a dismissible message saying so.
 */
function useVersionUpdateNotice(version) {
  const shown = useRef(null);
  const [notice, setNotice] = useState(null);
  useEffect(() => {
    if (!version) return;
    const previous = shown.current;
    shown.current = version;
    if (!previous) return;
    if (previous.id !== version.id) {
      setNotice({ text: `The latest version is now ${version.versionNumber} (was ${previous.versionNumber}); the endpoints below were reloaded.` });
    } else if (previous.content_hash !== version.content_hash) {
      setNotice({ text: `${version.versionNumber} was edited; its endpoints were reloaded.` });
    }
  }, [version]);
  return { notice, dismiss: () => setNotice(null) };
}

/** The endpoint list and request forms for one project API. */
export function Tester({ project, reloadProject, goToTab, revision }) {
  // `revision` changes whenever the project's versions do (a new upload, a CLI save, an edit), so the
  // endpoint list always follows the current latest version instead of the one loaded on mount.
  const endpoints = useAsync(() => api.getTestableEndpoints(project.id), [project.id, revision]);
  const list = endpoints.data?.endpoints ?? NO_ENDPOINTS;
  const versionUpdate = useVersionUpdateNotice(endpoints.data?.version);
  const session = useTestSession(project, endpoints.data?.version?.openapiJson, list, api.updateEnvironments);
  const { active, runtimeProject, apiToken, tokenState } = session;
  const [managerFor, setManagerFor] = useState(null);
  const [loginOpen, setLoginOpen] = useState(false);
  const [containerRef, toolbarRef] = useToolbarHeight();

  const [openKeys, setOpenKeys] = usePersistentState(`test.${project.id}.open`, []);
  const [collapsedTags, setCollapsedTags] = usePersistentState(`test.${project.id}.collapsedTags`, []);
  const [filter, setFilter] = usePersistentState(`test.${project.id}.filter`, "");
  const [methodFilter, setMethodFilter] = usePersistentState(`test.${project.id}.methods`, []);
  const [authFilter, setAuthFilter] = usePersistentState(`test.${project.id}.auth`, "all");
  const [caseSensitive, setCaseSensitive] = usePersistentState(`test.${project.id}.caseSensitive`, false);

  // A filtered view keeps its own (unsaved) collapsed groups: it starts expanded so every match is visible,
  // resets when the filter changes, and leaves the saved unfiltered layout untouched.
  const filterKey = JSON.stringify([filter.trim(), caseSensitive, methodFilter, authFilter]);
  const [filteredCollapse, setFilteredCollapse] = useState({ key: filterKey, tags: [] });
  const filteredCollapsedTags = filteredCollapse.key === filterKey ? filteredCollapse.tags : [];
  const setFilteredCollapsedTags = (tags) => setFilteredCollapse({ key: filterKey, tags });

  const toggle = (key, keys, setKeys) => setKeys(keys.includes(key) ? keys.filter((k) => k !== key) : [...keys, key]);
  const haystack = (endpoint) => `${endpoint.method} ${endpoint.path} ${endpoint.summary ?? ""} ${endpoint.tags.join(" ")}`;
  const matchesSearch = (endpoint, needle) =>
    !needle || (caseSensitive ? haystack(endpoint) : haystack(endpoint).toLowerCase()).includes(needle);
  // The needle follows the Match-case toggle; search text is otherwise case-insensitive.
  const needle = caseSensitive ? filter.trim() : filter.trim().toLowerCase();
  // The method counts follow the search box (but not the method filter itself, so every method stays selectable).
  const methodCounts = useMemo(() => {
    const counts = {};
    for (const endpoint of list) if (matchesSearch(endpoint, needle)) counts[endpoint.method] = (counts[endpoint.method] ?? 0) + 1;
    return counts;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [list, needle, caseSensitive]);

  const groups = useMemo(() => {
    const groups = new Map();
    for (const endpoint of list) {
      if (methodFilter.length && !methodFilter.includes(endpoint.method)) continue;
      if (authFilter === "required" && !endpoint.requiresAuth) continue;
      if (authFilter === "public" && endpoint.requiresAuth) continue;
      if (!matchesSearch(endpoint, needle)) continue;
      const tag = endpoint.tags[0] ?? "Other";
      if (!groups.has(tag)) groups.set(tag, []);
      groups.get(tag).push(endpoint);
    }
    return [...groups.entries()];
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [list, needle, caseSensitive, methodFilter, authFilter]);
  const visibleCount = groups.reduce((sum, [, items]) => sum + items.length, 0);

  // "/" focuses the search box (like Postman and GitHub); Escape clears it while focused.
  const searchRef = useRef(null);
  useEffect(() => {
    const onKeyDown = (event) => {
      const el = event.target;
      const typing = el instanceof HTMLElement && (el.tagName === "INPUT" || el.tagName === "TEXTAREA" || el.isContentEditable);
      if (event.key === "/" && !typing) {
        event.preventDefault();
        searchRef.current?.focus();
        searchRef.current?.select();
      } else if (event.key === "Escape" && document.activeElement === searchRef.current && filter) {
        setFilter("");
      }
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [setFilter, filter]);

  const openManager = (id) => {
    setManagerFor(id === "new" ? session.addEnvironment() : id ?? active?.id);
  };

  if (endpoints.error?.code === "NO_VERSIONS") {
    return (
      <EmptyState
        title="Nothing to test yet"
        icon="play"
        action={
          <button className="btn btn-primary" onClick={() => goToTab("versions")}>
            <Icon name="layers" /> Go to Versions
          </button>
        }
      >
        Add a version first: only endpoints from the latest version can be called.
      </EmptyState>
    );
  }

  const filtering = Boolean(filter.trim()) || methodFilter.length > 0 || authFilter !== "all";
  const toggleMethod = (m) => setMethodFilter(methodFilter.includes(m) ? methodFilter.filter((x) => x !== m) : [...methodFilter, m]);
  const [shownCollapsed, setShownCollapsed] = filtering
    ? [filteredCollapsedTags, setFilteredCollapsedTags]
    : [collapsedTags, setCollapsedTags];
  const allTags = groups.map(([tag]) => tag);
  const allCollapsed = allTags.length > 0 && allTags.every((tag) => shownCollapsed.includes(tag));
  const noneCollapsed = !allTags.some((tag) => shownCollapsed.includes(tag));
  const activeUrlError = baseUrlError(active?.baseUrl ?? "");
  const signedIn = tokenState === "active";
  const protectedCount = list.filter((endpoint) => endpoint.requiresAuth).length;

  return (
    <div className="test-tab" ref={containerRef}>
      <div className="test-toolbar" ref={toolbarRef}>
        <div className="tb-row">
          <EnvironmentSwitcher session={session} onManage={openManager} />
          <LoginPanel project={project} session={session} open={loginOpen} onOpenChange={setLoginOpen} onSaved={reloadProject} />
          <span className="grow" />
          {endpoints.data && (
            <span className="tb-version">
              <span className="tb-version-tag" title="Endpoints come from the project's latest version">
                {endpoints.data.version.versionNumber} · latest
              </span>
              <span className="stat-chip">
                <strong>{list.length}</strong> endpoint{list.length === 1 ? "" : "s"}
              </span>
              {protectedCount > 0 && (
                <span className="stat-chip" title="Endpoints that need a sign-in token">
                  <Icon name="lock" /> <strong>{protectedCount}</strong> protected
                </span>
              )}
            </span>
          )}
        </div>
        <div className="tb-row tb-search-row">
          <div className="search-box tb-search">
            <Icon name="search" />
            <input
              type="search"
              className="ops-filter"
              placeholder="Search path, summary or tag…   ( / )"
              value={filter}
              onChange={(e) => setFilter(e.target.value)}
              aria-label="Search endpoints"
              ref={searchRef}
              aria-keyshortcuts="/"
            />
            <button
              type="button"
              className={`icon-btn search-case${caseSensitive ? " active" : ""}`}
              aria-pressed={caseSensitive}
              onClick={() => setCaseSensitive((value) => !value)}
              title={caseSensitive ? "Match case: on" : "Match case: off"}
              aria-label="Match case"
            >
              <span aria-hidden="true">Aa</span>
            </button>
            {filter && (
              <button type="button" className="icon-btn search-clear" onClick={() => setFilter("")} aria-label="Clear search">
                <Icon name="close" />
              </button>
            )}
          </div>
          {filter.trim() && (
            <span className="search-count" aria-live="polite">
              <strong>{visibleCount}</strong> of {list.length}
            </span>
          )}
        </div>
        <div className="tb-row tb-filters">
          <span className="tb-filter-label" aria-hidden="true">Method</span>
          <div className="segmented seg-compact" role="group" aria-label="Filter by method">
            <button type="button" className={methodFilter.length === 0 ? "active" : ""} aria-pressed={methodFilter.length === 0} onClick={() => setMethodFilter([])}>
              All
            </button>
            {METHODS.filter((m) => methodCounts[m] || methodFilter.includes(m)).map((m) => (
              <button
                type="button"
                key={m}
                className={`seg-method seg-method-${m.toLowerCase()}${methodFilter.includes(m) ? " active" : ""}`}
                aria-pressed={methodFilter.includes(m)}
                onClick={() => toggleMethod(m)}
                title={`${methodCounts[m] ?? 0} ${m} endpoint${methodCounts[m] === 1 ? "" : "s"}`}
              >
                {m}
              </button>
            ))}
          </div>
          <span className="tb-filter-sep" aria-hidden="true" />
          <span className="tb-filter-label" aria-hidden="true">Access</span>
          <div className="segmented seg-compact seg-auth-filter" role="group" aria-label="Filter by access">
            {AUTH_FILTERS.map(({ id, label, icon }) => (
              <button key={id} type="button" className={`seg-auth-${id}${authFilter === id ? " active" : ""}`} aria-pressed={authFilter === id} onClick={() => setAuthFilter(id)}>
                {icon && <Icon name={icon} />} {label}
              </button>
            ))}
          </div>
          <span className="grow" />
          {filtering && (
            <button
              type="button"
              className="btn btn-small btn-ghost"
              onClick={() => {
                setFilter("");
                setMethodFilter([]);
                setAuthFilter("all");
              }}
            >
              Clear filters
            </button>
          )}
        </div>
      </div>

      <div className="test-body">
        {versionUpdate.notice && (
          <Notice kind="info" onDismiss={versionUpdate.dismiss}>
            {versionUpdate.notice.text}
          </Notice>
        )}
        {activeUrlError && (
          <p className="env-inline-hint">
            <Icon name="globe" />
            <span>
              <strong>{environmentLabel(active)}</strong> has no base URL yet — requests cannot be sent. Use{' '}
              <button type="button" className="link-btn" onClick={() => openManager(active?.id)}>
                Manage environments
              </button>{' '}
              to set it.
            </span>
          </p>
        )}
        {endpoints.loading && !endpoints.data && <SkeletonList rows={5} height={44} label="Loading endpoints" />}
        <ErrorBanner error={endpoints.error} />

        {groups.length > 0 && (
          <div className="ops-groups-bar">
            <span className="muted">
              {groups.length} group{groups.length === 1 ? "" : "s"} · {visibleCount} endpoint{visibleCount === 1 ? "" : "s"}
            </span>
            <span className="grow" />
            <button
              type="button"
              className="btn btn-small"
              onClick={() => setShownCollapsed([])}
              disabled={noneCollapsed}
              title="Expand every group"
            >
              <Icon name="expand" /> Expand all
            </button>
            <button
              type="button"
              className="btn btn-small"
              onClick={() => {
                setShownCollapsed(allTags);
                setOpenKeys([]);
              }}
              disabled={allCollapsed && openKeys.length === 0}
              title="Collapse every group and close open endpoints"
            >
              <Icon name="collapse" /> Collapse all
            </button>
          </div>
        )}

        {groups.map(([tag, items]) => {
          const collapsed = shownCollapsed.includes(tag);
          // Which methods the group holds, so its contents are recognisable while collapsed.
          const methodMix = METHODS.map((m) => [m, items.filter((endpoint) => endpoint.method === m).length]).filter(([, n]) => n > 0);
          return (
            <section className={`ops-group${collapsed ? " collapsed" : ""}`} key={tag}>
              <button type="button" className="ops-group-head" aria-expanded={!collapsed} onClick={() => toggle(tag, shownCollapsed, setShownCollapsed)}>
                <Icon name="chevron" className={`chevron${collapsed ? "" : " open"}`} />
                <span className="ops-group-title">
                  <Highlight text={tag} needle={filter.trim()} />
                </span>
                <span className="count-pill">{items.length}</span>
                <span className="ops-group-methods" aria-label={methodMix.map(([m, n]) => `${n} ${m}`).join(", ")}>
                  {methodMix.map(([m, n]) => (
                    <span key={m} className={`gm gm-${m.toLowerCase()}`} aria-hidden="true">
                      {m} <b>{n}</b>
                    </span>
                  ))}
                </span>
              </button>
              {!collapsed && (
                <div className="ops-list">
                  {items.map((endpoint) => {
                    const key = `${endpoint.method} ${endpoint.path}`;
                    const open = openKeys.includes(key);
                    return (
                      <div className={`op op-method-${endpoint.method.toLowerCase()}${open ? " open" : ""}`} key={key}>
                        <button type="button" className="op-head" aria-expanded={open} onClick={() => toggle(key, openKeys, setOpenKeys)}>
                          <MethodBadge method={endpoint.method} />
                          <PathText path={endpoint.path} />
                          <span className="op-summary" title={endpoint.summary || undefined}>
                            <Highlight text={endpoint.summary} needle={filter.trim()} />
                          </span>
                          {endpoint.requiresAuth ? (
                            <span
                              className={`op-lock${signedIn ? " signed" : ""}`}
                              title={signedIn ? "Requires a token: you are signed in" : "Requires a token: sign in first"}
                              aria-label="Requires authentication"
                            >
                              <Icon name="lock" />
                            </span>
                          ) : (
                            <span className="op-lock op-lock-none" aria-hidden="true" />
                          )}
                          <Icon name="chevron" className={`chevron${open ? " open" : ""}`} />
                        </button>
                        {open && (
                          <div className="op-body">
                            <OperationNotes endpoint={endpoint} />
                            <RequestBuilder project={runtimeProject} apiToken={apiToken} endpoint={endpoint} showNotes={false} onSignIn={() => setLoginOpen(true)} />
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
              )}
            </section>
          );
        })}
        {endpoints.data && groups.length === 0 && (
          <EmptyState title="No endpoints match" icon="search">
            {filtering ? "Try a different search or clear the filters." : "This version has no testable endpoints."}
          </EmptyState>
        )}
      </div>

      {managerFor && (
        <EnvironmentManager
          session={session}
          initialId={managerFor}
          onClose={() => setManagerFor(null)}
          onSaved={reloadProject}
        />
      )}
    </div>
  );
}

export default function TestTab({ project, reloadProject, goToTab, revision }) {
  return <Tester key={project.id} project={project} reloadProject={reloadProject} goToTab={goToTab} revision={revision} />;
}
