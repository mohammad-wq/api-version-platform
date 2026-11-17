import { useEffect, useState } from "react";
import { api } from "../../api.js";
import { useAction, useAsync } from "../../hooks.js";
import { EmptyState, ErrorBanner, Notice, SkeletonList } from "../../components/ui.jsx";
import { Icon } from "../../components/icons.jsx";

function SignIn({ onDone }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const { busy, error, run } = useAction();

  const submit = async (event) => {
    event.preventDefault();
    const result = await run(() => api.cloudLogin({ email, password }));
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
          <p className="muted">Sign in with your account on the hosted platform to publish this project's versions.</p>
        </div>
      </div>
      <ErrorBanner error={error} />
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
      <div>
        <button className="btn btn-primary" disabled={busy || !email || !password}>
          <Icon name="key" /> {busy ? "Signing in…" : "Sign in"}
        </button>
      </div>
    </form>
  );
}

function Publish({ project, onSessionEnded }) {
  const versions = project.versions ?? [];
  const [versionId, setVersionId] = useState(String(versions.find((v) => v.isLatest)?.id ?? versions[0]?.id ?? ""));
  const [label, setLabel] = useState("");
  const [note, setNote] = useState("");
  const { busy, error, setError, run } = useAction();
  const [done, setDone] = useState(null);

  const selected = versions.find((v) => String(v.id) === versionId);
  const submit = async (event) => {
    event.preventDefault();
    setDone(null);
    const result = await run(() => api.cloudPublish({ versionId: Number(versionId), version: label, note }));
    if (result) {
      setDone(result);
      setLabel("");
    }
  };
  // The server drops a rejected sign-in; show the form again.
  useEffect(() => {
    if (error?.status === 401) onSessionEnded();
  }, [error, onSessionEnded]);

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
          <p className="muted">
            Publishes to the cloud project named <strong>{project.name}</strong> (created if it does not exist yet).
          </p>
        </div>
      </div>
      <ErrorBanner error={error} onDismiss={() => setError(null)} />
      {done && (
        <Notice kind="ok" onDismiss={() => setDone(null)}>
          Published <strong>{done.version}</strong> to <strong>{done.project.name}</strong>
          {done.projectCreated ? " (new cloud project)" : ""}.
        </Notice>
      )}
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
        <button className="btn btn-primary" disabled={busy || !versionId}>
          <Icon name="upload" /> {busy ? "Publishing…" : "Publish to cloud"}
        </button>
      </div>
    </form>
  );
}

export default function CloudTab({ project }) {
  const status = useAsync(() => api.cloudStatus(), []);
  const { busy, run } = useAction();

  const signOut = async () => {
    await run(() => api.cloudLogout());
    status.reload();
  };

  if (status.loading && !status.data) return <SkeletonList rows={3} height={56} label="Checking the cloud connection" />;
  if (status.error) return <ErrorBanner error={status.error} />;
  if (!status.data?.connected) return <SignIn onDone={status.reload} />;

  return (
    <div className="stack">
      <div className="card row" style={{ justifyContent: "space-between", gap: 12 }}>
        <span>
          <Icon name="user" /> Signed in
          {status.data.user ? (
            <>
              {" "}as <strong>{status.data.user.name || status.data.user.email}</strong>
            </>
          ) : null}
        </span>
        <button className="btn btn-small" onClick={signOut} disabled={busy}>
          <Icon name="logout" /> Sign out
        </button>
      </div>
      <Publish project={project} onSessionEnded={status.reload} />
    </div>
  );
}
