import { useEffect, useMemo, useState } from "react";
import { useTesterClient } from "./tester_client.jsx";
import { maskSecretRows, maskSecrets, maskSecretsInJson, useAction, usePersistentState, useSessionState } from "../hooks.js";
import { Badge, ErrorBanner, MethodBadge, Notice, formatBytes } from "./ui.jsx";
import { Icon } from "./icons.jsx";
import { BODY_METHODS, KeyValueEditor, QueryField, ResponseView, placeholdersOf } from "./tester.jsx";
import { baseUrlError } from "../lib/project-environments.js";

/** The documented description ("comments") and permissions of an operation. */
export function OperationNotes({ endpoint }) {
  const permissions = endpoint.permissions ?? [];
  if (!endpoint.description && permissions.length === 0) return null;
  return (
    <div className="op-notes">
      {permissions.length > 0 && (
        <div className="perm-list">
          <strong>Requires</strong>
          {permissions.map((p) => (
            <Badge key={p} kind="info">
              {p}
            </Badge>
          ))}
        </div>
      )}
      {endpoint.description && (
        <div style={permissions.length > 0 ? { marginTop: 10 } : undefined}>
          <strong>Notes</strong>
          {endpoint.description}
        </div>
      )}
    </div>
  );
}

/** One file input of a multipart form. Files live in memory only (they are never saved to disk). */
function FileField({ field, files, onChange }) {
  const multiple = field.type === "array";
  return (
    <label className="file-field">
      <span className="label-line">
        <code>{field.name}</code> {field.required && <Badge kind="warn">required</Badge>} <Badge kind="neutral">file</Badge>
      </span>
      <input type="file" multiple={multiple} key={files.length} onChange={(e) => onChange([...(e.target.files ?? [])])} />
      {files.map((file, i) => (
        <span className="file-picked" key={`${file.name}-${i}`}>
          <Icon name="file" /> {file.name} <span className="muted">({formatBytes(file.size)})</span>
          <button type="button" className="btn btn-ghost btn-small" onClick={() => onChange(files.filter((_, j) => j !== i))}>
            Remove
          </button>
        </span>
      ))}
      {field.description && <small className="muted">{field.description}</small>}
    </label>
  );
}

/**
 * The request form for one operation + the last response. Handles path/query/header parameters,
 * JSON bodies and multipart/form-data (text fields and file uploads).
 * `locked` keeps the form visible but prevents sending (used for previous versions in the split view);
 * `scope` separates saved drafts of the same endpoint shown in different places.
 */
