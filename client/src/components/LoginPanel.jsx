import { useEffect, useLayoutEffect, useRef, useState } from "react";
import { useTesterClient } from "./tester_client.jsx";
import { maskSecretsInJson, useAction, usePersistentState } from "../hooks.js";
import { CopyButton, ErrorBanner, JsonView, MethodBadge, Notice, formatDate, timeAgo } from "./ui.jsx";
import { Icon } from "./icons.jsx";
import ApiAuthSettings from "./ApiAuthSettings.jsx";
import { environmentStatus } from "./EnvironmentSwitcher.jsx";
import { baseUrlError, environmentLabel } from "../lib/project-environments.js";

// Shown only when the README documents no login call: an empty body to fill in, not a guess at field names.
const DEFAULT_LOGIN = "{}";
// What older versions pre-filled; a saved draft that still holds it is replaced by the empty body.
const OLD_DEFAULT_LOGIN = '{\n  "username": "",\n  "password": ""\n}';

const maskToken = (token) => (token.length > 18 ? `${token.slice(0, 10)}…${token.slice(-6)}` : "••••••");

const templateFor = (fields) =>
  fields.length ? JSON.stringify(Object.fromEntries(fields.map((field) => [field.name, field.type === "number" ? 0 : field.type === "boolean" ? false : ""])), null, 2) : DEFAULT_LOGIN;

function bodyFromFields(fields, values) {
  const body = {};
  for (const field of fields) {
    const value = values[field.name];
    if (field.type === "boolean") body[field.name] = Boolean(value);
    else if (field.type === "number" && value !== "" && value != null && !Number.isNaN(Number(value))) body[field.name] = Number(value);
    else if (value !== "" && value != null) body[field.name] = value;
  }
  return body;
}

const BUTTON = {
  public: { kind: "none", icon: "lock", label: "No login required" },
  active: { kind: "ok", icon: "check", label: "Signed in" },
  expired: { kind: "warn", icon: "key", label: "Sign in again" },
  none: { kind: "primary", icon: "key", label: "Sign in" },
  invalid: { kind: "off", icon: "key", label: "Sign in" },
};

/**
 * Toolbar sign-in button and panel for the API under test. The login call, token location and header
 * come from the README unless overridden; the token is kept for this browser session and environment only.
 */
