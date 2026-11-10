import { useEffect, useRef, useState } from "react";
import { Link, useNavigate, useParams, useSearchParams } from "react-router-dom";
import { api } from "../api.js";
import { useAuth } from "../auth.jsx";
import { useAction, useAsync } from "../hooks.js";
import { Avatar, ErrorBanner, RolePill, Skeleton, formatDate, useCrumb, usePopover } from "../components/ui.jsx";
import { ProjectDetailsForm } from "../components/ApiAuthSettings.jsx";
import { Icon } from "../components/icons.jsx";
import { mode } from "../mode.js";
import { downloadReadmeTemplate, downloadReadmeTemplatePdf } from "../download.js";
import { canProjectRole, projectRoleForUser } from "../lib/permissions.js";
import ReadmeSyncBanner from "../components/ReadmeSyncBanner.jsx";
import VersionsTab from "./project/VersionsTab.jsx";
import TestTab from "./project/TestTab.jsx";
import MembersTab from "./project/MembersTab.jsx";
import HistoryTab from "./project/HistoryTab.jsx";

const TABS = [
  { id: "versions", label: "Versions", icon: "layers", count: "versions" },
  { id: "test", label: "Test APIs", icon: "play" },
  { id: "history", label: "History", icon: "clock" },
  { id: "members", label: "Collaborators", icon: "users", count: "members" },
];


/**
 * Identifies the project's version list: changes when a version is added, deleted, renamed, re-pointed
 * as latest, or has its OpenAPI document edited. Tabs reload their version-derived data when it changes.
 */
export function versionSignature(project) {
  return (project?.versions ?? [])
    .map((version) => `${version.id}:${version.versionNumber}:${version.content_hash ?? ""}:${version.isLatest ? 1 : 0}`)
    .join("|");
}

// Local mode reads one SQLite file on this machine, so it can be checked often; the hosted server less so.
const WATCH_MS = { local: 4000, hosted: 30000 };

/**
 * Versions can be created outside this page: `avp version create` in a terminal, another browser tab,
 * or a collaborator. Checks the project while the page is visible (on an interval, and when the window
 * regains focus) and calls `onChange` when its versions differ from the ones on screen.
 */
function useVersionWatch(projectId, signature, onChange) {
  const current = useRef(signature);
  current.current = signature;
  const callback = useRef(onChange);
  callback.current = onChange;
  const loaded = signature != null;

  useEffect(() => {
    if (!loaded) return undefined;
    let stopped = false;
    let inFlight = false;
    const check = async () => {
      if (inFlight || document.visibilityState === "hidden") return;
      inFlight = true;
      try {
        const fresh = await api.getProject(projectId);
        if (!stopped && versionSignature(fresh) !== current.current) callback.current();
      } catch {
        /* offline or the server restarted: the next check tries again */
      } finally {
        inFlight = false;
      }
    };
    const onVisible = () => document.visibilityState === "visible" && check();
    const timer = setInterval(check, mode.local ? WATCH_MS.local : WATCH_MS.hosted);
    window.addEventListener("focus", check);
    document.addEventListener("visibilitychange", onVisible);
    return () => {
      stopped = true;
      clearInterval(timer);
      window.removeEventListener("focus", check);
      document.removeEventListener("visibilitychange", onVisible);
    };
  }, [projectId, loaded]);
}

function ProjectSkeleton() {
  return (
    <div role="status" aria-label="Loading project">
      <div className="card project-header">
        <Skeleton w={56} h={56} r={14} />
        <div className="grow stack" style={{ gap: 10 }}>
          <Skeleton w={240} h={20} />
          <Skeleton w={320} h={12} />
        </div>
      </div>
      <div className="project-layout">
        <div className="side-nav stack" style={{ gap: 8 }}>
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} h={36} r={8} />
          ))}
        </div>
        <div className="stack">
          <Skeleton h={180} r={12} />
          <Skeleton h={260} r={12} />
        </div>
      </div>
    </div>
  );
}

