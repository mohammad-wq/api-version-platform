import { useMemo, useState } from "react";
import { api } from "../api.js";
import { useAsync } from "../hooks.js";
import { Badge, EmptyState, ErrorBanner, MethodBadge, Notice, SkeletonList } from "./ui.jsx";
import { Icon } from "./icons.jsx";
import LoginPanel from "./LoginPanel.jsx";
import { OperationNotes, RequestBuilder } from "./request_builder.jsx";
import ChangeSections from "./compare/ChangeSections.jsx";
import { buildComparison } from "../lib/compare.js";
import { environmentLabel } from "../lib/project-environments.js";
import { operationsForSpec } from "../lib/openapi-endpoints.js";
import { useTestSession } from "../lib/test-session.js";
import "./split.css";

const METHODS = ["GET", "POST", "PUT", "PATCH", "DELETE", "HEAD", "OPTIONS", "TRACE"];
const OPENAPI_METHODS = METHODS.map((method) => method.toLowerCase());
const keyOf = ({ method, path }) => `${method} ${path}`;
const CHANGE_KIND = { added: "ok", removed: "danger", changed: "warn", unchanged: "neutral" };

function collectOperations(spec) {
  const operations = new Map();
  for (const [path, pathItem] of Object.entries(spec?.paths ?? {})) {
    for (const method of OPENAPI_METHODS) {
      const operation = pathItem?.[method];
      if (operation) operations.set(`${method.toUpperCase()} ${path}`, { method: method.toUpperCase(), path, operation });
    }
  }
  return operations;
}

function ComparisonRequest({ from, to, endpoint, project, apiToken, latestVersionId }) {
  const operation = useAsync(
    () => endpoint.method === "TRACE" ? Promise.resolve(null) : api.compareOperation(from.id, to.id, endpoint.method, endpoint.path),
    [from.id, to.id, endpoint.method, endpoint.path],
  );
  const result = operation.data;
  const contract = useMemo(
    () => result && buildComparison(result.from.operation, result.to.operation),
    [result],
  );

  return (
    <>
      {operation.loading && !result && <SkeletonList rows={1} height={64} label={`Loading ${endpoint.method} ${endpoint.path}`} />}
      {operation.error && <ErrorBanner error={operation.error} />}
      {endpoint.method === "TRACE" && <Notice kind="info">The API tester does not support TRACE requests.</Notice>}
      {result && (
        <>
          <section className="card cmp-api-contract">
            <h4>
              What changed from <code>{result.from.version.versionNumber}</code> to <code>{result.to.version.versionNumber}</code>
            </h4>
            <ChangeSections comparison={contract} labels={{ old: result.from.version.versionNumber, new: result.to.version.versionNumber }} />
          </section>
          {project.baseUrl ? (
            <div className="cmp-try-cols cmp-api-try-cols">
              {[
                { side: "older", version: result.from.version, operation: result.from.operation },
                { side: "newer", version: result.to.version, operation: result.to.operation },
              ].map(({ side, version, operation: apiOperation }) => {
                const locked = version.id !== latestVersionId;
                return (
                  <section className={`cmp-try-col ${side}`} key={side}>
                    <div className="cmp-try-head">
                      <span>
                        {side === "older" ? "Older" : "Newer"} · <code>{version.versionNumber}</code>
                      </span>
                      {locked ? <Badge kind="neutral"><Icon name="lock" /> call disabled</Badge> : <Badge kind="ok">latest — can be called</Badge>}
                    </div>
                    <div className="cmp-try-body">
                      {!apiOperation ? (
                        <p className="muted cmp-empty">This API does not exist in version {version.versionNumber}.</p>
                      ) : (
                        <>
                          <OperationNotes endpoint={apiOperation} />
                          <RequestBuilder
                            project={project}
                            apiToken={apiToken}
                            endpoint={apiOperation}
                            locked={locked}
                            lockedReason={`Only the project's latest version can be called. Version ${version.versionNumber} is not the latest.`}
                            scope={`compare:v${version.id}:`}
                            showNotes={false}
                          />
                        </>
                      )}
                    </div>
                  </section>
                );
              })}
            </div>
          ) : (
            <Notice kind="info">Choose an environment with a valid base URL on the Test APIs page to test this API.</Notice>
          )}
        </>
      )}
    </>
  );
}

