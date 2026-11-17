import { useEffect, useState } from "react";
import { api } from "../../api.js";
import { useAction, useAsync } from "../../hooks.js";
import { EmptyState, ErrorBanner, Notice, SkeletonList, formatDate } from "../../components/ui.jsx";
import { Icon } from "../../components/icons.jsx";

const isCleartext = (url) => /^http:\/\/(?!(localhost|127\.0\.0\.1|\[::1\])([:/]|$))/i.test(String(url).trim());

function SignIn({ status, onDone }) {
  const [url, setUrl] = useState(status.url ?? "");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showUrl, setShowUrl] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const { busy, error, run } = useAction();

  const submit = async (event) => {
    event.preventDefault();
    const result = await run(() => api.cloudLogin({ url, email, password }));
    if (result) {
      setPassword("");
      onDone();
    }
  };

  return (
    <form className="card stack" onSubmit={submit}>
      <div className="card-head">
        <div>
          <h3>Sign in to the cloud</h3>
          <p className="muted">Use your account on the hosted platform to publish this project's versions and browse your cloud projects.</p>
        </div>
      </div>
      <ErrorBanner error={error} />
      {isCleartext(url) && <Notice kind="warn">This address uses http://, so your password would travel unencrypted. Use https:// if the server supports it.</Notice>}
      <label>
        <span className="label-line">Email</span>
        <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="username" required />
      </label>
      <label>
        <span className="label-line">Password</span>
        <div className="password-input-wrap">
          <input type={showPassword ? "text" : "password"} value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" required />
          <button
            type="button"
            className="icon-btn password-toggle"
            onClick={() => setShowPassword((visible) => !visible)}
            aria-label={showPassword ? "Hide password" : "Show password"}
            aria-pressed={showPassword}
          >
            <Icon name={showPassword ? "eyeOff" : "eye"} />
          </button>
        </div>
      </label>
      {showUrl ? (
        <label>
          <span className="label-line">Cloud URL</span>
          <input type="url" value={url} onChange={(e) => setUrl(e.target.value)} required />
        </label>
      ) : (
        <p className="muted">
          Server: <span className="break">{url}</span>{" "}
          <button type="button" className="link-btn" onClick={() => setShowUrl(true)}>
            Change
          </button>
        </p>
      )}
      <div>
        <button className="btn btn-primary" disabled={busy || !email || !password}>
          <Icon name="key" /> {busy ? "Signing in…" : "Sign in"}
        </button>
      </div>
    </form>
  );
}