export default function ProjectPage() {
  const { projectId } = useParams();
  const { user } = useAuth();
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  // Collaborators and project deletion need the hosted server. Read at render time: mode is detected after module load.
  const visibleTabs = mode.local ? TABS.filter((t) => t.id !== "members") : TABS;
  // The tab comes from the URL: `?tab=` when there is one, Versions on a plain project link.
  // Keeping it purely in the URL (no localStorage) makes every link deterministic.
  const defaultTab = visibleTabs.some((t) => t.id === params.get("tab")) ? params.get("tab") : "versions";
  const [tab, setTab] = useState(defaultTab);
  const [urlTab, setUrlTab] = useState(defaultTab);
  // A different URL wins on navigation/refresh (back/forward, bookmarks, `/projects/1?tab=history`).
  if (defaultTab !== urlTab) {
    setUrlTab(defaultTab);
    setTab(defaultTab);
  }
  const selectTab = (id) => {
    setTab(id);
    setParams({ tab: id });
  };
  const project = useAsync(() => api.getProject(projectId), [projectId]);
  const remove = useAction();
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [editing, setEditing] = useState(false);
  const [menuOpen, setMenuOpen, menuRef] = usePopover();
  useCrumb(project.data?.name);
  // Every tab that shows version-derived data (endpoints, the version list) reloads when this changes,
  // without remounting, so open endpoints, filters and in-progress requests stay where they are.
  const revision = project.data ? versionSignature(project.data) : null;
  useVersionWatch(projectId, revision, project.reload);

  if (project.loading && !project.data) return <ProjectSkeleton />;
  if (project.error) {
    return (
      <div className="stack">
        <ErrorBanner error={project.error} />
        <Link to="/" className="back">
          <Icon name="arrowLeft" /> Back to projects
        </Link>
      </div>
    );
  }

  const data = project.data;
  const role = projectRoleForUser(data, user);
  if (!role) {
    return (
      <div className="stack">
        <ErrorBanner error="Your account does not have an active owner, editor, or viewer role for this project." />
        <Link to="/" className="back">
          <Icon name="arrowLeft" /> Back to projects
        </Link>
      </div>
    );
  }
  const can = (minimum) => canProjectRole(role, minimum);
  const counts = data._count ?? {
    versions: data.versions.length,
    members: data.members.length,
  };

  const deleteProject = async () => {
    const ok = await remove.run(async () => {
      await api.deleteProject(data.id);
      return true;
    });
    if (ok) navigate("/");
  };

  const tabProps = { project: data, role, can, reloadProject: project.reload, revision };

  return (
    <>
      <header className="card project-header">
        <div className="project-header-main">
          <Avatar name={data.name} size={48} square />
          <div className="project-title">
            <h1>{data.name}</h1>
            {data.description ? (
              <p className="project-description">{data.description}</p>
            ) : (
              can("EDITOR") && !editing && (
                <button type="button" className="link-btn project-add-description" onClick={() => setEditing(true)}>
                  <Icon name="plus" /> Add a description
                </button>
              )
            )}
          </div>
          <div className="project-actions">
            {can("EDITOR") && !editing && (
              <button className="btn btn-small" onClick={() => setEditing(true)}>
                <Icon name="edit" /> Edit details
              </button>
            )}
            <div className="menu-wrap" ref={menuRef}>
              <button className="btn" aria-label="Project actions" aria-haspopup="menu" aria-expanded={menuOpen} onClick={() => setMenuOpen(!menuOpen)}>
                <Icon name="more" />
              </button>
              {menuOpen && (
                <div className="menu menu-right" role="menu">
                  <button className="menu-item" role="menuitem" onClick={() => { setMenuOpen(false); downloadReadmeTemplate(); }} title="Download the Markdown template supported by the README parser">
                    <Icon name="download" /> README template
                  </button>
                  <button className="menu-item" role="menuitem" onClick={() => { setMenuOpen(false); downloadReadmeTemplatePdf(); }} title="Download a printable PDF copy of the README template">
                    <Icon name="download" /> PDF template
                  </button>
                  {can("OWNER") && !mode.local && (
                    <>
                      <div className="menu-sep" role="separator" />
                      <button
                        className="menu-item menu-danger"
                        role="menuitem"
                        onClick={() => {
                          setMenuOpen(false);
                          setConfirmDelete(true);
                        }}
                      >
                        <Icon name="trash" /> Delete project
                      </button>
                    </>
                  )}
                </div>
              )}
            </div>
          </div>
        </div>

        {editing && (
          <div className="project-edit">
            <ProjectDetailsForm
              project={data}
              canEdit
              onSaved={() => {
                setEditing(false);
                project.reload();
              }}
              onCancel={() => setEditing(false)}
            />
          </div>
        )}

        <dl className="project-meta">
          <div>
            <dt>Owner</dt>
            <dd title={data.owner.email}>
              <Avatar name={data.owner.name || data.owner.email} size={20} />
              <span className="truncate">{data.owner.name || data.owner.email}</span>
            </dd>
          </div>
          <div>
            <dt>Created</dt>
            <dd>{formatDate(data.createdAt)}</dd>
          </div>
          <div>
            <dt>Latest version</dt>
            <dd>{data.latestVersionNumber ? <span className="tb-version-tag">{data.latestVersionNumber}</span> : <span className="muted">None yet</span>}</dd>
          </div>
          {!mode.local && (
            <div>
              <dt>Your role</dt>
              <dd>
                <RolePill role={role} />
              </dd>
            </div>
          )}
        </dl>
      </header>

      {confirmDelete && (
        <div className="confirm-bar" role="alertdialog" aria-label="Confirm project deletion">
          <Icon name="alert" />
          <span className="grow">
            <strong>Delete “{data.name}”?</strong> This removes every version and request history in it. It cannot be undone.
          </span>
          <button className="btn btn-danger-solid" onClick={deleteProject} disabled={remove.busy}>
            {remove.busy ? "Deleting…" : "Yes, delete"}
          </button>
          <button className="btn" onClick={() => setConfirmDelete(false)}>
            Cancel
          </button>
        </div>
      )}
      <ErrorBanner error={remove.error} />

      {mode.local && (
        <ReadmeSyncBanner revision={revision} onSaved={() => project.reload()} />
      )}

      <div className="project-layout">
        <nav className="side-nav" aria-label="Project sections">
          {visibleTabs.map((t) => (
            <button key={t.id} className={`side-link${tab === t.id ? " active" : ""}`} aria-current={tab === t.id ? "page" : undefined} onClick={() => selectTab(t.id)}>
              <Icon name={t.icon} />
              <span className="side-label">{t.label}</span>
              {t.count && counts[t.count] != null && <span className="side-count">{counts[t.count]}</span>}
            </button>
          ))}
        </nav>

        <section className="project-content" aria-label={visibleTabs.find((t) => t.id === tab)?.label}>
          {tab === "versions" && <VersionsTab {...tabProps} goToTab={selectTab} />}
          {tab === "test" && <TestTab {...tabProps} goToTab={selectTab} />}
          {tab === "history" && <HistoryTab {...tabProps} />}
          {tab === "members" && !mode.local && <MembersTab {...tabProps} />}
        </section>
      </div>
    </>
  );
}