function ApiRow({ endpoint, status, breaking, expanded, onToggle, ...testProps }) {
  return (
    <article className={`op op-method-${endpoint.method.toLowerCase()}${expanded ? " open" : ""} cmp-api-row`}>
      <button type="button" className="op-head" aria-expanded={expanded} onClick={onToggle}>
        <MethodBadge method={endpoint.method} />
        <code className="op-path">{endpoint.path}</code>
        <span className="op-summary muted">{endpoint.summary}</span>
        <Badge kind={CHANGE_KIND[status]}>{status}</Badge>
        <Icon name="chevron" className={`chevron${expanded ? " open" : ""}`} />
      </button>
      {expanded && (
        <div className="op-body cmp-api-body">
          {endpoint.summary && <p className="muted cmp-api-summary">{endpoint.summary}</p>}
          {breaking.length > 0 && (
            <div className="row wrap">
              {breaking.map((reason) => <Badge kind="danger" key={reason}>Breaking: {reason}</Badge>)}
            </div>
          )}
          <ComparisonRequest endpoint={endpoint} {...testProps} />
        </div>
      )}
    </article>
  );
}

/** Renders the version summary and a test-page-style, expandable side-by-side API list. */
export default function DiffView({ project, comparison, onClose, reloadProject }) {
  const { from, to, diff } = comparison;
  const [text, setText] = useState("");
  const [loginOpen, setLoginOpen] = useState(false);
  const [methods, setMethods] = useState([]);
  const [changesOnly, setChangesOnly] = useState(false);
  const [expanded, setExpanded] = useState([]);
  const [collapsedTags, setCollapsedTags] = useState([]);
  const [collapsed, setCollapsed] = useState(false);

  const specs = useAsync(
    () => Promise.all([api.getVersion(from.id), api.getVersion(to.id)]),
    [from.id, to.id],
  );
  // Only the latest version can be sent to; take it from the live project so a newer upload locks this one.
  const latestVersionId = (project.versions.find((version) => version.isLatest) ?? project.versions[0])?.id;
  const newerSpec = specs.data?.[1]?.openapiJson;
  const newerEndpoints = useMemo(() => [...operationsForSpec(newerSpec ?? {}).values()], [newerSpec]);
  const session = useTestSession(project, newerSpec, newerEndpoints, api.updateEnvironments);
  const { active, runtimeProject, apiToken } = session;

  const changes = useMemo(() => {
    const entries = new Map();
    for (const endpoint of diff.endpoints.added) entries.set(keyOf(endpoint), { status: "added", breaking: [] });
    for (const endpoint of diff.endpoints.removed) entries.set(keyOf(endpoint), { status: "removed", breaking: ["endpoint removed"] });
    for (const endpoint of diff.endpoints.changed) entries.set(keyOf(endpoint), { status: "changed", breaking: endpoint.breaking ?? [] });
    for (const endpoint of diff.breaking) {
      const entry = entries.get(keyOf(endpoint));
      if (entry && !entry.breaking.includes(endpoint.reason)) entry.breaking.push(endpoint.reason);
    }
    return entries;
  }, [diff]);

  const endpoints = useMemo(() => {
    if (!specs.data) return [];
    const older = collectOperations(specs.data[0].openapiJson);
    const newer = collectOperations(specs.data[1].openapiJson);
    const keys = new Set([...newer.keys(), ...older.keys()]);
    return [...keys].map((key) => {
      const before = older.get(key);
      const after = newer.get(key);
      const endpoint = after ?? before;
      const change = changes.get(key);
      return {
        ...endpoint,
        summary: after?.operation.summary ?? before?.operation.summary ?? "",
        tags: after?.operation.tags?.length ? after.operation.tags : before?.operation.tags ?? [],
        status: change?.status ?? "unchanged",
        breaking: change?.breaking ?? [],
      };
    });
  }, [specs.data, changes]);

  const needle = text.trim().toLowerCase();
  const filtered = endpoints.filter((endpoint) => {
    if (methods.length && !methods.includes(endpoint.method)) return false;
    if (changesOnly && endpoint.status === "unchanged") return false;
    return !needle || `${endpoint.method} ${endpoint.path} ${endpoint.summary} ${endpoint.tags.join(" ")}`.toLowerCase().includes(needle);
  });
  const grouped = useMemo(() => {
    const groups = new Map();
    for (const endpoint of filtered) {
      const tag = endpoint.tags[0] ?? "Other";
      if (!groups.has(tag)) groups.set(tag, []);
      groups.get(tag).push(endpoint);
    }
    return [...groups.entries()];
  }, [filtered]);
  const toggle = (list, setList, item) => setList(list.includes(item) ? list.filter((value) => value !== item) : [...list, item]);
  const toggleMethod = (method) => toggle(methods, setMethods, method);
  const toggleApi = (key) => toggle(expanded, setExpanded, key);
  const toggleTag = (tag) => toggle(collapsedTags, setCollapsedTags, tag);

  const counts = {
    added: diff.summary.endpointsAdded,
    removed: diff.summary.endpointsRemoved,
    changed: diff.summary.endpointsChanged,
    unchanged: Math.max(0, endpoints.length - diff.summary.endpointsAdded - diff.summary.endpointsRemoved - diff.summary.endpointsChanged),
  };
  const availableMethods = [...new Set(endpoints.map((endpoint) => endpoint.method))];

  return (
    <section className={`card cmp cmp-api-list-card${collapsed ? " is-collapsed" : ""}`}>
      <div className="row space wrap">
        <div>
          <h3>APIs in <code>{from.versionNumber}</code> and <code>{to.versionNumber}</code></h3>
          <p className="muted">
            {collapsed
              ? "Comparison collapsed — expand it to browse, filter and test the API list."
              : "Expand an API to compare and test both versions side by side."}
          </p>
        </div>
        <div className="row wrap">
          <button
            type="button"
            className="btn btn-ghost"
            onClick={() => setCollapsed((value) => !value)}
            aria-expanded={!collapsed}
            title={collapsed ? "Expand the comparison" : "Collapse the comparison"}
          >
            <Icon name={collapsed ? "expand" : "collapse"} /> {collapsed ? "Expand" : "Collapse"}
          </button>
          {onClose && <button className="btn btn-ghost" onClick={onClose}>Close</button>}
        </div>
      </div>

      {/* Kept mounted while collapsed so filters, expanded APIs and loaded results survive. */}
      <div className="cmp-body" style={collapsed ? { display: "none" } : undefined}>
      <div className="chips">
        <Badge kind="ok">{counts.added} added</Badge>
        <Badge kind="danger">{counts.removed} removed</Badge>
        <Badge kind="warn">{counts.changed} changed</Badge>
        <Badge kind="neutral">{counts.unchanged} unchanged</Badge>
        {diff.summary.breakingChanges > 0 ? <Badge kind="danger">{diff.summary.breakingChanges} breaking changes</Badge> : <Badge kind="ok">No breaking changes</Badge>}
      </div>

      {diff.identical && <Notice kind="info">These versions are identical. APIs are still available below for testing.</Notice>}

      <div className="cmp-api-tools">
        <label className="cmp-api-env">
          <span className="cmp-lab">Active environment</span>
          <span className="muted">{environmentLabel(active)} · {runtimeProject.baseUrl || "Base URL not configured"}</span>
        </label>
        {session.auth.authRequired && <LoginPanel project={project} session={session} open={loginOpen} onOpenChange={setLoginOpen} onSaved={reloadProject} />}
      </div>

      <div className="cmp-api-filters">
        <div className="search-box">
          <Icon name="search" />
          <input type="search" placeholder="Search APIs by path or summary…" value={text} onChange={(event) => setText(event.target.value)} aria-label="Search APIs" />
        </div>
        <div className="chip-group" role="group" aria-label="Filter APIs by method">
          {availableMethods.map((method) => (
            <button type="button" key={method} className={`method-chip method-chip-${method.toLowerCase()}${methods.includes(method) ? " active" : ""}`} aria-pressed={methods.includes(method)} onClick={() => toggleMethod(method)}>
              {method}
            </button>
          ))}
        </div>
        <label className="cmp-api-changes-toggle">
          <input type="checkbox" checked={changesOnly} onChange={(event) => setChangesOnly(event.target.checked)} />
          Changed APIs only
        </label>
        <span className="grow" />
        <button type="button" className="btn btn-small btn-ghost" onClick={() => setCollapsedTags([])}>
          <Icon name="expand" /> Expand groups
        </button>
        <button type="button" className="btn btn-small btn-ghost" onClick={() => setCollapsedTags(grouped.map(([tag]) => tag))}>
          <Icon name="collapse" /> Collapse groups
        </button>
      </div>

      {specs.loading && !specs.data && <SkeletonList rows={5} height={52} label="Loading APIs from both versions" />}
      <ErrorBanner error={specs.error} />

      {specs.data && grouped.map(([tag, items]) => {
        const collapsed = collapsedTags.includes(tag);
        return (
          <section className="ops-group cmp-api-group" key={tag}>
            <button type="button" className="ops-group-head" aria-expanded={!collapsed} onClick={() => toggleTag(tag)}>
              <Icon name="chevron" className={`chevron${collapsed ? "" : " open"}`} />
              <span className="ops-group-title">{tag}</span>
              <span className="count-pill">{items.length}</span>
            </button>
            {!collapsed && items.map((endpoint) => {
              const key = keyOf(endpoint);
              return (
                <ApiRow
                  key={key}
                  endpoint={endpoint}
                  status={endpoint.status}
                  breaking={endpoint.breaking}
                  expanded={expanded.includes(key)}
                  onToggle={() => toggleApi(key)}
                  from={from}
                  to={to}
                  project={runtimeProject}
                  apiToken={apiToken}
                  latestVersionId={latestVersionId}
                />
              );
            })}
          </section>
        );
      })}
      {specs.data && grouped.length === 0 && (
        <EmptyState title="No APIs match" icon="search">
          Try another search or turn off “Changed APIs only”.
        </EmptyState>
      )}
      </div>
    </section>
  );
}
