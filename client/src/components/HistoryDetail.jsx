import { useEffect, useState } from "react";
import { api } from "../api.js";
import { ErrorBanner, Skeleton, StatusBadge } from "./ui.jsx";

const MAX_CHARS = 6000;

/** JSON is shown indented; anything else as it was stored. */
function pretty(text) {
  if (text == null || text === "") return "";
  const value = typeof text === "string" ? text : JSON.stringify(text);
  try {
    return JSON.stringify(JSON.parse(value), null, 2);
  } catch {
    return String(value);
  }
}

function Headers({ value }) {
  const entries = value && typeof value === "object" && !Array.isArray(value) ? Object.entries(value) : [];
  if (entries.length === 0) return null;
  return (
    <dl className="hd-headers">
      {entries.map(([name, header]) => (
        <div key={name}>
          <dt>{name}</dt>
          <dd>{String(header)}</dd>
        </div>
      ))}
    </dl>
  );
}

function Body({ value, label }) {
  const text = pretty(value);
  if (!text) return null;
  const cut = text.length > MAX_CHARS;
  return (
    <div className="hd-body">
      <span className="hd-label">{label}</span>
      <pre>{cut ? text.slice(0, MAX_CHARS) : text}</pre>
      {cut && <p className="muted">Showing the first {MAX_CHARS.toLocaleString()} of {text.length.toLocaleString()} characters.</p>}
    </div>
  );
}

/**
 * What was sent and what came back for one History row. The row list is deliberately light, so the details are
 * loaded when a row is opened. Secrets were redacted before they were stored.
 */
export default function HistoryDetail({ entry, projectId }) {
  const [state, setState] = useState({ loading: true, data: null, error: null });

  useEffect(() => {
    let live = true;
    setState({ loading: true, data: null, error: null });
    api
      .getRequestHistoryEntry(entry.id, projectId)
      .then((data) => live && setState({ loading: false, data, error: null }))
      .catch((error) => live && setState({ loading: false, data: null, error }));
    return () => {
      live = false;
    };
  }, [entry.id, projectId]);

  if (state.loading) return <Skeleton h={72} r={8} />;
  if (state.error) return <ErrorBanner error={state.error} />;
  const row = { ...entry, ...state.data };
  const failed = row.status == null;
  const hasDetail = ["request_headers", "request_body", "response_headers", "response_body"].some((key) => key in row);
  const errorText = row.error || (failed && typeof row.response_body === "string" ? row.response_body : "");

  return (
    <div className="hd">
      <section className="hd-col" aria-label="Request">
        <h4>Request</h4>
        <p className="hd-url">
          <strong>{row.method}</strong> <code>{row.url}</code>
        </p>
        {row.environment && <p className="muted">Environment: {row.environment}</p>}
        <Headers value={row.request_headers} />
        <Body value={row.request_body} label="Body" />
      </section>
      <section className="hd-col" aria-label="Response">
        <h4>Response</h4>
        <p className="hd-status">
          <StatusBadge status={row.status} />
          {row.durationMs != null && <span className="muted">{row.durationMs} ms</span>}
        </p>
        {failed && errorText && <p className="hd-error" role="status">{errorText}</p>}
        {failed && !errorText && <p className="muted">No response was received.</p>}
        <Headers value={row.response_headers} />
        {!failed && <Body value={row.response_body} label="Body" />}
      </section>
      {!hasDetail && <p className="muted hd-note">Local history keeps only the method, URL, status, timing and any error.</p>}
    </div>
  );
}