function Publish({ project, status, cloudProjects, onPublished }) {
  const versions = project.versions ?? [];
  const linked = status.linkedProject;
  const [versionId, setVersionId] = useState(String(versions.find((v) => v.isLatest)?.id ?? versions[0]?.id ?? ""));
  const [target, setTarget] = useState(() =>
    linked && cloudProjects.some((p) => p.id === linked.id) ? String(linked.id) : cloudProjects.length ? String(cloudProjects[0].id) : "new",
  );
  const [newName, setNewName] = useState(project.name ?? "");
  const [label, setLabel] = useState("");
  const [note, setNote] = useState("");
  const { busy, error, setError, run } = useAction();
  const [done, setDone] = useState(null);

  const selected = versions.find((v) => String(v.id) === versionId);
  const submit = async (event) => {
    event.preventDefault();
    setDone(null);
    const cloudProject = cloudProjects.find((p) => String(p.id) === target);
    const result = await run(() =>
      api.cloudPublish({
        versionId: Number(versionId),
        projectId: target === "new" ? null : Number(target),
        projectName: cloudProject?.name,
        newProjectName: target === "new" ? newName : undefined,
        version: label,
        note,
      }),
    );
    if (result) {
      setDone(result);
      setLabel("");
      onPublished();
    }
  };

  if (versions.length === 0) {
    return (
      <EmptyState title="Nothing to publish yet" icon="upload">
        Save a version first (from the README banner or the Versions tab), then publish it here.
      </EmptyState>
    );
  }

  return (
    <form className="card stack" onSubmit={submit}>
      <div className="card-head">
        <div>
          <h3>Publish a version</h3>
          <p className="muted">Sends the version's OpenAPI document to a cloud project as a new version.</p>
        </div>
      </div>
      <ErrorBanner error={error} onDismiss={() => setError(null)} />
      {done && (
        <Notice kind="ok" onDismiss={() => setDone(null)}>
          Published <strong>{done.version}</strong>{done.projectCreated ? " to a new cloud project" : ""}.{" "}
          {done.url && (
            <a href={done.url} target="_blank" rel="noreferrer">
              Open in the cloud <Icon name="externalLink" />
            </a>
          )}
        </Notice>
      )}
      <div className="form-row">
        <label>
          <span className="label-line">Local version</span>
          <select value={versionId} onChange={(e) => setVersionId(e.target.value)}>
            {versions.map((v) => (
              <option key={v.id} value={v.id}>
                {v.versionNumber}
                {v.isLatest ? " (latest)" : ""}
              </option>
            ))}
          </select>
        </label>
        <label>
          <span className="label-line">Cloud project</span>
          <select value={target} onChange={(e) => setTarget(e.target.value)}>
            {cloudProjects.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
            <option value="new">+ New cloud project…</option>
          </select>
        </label>
      </div>
      {target === "new" && (
        <label>
          <span className="label-line">New project name</span>
          <input value={newName} onChange={(e) => setNewName(e.target.value)} maxLength={190} required />
        </label>
      )}
      <div className="form-row">
        <label>
          <span className="label-line">
            Cloud version name <small className="muted">defaults to {selected?.versionNumber}</small>
          </span>
          <input value={label} onChange={(e) => setLabel(e.target.value)} maxLength={50} placeholder={selected?.versionNumber} />
        </label>
        <label>
          <span className="label-line">
            Note <small className="muted">optional</small>
          </span>
          <input value={note} onChange={(e) => setNote(e.target.value)} />
        </label>
      </div>
      <div>
        <button className="btn btn-primary" disabled={busy || !versionId || (target === "new" && !newName.trim())}>
          <Icon name="upload" /> {busy ? "Publishing…" : "Publish to cloud"}
        </button>
      </div>
    </form>
  );
}

function CloudProject({ cloudProject, onImported }) {
  const [open, setOpen] = useState(false);
  const versions = useAsync(() => (open ? api.cloudVersions(cloudProject.id) : Promise.resolve(null)), [open, cloudProject.id]);
  const { busy, error, setError, run } = useAction();
  const [imported, setImported] = useState(null);

  const importVersion = async (version) => {
    const result = await run(() => api.cloudImport({ versionId: version.id }));
    if (result) {
      setImported(result.version);
      onImported();
    }
  };

  return (
    <li className="cloud-project">
      <button type="button" className="side-link" aria-expanded={open} onClick={() => setOpen(!open)}>
        <Icon name={open ? "down" : "chevron"} />
        <span className="side-label">{cloudProject.name}</span>
        {cloudProject.latest_version && <span className="side-count">{cloudProject.latest_version}</span>}
      </button>
      {open && (
        <div className="stack" style={{ paddingLeft: 24 }}>
          <ErrorBanner error={error} onDismiss={() => setError(null)} />
          {imported && <Notice kind="ok" onDismiss={() => setImported(null)}>Imported as local version <strong>{imported}</strong>.</Notice>}
          {versions.loading && !versions.data && <SkeletonList rows={2} height={36} label="Loading cloud versions" />}
          <ErrorBanner error={versions.error} />
          {versions.data?.length === 0 && <p className="muted">No versions in this project yet.</p>}
          {versions.data?.map((version) => (
            <div key={version.id} className="row" style={{ justifyContent: "space-between", gap: 12 }}>
              <span>
                <strong>{version.version}</strong> {version.is_latest && <span className="muted">(latest)</span>}
                <span className="muted"> · {formatDate(version.created_at)}{version.author_name ? ` · ${version.author_name}` : ""}</span>
              </span>
              <button className="btn btn-small" disabled={busy} onClick={() => importVersion(version)} title="Copy this version into the local project">
                <Icon name="download" /> Import
              </button>
            </div>
          ))}
        </div>
      )}
    </li>
  );
}

export default function CloudTab({ project, reloadProject }) {
  const status = useAsync(() => api.cloudStatus(), []);
  const connected = status.data?.connected;
  const cloudProjects = useAsync(() => (connected ? api.cloudProjects() : Promise.resolve([])), [connected]);
  const { busy, run } = useAction();

  const signOut = async () => {
    await run(() => api.cloudLogout());
    status.reload();
  };
  // A rejected session (expired token) signs the user out on the server; show the form again.
  const expired = cloudProjects.error?.status === 401;
  const reloadStatus = status.reload;
  useEffect(() => {
    if (expired && connected) reloadStatus();
  }, [expired, connected, reloadStatus]);

  if (status.loading && !status.data) return <SkeletonList rows={3} height={56} label="Checking the cloud connection" />;
  if (status.error) return <ErrorBanner error={status.error} />;
  if (!connected) {
    return (
      <>
        {expired && <Notice kind="warn">Your cloud session expired. Sign in again.</Notice>}
        <SignIn status={status.data} onDone={status.reload} />
      </>
    );
  }

  return (
    <div className="stack">
      <div className="card row" style={{ justifyContent: "space-between", gap: 12 }}>
        <span>
          <Icon name="globe" /> Connected to <strong className="break">{status.data.url}</strong>
          {status.data.user && <span className="muted"> as {status.data.user.name || status.data.user.email}</span>}
        </span>
        <button className="btn btn-small" onClick={signOut} disabled={busy}>
          <Icon name="logout" /> Sign out
        </button>
      </div>

      {cloudProjects.loading && !cloudProjects.data && <SkeletonList rows={3} height={44} label="Loading cloud projects" />}
      {cloudProjects.error && !expired && <ErrorBanner error={cloudProjects.error} />}

      {cloudProjects.data && (
        <>
          <Publish key={cloudProjects.data.length === 0 ? "empty" : "ready"} project={project} status={status.data} cloudProjects={cloudProjects.data} onPublished={() => { status.reload(); cloudProjects.reload(); }} />
          <div className="card stack">
            <div className="card-head">
              <div>
                <h3>Your cloud projects</h3>
                <p className="muted">{cloudProjects.data.length} project{cloudProjects.data.length === 1 ? "" : "s"}. Expand one to see its versions or import one locally.</p>
              </div>
              <button className="btn btn-small" onClick={cloudProjects.reload}>Refresh</button>
            </div>
            {cloudProjects.data.length === 0 ? (
              <EmptyState title="No cloud projects yet" icon="folder">
                Publishing a version can create one for you.
              </EmptyState>
            ) : (
              <ul className="stack" style={{ listStyle: "none", padding: 0, margin: 0 }}>
                {cloudProjects.data.map((p) => (
                  <CloudProject key={p.id} cloudProject={p} onImported={reloadProject} />
                ))}
              </ul>
            )}
          </div>
        </>
      )}
    </div>
  );
}
