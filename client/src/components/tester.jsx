import { useState } from "react";
import { Badge, CopyButton, JsonView, MethodBadge, Notice, StatusBadge, formatBytes } from "./ui.jsx";
import { Icon } from "./icons.jsx";

export const BODY_METHODS = ["POST", "PUT", "PATCH", "DELETE"];

export const placeholdersOf = (path) => [...path.matchAll(/\{([^}]+)\}/g)].map((m) => m[1]);

export function KeyValueEditor({ rows, onChange, keyLabel = "Name", valueLabel = "Value" }) {
  const update = (index, patch) => onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
  return (
    <div className="kv">
      {rows.map((row, index) => (
        <div className="kv-row" key={index}>
          <input value={row.key} placeholder={keyLabel} onChange={(e) => update(index, { key: e.target.value })} />
          <input value={row.value} placeholder={valueLabel} onChange={(e) => update(index, { value: e.target.value })} />
          <button type="button" className="icon-btn" onClick={() => onChange(rows.filter((_, i) => i !== index))} aria-label="Remove row">
            <Icon name="close" />
          </button>
        </div>
      ))}
      <button type="button" className="btn btn-small" onClick={() => onChange([...rows, { key: "", value: "" }])}>
        <Icon name="plus" /> Add
      </button>
    </div>
  );
}

/** One documented query parameter: a dropdown for enums/booleans, a text box otherwise. */
export function QueryField({ param, value, onChange }) {
  const options = param.enum ?? (param.type === "boolean" ? ["true", "false"] : null);
  const hint = [param.type, param.format].filter(Boolean).join(" · ");
  const placeholder = param.example != null ? String(param.example) : param.default != null ? `default: ${param.default}` : (hint || "value");
  return (
    <label>
      <span className="label-line">
        <code>{param.name}</code> {param.required && <Badge kind="warn">required</Badge>} {hint && <small className="muted">{hint}</small>}
      </span>
      {options ? (
        <select value={value} onChange={(e) => onChange(e.target.value)}>
          <option value="">{param.default != null ? `(default: ${param.default})` : "(not set)"}</option>
          {options.map((o) => (
            <option key={String(o)} value={String(o)}>
              {String(o)}
            </option>
          ))}
        </select>
      ) : (
        <input value={value} placeholder={placeholder} inputMode={param.type === "integer" || param.type === "number" ? "numeric" : undefined} onChange={(e) => onChange(e.target.value)} />
      )}
      {param.description && <small className="muted">{param.description}</small>}
    </label>
  );
}


const sizeOf = (body) => {
  if (body == null || body === "") return 0;
  return new Blob([typeof body === "string" ? body : JSON.stringify(body)]).size;
};

function downloadResponse(response) {
  const contentType = String(response.headers["content-type"] ?? response.contentType ?? "application/octet-stream").split(";")[0];
  let body;
  if (response.bodyBase64) {
    const binary = atob(response.bodyBase64);
    const bytes = new Uint8Array(binary.length);
    for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);
    body = new Blob([bytes], { type: contentType });
  } else {
    body = new Blob([typeof response.body === "string" ? response.body : JSON.stringify(response.body ?? "")], { type: contentType });
  }
  const extension = /(?:^|\/)csv$|comma-separated-values/i.test(contentType)
    ? "csv"
    : /\/pdf$/i.test(contentType)
      ? "pdf"
      : /\/(?:zip|gzip|x-7z-compressed|x-rar-compressed)$/i.test(contentType)
        ? "zip"
        : /\/png$/i.test(contentType)
          ? "png"
          : /\/jpe?g$/i.test(contentType)
            ? "jpg"
            : "bin";
  const filename = response.downloadFilename || `response.${extension}`;
  const url = URL.createObjectURL(body);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 0);
}

export function ResponseView({ result }) {
  const [tab, setTab] = useState("body");
  const { request, response, specVersion } = result;
  const body = response.body;
  const isBinary = typeof response.bodyBase64 === "string";
  const isEmpty = (body === "" || body == null) && !isBinary;
  const kind = response.status >= 500 ? "5xx" : response.status >= 400 ? "4xx" : response.status >= 300 ? "3xx" : "2xx";
  const copyText = () => {
    const value = tab === "body" ? body : response.headers;
    return typeof value === "string" ? value : JSON.stringify(value, null, 2);
  };

  return (
    <section className={`response response-${kind}`} aria-label="Response">
      <div className="response-bar">
        <span className="response-title">Response</span>
        <StatusBadge status={response.status} />
        {response.statusText && <span className="muted">{response.statusText}</span>}
        <span className="response-stat" title="Round-trip time">
          <Icon name="clock" /> {response.durationMs} ms
        </span>
        <span className="response-stat" title="Body size">
          <Icon name="file" /> {formatBytes(response.bodySize ?? sizeOf(body))}
        </span>
        <span className="grow" />
        <Badge kind="neutral" title="The spec version this request was built from">
          version {specVersion.versionNumber}
        </Badge>
      </div>
      <div className="response-url">
        <MethodBadge method={request.method} />
        <code className="break">{request.url}</code>
      </div>
      <div className="response-body">
        {response.truncated && <Notice kind="warn">The response was larger than 5 MB and has been cut off.</Notice>}
        <div className="row space wrap">
          <div className="seg-tabs" role="group" aria-label="Response view">
            <button type="button" className={tab === "body" ? "active" : ""} aria-pressed={tab === "body"} onClick={() => setTab("body")}>
              Body
            </button>
            <button type="button" className={tab === "headers" ? "active" : ""} aria-pressed={tab === "headers"} onClick={() => setTab("headers")}>
              Headers <span className="count">{Object.keys(response.headers).length}</span>
            </button>
          </div>
          <div className="row">
            {tab === "body" && response.downloadable && (
              <button type="button" className="btn btn-small" disabled={response.truncated} onClick={() => downloadResponse(response)}>
                <Icon name="download" /> {response.truncated ? "Download unavailable (truncated)" : "Download response"}
              </button>
            )}
            {!(tab === "body" && isBinary) && <CopyButton text={copyText} label={tab === "body" ? "Copy body" : "Copy headers"} />}
          </div>
        </div>
        {tab === "body" ? isBinary ? (
          <p className="muted">Binary response ({formatBytes(response.bodySize ?? 0)}). Download the response to view it.</p>
        ) : isEmpty ? <p className="muted">(empty body)</p> : <JsonView value={body} maxHeight={480} searchable /> : <JsonView value={response.headers} maxHeight={480} />}
      </div>
    </section>
  );
}