export default function LoginPanel({ project, session, open, onOpenChange, onSaved }) {
  const { auth, detected, apiToken, setApiToken, runtimeProject, active } = session;
  const client = useTesterClient(runtimeProject, apiToken);
  const ref = useRef(null);
  const [panelMaxHeight, setPanelMaxHeight] = useState(undefined);
  const { busy, error, setError, run } = useAction();
  const [parseError, setParseError] = useState(null);
  const [raw, setRaw] = usePersistentState(`project.${project.id}.login.raw`, false);
  const fields = auth.loginFields ?? [];
  const useFields = fields.length > 0 && !raw;
  const secretNames = new Set(fields.filter((field) => field.secret).map((field) => field.name));
  const [values, setValues] = usePersistentState(`project.${project.id}.login.fields`, {}, {
    sanitize: (current) => Object.fromEntries(Object.entries(current ?? {}).filter(([name]) => !secretNames.has(name))),
  });
  const [payload, setPayload] = usePersistentState(`project.${project.id}.login`, () => templateFor(fields), {
    sanitize: (text) => maskSecretsInJson(text, DEFAULT_LOGIN),
  });
  const [showSettings, setShowSettings] = useState(false);
  useEffect(() => {
    if (payload === OLD_DEFAULT_LOGIN) setPayload(templateFor(fields));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!open) return undefined;
    const onDown = (event) => ref.current && !ref.current.contains(event.target) && onOpenChange(false);
    const onKey = (event) => event.key === "Escape" && onOpenChange(false);
    document.addEventListener("mousedown", onDown);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onDown);
      document.removeEventListener("keydown", onKey);
    };
  }, [open, onOpenChange]);

  // The toolbar is sticky, so the available space below its sign-in button varies
  // with the viewport. Measure it instead of assuming a fixed top-bar height.
  useLayoutEffect(() => {
    if (!open) return undefined;
    const updateHeight = () => {
      const bottom = ref.current?.getBoundingClientRect().bottom;
      if (bottom == null) return;
      setPanelMaxHeight(Math.max(0, window.innerHeight - bottom - 36));
    };
    updateHeight();
    window.addEventListener("resize", updateHeight);
    window.addEventListener("scroll", updateHeight, true);
    return () => {
      window.removeEventListener("resize", updateHeight);
      window.removeEventListener("scroll", updateHeight, true);
    };
  }, [open]);

  const status = environmentStatus(session);
  const button = BUTTON[status];
  const invalidUrl = baseUrlError(active?.baseUrl ?? "");
  const configured = !invalidUrl && Boolean(auth.loginPath) && Boolean(auth.tokenPath);
  const envName = environmentLabel(active);
  const upstream = error?.code === "LOGIN_FAILED" || error?.code === "TOKEN_NOT_FOUND" ? error.details?.upstream?.body ?? error.details : null;

  const login = async (event) => {
    event.preventDefault();
    setParseError(null);
    let body;
    if (useFields) {
      body = bodyFromFields(fields, values);
    } else {
      try {
        body = JSON.parse(payload);
        if (!body || typeof body !== "object" || Array.isArray(body)) throw new Error("must be an object");
      } catch {
        setParseError("The login payload must be a valid JSON object.");
        return;
      }
    }
    const ok = await run(async () => {
      const response = await client.login(body);
      setApiToken({ token: response.token, expiresAt: response.expiresAt ?? null, signedInAt: new Date().toISOString() });
      return true;
    });
    if (ok) {
      setValues(Object.fromEntries(Object.entries(values).filter(([name]) => !secretNames.has(name))));
      setPayload(maskSecretsInJson(payload, templateFor(fields)));
      onOpenChange(false);
    }
  };

  return (
    <div className="menu-wrap login-wrap" ref={ref}>
      <button
        type="button"
        className={`signin-btn signin-${button.kind}`}
        onClick={() => onOpenChange(!open)}
        aria-expanded={open}
        aria-haspopup="dialog"
        title={status === "active" && apiToken?.expiresAt ? `Token valid until ${formatDate(apiToken.expiresAt)}` : undefined}
      >
        <Icon name={button.icon} /> {button.label}
        <Icon name="down" className="chip-caret" />
      </button>
      {open && (
        <div className="popover login-panel" role="dialog" aria-label="Sign in to the API" style={panelMaxHeight === undefined ? undefined : { maxHeight: panelMaxHeight }}>
          <div className="popover-head">
            <h4>{auth.authRequired ? `Sign in to ${envName}` : "Authentication"}</h4>
            <button type="button" className="icon-btn" onClick={() => onOpenChange(false)} aria-label="Close">
              <Icon name="close" />
            </button>
          </div>

          <div className="login-panel-body">
          {auth.authRequired ? (
            <>
              <div className="login-flow" aria-label="How the token is obtained">
                <span className="login-flow-step">
                  <MethodBadge method={auth.loginMethod} />
                  <code>{auth.loginPath || "login path not set"}</code>
                </span>
                <Icon name="chevron" className="login-flow-arrow" />
                <span className="login-flow-step">
                  <span className="muted">token at</span> <code>{auth.tokenPath || "?"}</code>
                </span>
                <Icon name="chevron" className="login-flow-arrow" />
                <span className="login-flow-step">
                  <code>
                    {auth.headerName}: {auth.headerFormat.replace("{token}", "…")}
                  </code>
                </span>
                <span className={`env-tag login-source-${auth.source}`}>
                  {auth.source === "custom" ? "Custom" : auth.source === "readme" ? "Detected from README" : "Not documented"}
                </span>
              </div>

              {status === "active" && (
                <div className="token-card">
                  <Icon name="checkCircle" className="token-card-icon" />
                  <div className="grow">
                    <strong>Signed in to {envName}</strong>
                    <div className="muted token-card-meta">
                      <code>{maskToken(apiToken.token)}</code>
                      {apiToken.expiresAt ? ` · expires ${timeAgo(apiToken.expiresAt)}` : " · no expiry reported"}
                    </div>
                  </div>
                  <CopyButton text={() => apiToken.token} label="Copy" className="btn btn-small btn-ghost" />
                  <button type="button" className="btn btn-small" onClick={() => setApiToken(null)}>
                    <Icon name="logout" /> Sign out
                  </button>
                </div>
              )}
              {status === "expired" && <Notice kind="warn">The token for {envName} has expired. Sign in again to continue.</Notice>}

              {invalidUrl ? (
                <Notice kind="warn">Set a valid base URL for {envName} before signing in.</Notice>
              ) : !configured ? (
                <Notice kind="warn">
                  No login call was found in the README. Document it as a POST whose Request JSON has a password field (a path with login or sign-in, marked Auth: false, is found fastest), or set the login path and body below.
                </Notice>
              ) : null}

              <form onSubmit={login} className="stack login-form">
                {useFields ? (
                  fields.map((field) => (
                    <label key={field.name} className={field.type === "boolean" ? "check" : undefined}>
                      {field.type === "boolean" ? (
                        <>
                          <input
                            type="checkbox"
                            checked={Boolean(values[field.name])}
                            onChange={(event) => setValues({ ...values, [field.name]: event.target.checked })}
                            disabled={!configured}
                          />
                          <code>{field.name}</code>
                        </>
                      ) : (
                        <>
                          <span className="label-line">
                            <code>{field.name}</code>
                            {field.description && <small className="muted">{field.description}</small>}
                          </span>
                          <input
                            type={field.type === "number" ? "number" : "text"}
                            value={values[field.name] ?? ""}
                            onChange={(event) => setValues({ ...values, [field.name]: event.target.value })}
                            placeholder={field.placeholder}
                            autoComplete={field.secret ? "off" : "username"}
                            spellCheck={false}
                            disabled={!configured}
                          />
                        </>
                      )}
                    </label>
                  ))
                ) : (
                  <label>
                    <span className="label-line">
                      Request body <small className="muted">JSON sent to the login endpoint</small>
                    </span>
                    <textarea rows={5} className="mono-input" value={payload} onChange={(event) => setPayload(event.target.value)} spellCheck={false} disabled={!configured} />
                  </label>
                )}
                {parseError && <ErrorBanner error={parseError} />}
                <ErrorBanner error={error} onDismiss={() => setError(null)} />
                {upstream != null && (
                  <details className="login-upstream">
                    <summary>Response from the API</summary>
                    <JsonView value={upstream} maxHeight={200} />
                  </details>
                )}
                <div className="row login-actions">
                  <button className="btn btn-primary" disabled={busy || !configured}>
                    {busy ? "Signing in…" : status === "active" ? "Sign in again" : `Sign in to ${envName}`}
                  </button>
                  {fields.length > 0 && (
                    <button type="button" className="btn btn-small btn-ghost" onClick={() => setRaw(!raw)}>
                      <Icon name="braces" /> {raw ? "Use form fields" : "Raw JSON"}
                    </button>
                  )}
                </div>
                <p className="muted login-note">The token is kept until this tab is closed. Passwords are never stored.</p>
              </form>
            </>
          ) : (
            <p className="muted">No endpoint in the latest version requires authentication, so requests are sent without a token.</p>
          )}

          <div className="login-settings">
            <button type="button" className="login-settings-toggle" aria-expanded={showSettings} onClick={() => setShowSettings(!showSettings)}>
              <Icon name="chevron" className={`chevron${showSettings ? " open" : ""}`} /> Login settings
              {auth.source === "custom" && <span className="env-tag">Custom</span>}
            </button>
            {showSettings && <ApiAuthSettings key={project.authOverrides ? "custom" : "readme"} project={project} detected={detected} onSaved={onSaved} />}
          </div>
          </div>
        </div>
      )}
    </div>
  );
}