export function RequestBuilder({ project, apiToken, endpoint, locked = false, lockedReason, scope = "", showNotes = true, onSignIn }) {
  const client = useTesterClient(project, apiToken);
  const placeholders = useMemo(() => placeholdersOf(endpoint.path), [endpoint.path]);
  const endpointId = `${scope}${endpoint.method} ${endpoint.path}`;
  const isMultipart = endpoint.requestBody?.contentType === "multipart/form-data";
  const isUrlEncoded = endpoint.requestBody?.contentType === "application/x-www-form-urlencoded";
  const isForm = isMultipart || isUrlEncoded;
  const contentType = endpoint.requestBody?.contentType ?? "application/json";
  const isJson = contentType.toLowerCase().includes("json");
  const fields = useMemo(() => (isForm ? (endpoint.requestBody.fields ?? []) : []), [isForm, endpoint.requestBody]);
  // The body starts out as the documented example (or a template built from the schema); secret-looking fields are blanked on save.
  const exampleBody = useMemo(() => {
    if (endpoint.requestBody?.sample == null) return "";
    return isJson ? JSON.stringify(endpoint.requestBody.sample, null, 2) : String(endpoint.requestBody.sample);
  }, [endpoint.requestBody, isJson]);
  // The request form and the last response survive tab switches; the form also survives reloads
  // (secret-looking query values and body fields are blanked before they are saved).
  const [draft, setDraft] = usePersistentState(
    `test.${project.id}.req.${endpointId}`,
    // `sample` is the example the body started from, so an untouched body can follow a newer version's example.
    { params: {}, qvalues: {}, hvalues: {}, mvalues: {}, extra: [], query: [], body: exampleBody, sample: exampleBody },
    {
      sanitize: (d) => {
        // Purge the legacy headers list: it used to live here (masked), but header edits
        // now belong to the session-only state below and must not linger on disk.
        const { headers: _legacyHeaders, ...rest } = d;
        return {
          ...rest,
          qvalues: maskSecrets(d.qvalues ?? {}),
          hvalues: maskSecrets(d.hvalues ?? {}),
          mvalues: maskSecrets(d.mvalues ?? {}),
          extra: maskSecretRows(d.extra ?? []),
          query: maskSecretRows(d.query),
          body: maskSecretsInJson(d.body, d.body),
          // Masked the same way as the body, so an untouched body still equals it after a reload.
          ...(d.sample !== undefined && { sample: maskSecretsInJson(d.sample, d.sample) }),
        };
      },
    },
  );
  // The draft outlives the version it was made from. When the documented example changes (a new latest
  // version), an untouched body takes the new example; a body the user edited is left alone.
  // Drafts saved before `sample` existed are treated as edited ("Reset to example" still works).
  useEffect(() => {
    if (draft.sample === undefined || draft.sample === exampleBody) return;
    setDraft((d) => (d.body === d.sample ? { ...d, body: exampleBody, sample: exampleBody } : { ...d, sample: exampleBody }));
  }, [exampleBody, draft.sample, setDraft]);
  const { params, query, body } = draft;
  // Custom request headers are session-persistent: they survive reloads with this tab
  // (nothing is written to disk, so tokens and API keys entered here never touch localStorage).
  const [headers, setHeaders] = useSessionState(`test.${project.id}.hrows.${endpointId}`, draft.headers ?? []);
  const qvalues = draft.qvalues ?? {};
  const hvalues = draft.hvalues ?? {};
  const mvalues = draft.mvalues ?? {};
  const extra = draft.extra ?? [];
  const [files, setFiles] = usePersistentState(`test.${project.id}.files.${endpointId}`, {}, { memoryOnly: true });
  // Query parameters documented for this endpoint: the user only has to fill in values.
  const specQuery = useMemo(() => endpoint.parameters?.filter((p) => p.in === "query") ?? [], [endpoint.parameters]);
  const specHeaders = useMemo(() => endpoint.parameters?.filter((p) => p.in === "header") ?? [], [endpoint.parameters]);
  const set = (key) => (value) => setDraft((d) => ({ ...d, [key]: value }));
  const setQValue = (name, value) => setDraft((d) => ({ ...d, qvalues: { ...d.qvalues, [name]: value } }));
  const setHValue = (name, value) => setDraft((d) => ({ ...d, hvalues: { ...d.hvalues, [name]: value } }));
  const setMValue = (name, value) => setDraft((d) => ({ ...d, mvalues: { ...d.mvalues, [name]: value } }));
  const headerOverrides = new Map(headers.filter((header) => header.key.trim()).map((header) => [header.key.trim().toLowerCase(), header.value]));
  const [bodyError, setBodyError] = useState(null);
  const [result, setResult] = usePersistentState(`test.${project.id}.resp.${endpointId}`, null, { memoryOnly: true });
  const { busy, error, setError, run } = useAction();

  const hasBody = BODY_METHODS.includes(endpoint.method);
  const environmentError = baseUrlError(project.baseUrl);
  const missing = placeholders.filter((name) => !params[name]?.trim());
  const missingHeaders = specHeaders.filter((header) => {
    const value = headerOverrides.has(header.name.toLowerCase()) ? headerOverrides.get(header.name.toLowerCase()) : hvalues[header.name];
    return header.required && !value?.trim();
  });
  const missingFormFields = fields.filter((field) =>
    field.required && (field.file ? !(files[field.name]?.length) : !mvalues[field.name]?.trim()),
  );

  const send = async (event) => {
    event.preventDefault();
    if (locked) return;
    setBodyError(null);
    setResult(null);
    if (missingFormFields.length > 0) {
      setBodyError(`Fill in required form fields: ${missingFormFields.map((field) => field.name).join(", ")}.`);
      return;
    }

    let parsedBody;
    if (hasBody && !isForm && body.trim()) {
      try {
        parsedBody = isJson ? JSON.parse(body) : body;
      } catch {
        setBodyError("The request body is not valid JSON.");
        return;
      }
    }

    const path = endpoint.path.replace(/\{([^}]+)\}/g, (_, name) => encodeURIComponent(params[name].trim()));
    const queryObject = {};
    for (const { name } of specQuery) {
      const value = (qvalues[name] ?? "").trim();
      if (value !== "") queryObject[name] = value; // blank documented parameters are simply not sent
    }
    for (const { key, value } of query) {
      if (!key.trim()) continue;
      const k = key.trim();
      queryObject[k] = k in queryObject ? [].concat(queryObject[k], value) : value;
    }
    const headerObject = {};
    for (const header of specHeaders) {
      const value = (hvalues[header.name] ?? "").trim();
      if (value !== "") headerObject[header.name] = value;
    }
    for (const header of headers) {
      const name = header.key.trim();
      if (name) headerObject[name] = header.value;
    }

    let response;
    if (isMultipart) {
      const form = new FormData();
      form.append("request", JSON.stringify({ method: endpoint.method, path, query: queryObject, headers: headerObject }));
      for (const field of fields) {
        if (field.file) {
          for (const file of files[field.name] ?? []) form.append(field.name, file, file.name);
        } else {
          const value = (mvalues[field.name] ?? "").trim();
          if (value !== "") form.append(field.name, value);
        }
      }
      for (const { key, value } of extra) if (key.trim()) form.append(key.trim(), value);
      response = await run(() => client.executeMultipart(form));
    } else if (isUrlEncoded) {
      const formBody = {};
      for (const field of fields) {
        const value = (mvalues[field.name] ?? "").trim();
        if (value !== "") formBody[field.name] = value;
      }
      for (const { key, value } of extra) {
        if (!key.trim()) continue;
        const name = key.trim();
        formBody[name] = name in formBody ? [].concat(formBody[name], value) : value;
      }
      response = await run(() =>
        client.execute({
          method: endpoint.method,
          path,
          query: queryObject,
          headers: headerObject,
          contentType,
          body: formBody,
        }),
      );
    } else {
      response = await run(() =>
        client.execute({
          method: endpoint.method,
          path,
          query: queryObject,
          headers: headerObject,
          contentType,
          ...(parsedBody !== undefined && { body: parsedBody }),
        }),
      );
    }
    if (response) setResult(response);
  };

  const authError = error && ["LOGIN_REQUIRED", "TOKEN_EXPIRED"].includes(error.code);
  const tokenExpired = Boolean(apiToken?.expiresAt && Date.parse(apiToken.expiresAt) <= Date.now());
  const needsSignIn = endpoint.requiresAuth && project.authRequired && !locked && (!apiToken?.token || tokenExpired);
  const urlPreview = `${(project.baseUrl ?? "").replace(/\/+$/, "")}${endpoint.path.replace(/\{([^}]+)\}/g, (m, name) => params[name]?.trim() || m)}`;

  return (
    <>
      {showNotes && <OperationNotes endpoint={endpoint} />}
      <form className="rb" onSubmit={send}>
        {environmentError && <Notice kind="warn">{environmentError} Choose or configure an environment above before sending requests.</Notice>}
        {!environmentError && needsSignIn && (
          <div className="signin-note">
            <Icon name="lock" />
            <span className="grow">
              {tokenExpired ? "Your session for this environment has expired." : "This endpoint needs a token."} Sign in to send authenticated requests.
            </span>
            {onSignIn && (
              <button type="button" className="btn btn-small btn-primary" onClick={onSignIn}>
                <Icon name="key" /> Sign in
              </button>
            )}
          </div>
        )}
        {isMultipart && (
          <Notice kind="warn">
            The configured PHP request runner does not support multipart file uploads.
          </Notice>
        )}
        <div className="rb-url" title="Where this request will be sent">
          <MethodBadge method={endpoint.method} />
          <code className="break">{urlPreview}</code>
        </div>
        {locked && (
          <div className="locked-note">
            <Icon name="lock" /> <span>{lockedReason ?? "This request cannot be sent from here."}</span>
          </div>
        )}
        <fieldset className="form-fieldset rb-sections" disabled={locked}>
          {placeholders.length > 0 && (
            <section className="rb-section">
              <h5 className="rb-title">Path parameters</h5>
              <div className="rb-grid">
                {placeholders.map((name) => (
                  <label key={name}>
                    <span className="label-line">
                      <code>{name}</code> <Badge kind="warn">required</Badge>
                    </span>
                    <input value={params[name] ?? ""} onChange={(e) => set("params")({ ...params, [name]: e.target.value })} required />
                  </label>
                ))}
              </div>
            </section>
          )}

          {specQuery.length > 0 && (
            <section className="rb-section">
              <h5 className="rb-title">Query parameters</h5>
              <div className="rb-grid">
                {specQuery.map((p) => (
                  <QueryField key={p.name} param={p} value={qvalues[p.name] ?? ""} onChange={(value) => setQValue(p.name, value)} />
                ))}
              </div>
            </section>
          )}

          {specHeaders.length > 0 && (
            <section className="rb-section">
              <h5 className="rb-title">Header parameters</h5>
              <div className="rb-grid">
                {specHeaders.map((header) => (
                  <QueryField key={header.name} param={header} value={hvalues[header.name] ?? ""} onChange={(value) => setHValue(header.name, value)} />
                ))}
              </div>
            </section>
          )}

          {isForm && (
            <section className="rb-section">
              <h5 className="rb-title">
                Form data <span className="muted">{isMultipart ? "multipart/form-data" : "application/x-www-form-urlencoded"}</span>
              </h5>
              {fields.length === 0 && <p className="muted">No fields are documented for this endpoint; add the ones you need below.</p>}
              <div className="rb-grid">
                {fields.map((field) =>
                  field.file ? (
                    <FileField key={field.name} field={field} files={files[field.name] ?? []} onChange={(list) => setFiles((f) => ({ ...f, [field.name]: list }))} />
                  ) : (
                    <QueryField key={field.name} param={field} value={mvalues[field.name] ?? ""} onChange={(value) => setMValue(field.name, value)} />
                  ),
                )}
              </div>
              <details className="rb-details" open={extra.length > 0}>
                <summary>Additional text fields</summary>
                <KeyValueEditor rows={extra} onChange={set("extra")} keyLabel="Field" />
              </details>
            </section>
          )}

          {hasBody && !isForm && (
            <section className="rb-section">
              <div className="row space">
                <h5 className="rb-title" style={{ margin: 0 }}>
                  Request body <span className="muted">{contentType}</span> {endpoint.requestBody?.required && <Badge kind="warn">required</Badge>}
                </h5>
                {exampleBody && body !== exampleBody && (
                  <button type="button" className="btn btn-ghost btn-small" onClick={() => set("body")(exampleBody)}>
                    Reset to example
                  </button>
                )}
              </div>
              <textarea
                aria-label={`Request body (${contentType})`}
                rows={Math.min(18, Math.max(7, body.split("\n").length + 1))}
                value={body}
                onChange={(e) => set("body")(e.target.value)}
                placeholder={'{\n  "key": "value"\n}'}
                spellCheck={false}
              />
            </section>
          )}

          <div className="rb-extras">
            <details className="rb-details" open={query.length > 0}>
              <summary>{specQuery.length > 0 ? "Additional query parameters" : "Query parameters"}</summary>
              <KeyValueEditor rows={query} onChange={set("query")} />
            </details>
            <section className="rb-section rb-request-headers">
              <div>
                <h5 className="rb-title">Request headers</h5>
                <p className="muted rb-header-hint">
                  Add or edit headers for this request. These values override documented and automatically configured headers.
                </p>
              </div>
              <KeyValueEditor rows={headers} onChange={setHeaders} keyLabel="Header" />
            </section>
          </div>
        </fieldset>
        {bodyError && <ErrorBanner error={bodyError} />}

        {authError ? (
          <Notice kind="warn">{error.message}. Sign in from the toolbar above, then send again.</Notice>
        ) : (
          <ErrorBanner error={error} onDismiss={() => setError(null)} />
        )}

        <div className="rb-send">
          <button className="btn btn-primary" disabled={locked || busy || Boolean(environmentError) || missing.length > 0 || missingHeaders.length > 0 || missingFormFields.length > 0} title={locked ? lockedReason : environmentError ?? undefined}>
            {locked ? (
              <>
                <Icon name="lock" /> Call disabled
              </>
            ) : busy ? (
              "Sending…"
            ) : (
              <>
                <Icon name="send" /> Send request
              </>
            )}
          </button>
          {!locked && (missing.length > 0 || missingHeaders.length > 0 || missingFormFields.length > 0) && (
            <span className="muted">
              Fill in {[...missing.map((m) => `{${m}}`), ...missingHeaders.map((header) => header.name), ...missingFormFields.map((field) => field.name)].join(", ")} to send.
            </span>
          )}
        </div>
      </form>

      {result && <ResponseView result={result} />}
    </>
  );
}
