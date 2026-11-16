import { useMemo, useState } from "react";
import { api } from "../../api.js";
import { useAction, useAsync } from "../../hooks.js";
import { EmptyState, ErrorBanner, MethodBadge, SkeletonList, StatusBadge, formatDate, timeAgo } from "../../components/ui.jsx";
import { Icon } from "../../components/icons.jsx";

const STATUS_FILTERS = [
  ["", "All statuses"],
  ["2", "2xx success"],
  ["3", "3xx redirect"],
  ["4", "4xx client error"],
  ["5", "5xx server error"],
  ["none", "No response"],
];
const PAGE = 25;

function ProjectHistory({ project }) {
  const [fromTime, setFromTime] = useState(""); // datetime-local values, interpreted in the browser's time zone
  const [toTime, setToTime] = useState("");
  const history = useAsync(
    () =>
      api.getRequestHistory(project.id, 100),
    [project.id],
  );
  const [text, setText] = useState("");
  const [method, setMethod] = useState("");
  const [statusClass, setStatusClass] = useState("");
  const [visible, setVisible] = useState(PAGE);
  const { busy, error, setError, run } = useAction();

  const all = history.data ?? [];
  const total = all.length;
  const filtered = useMemo(() => {
    const needle = text.trim().toLowerCase();
    return all.filter((entry) => {
      if (method && entry.method !== method) return false;
      if (statusClass === "none" ? entry.status != null : statusClass && String(entry.status ?? "")[0] !== statusClass) return false;
      if (fromTime && Date.parse(entry.createdAt) < new Date(fromTime).getTime()) return false;
      if (toTime && Date.parse(entry.createdAt) > new Date(toTime).getTime()) return false;
      return !needle || entry.url.toLowerCase().includes(needle);
    });
  }, [all, text, method, statusClass, fromTime, toTime]);
  const filtering = Boolean(text.trim() || method || statusClass || fromTime || toTime);

  // Clearing is confirmed with the app's confirm bar, not window.confirm: it cannot silently
  // block automation and the rest of the UI stays consistent.
  const [confirmClear, setConfirmClear] = useState(false);

  const clear = async () => {
    const done = await run(async () => {
      await api.clearRequestHistory(project.id);
      return true;
    });
    if (done) {
      setVisible(PAGE);
      history.reload();
    }
  };

  const resetFilters = () => {
    setFromTime("");
    setToTime("");
    setText("");
    setMethod("");
    setStatusClass("");
  };

  return (
    <div className="card history-card">
      <div className="card-head">
        <div>
          <h3>Requests</h3>
          <p className="muted">
            {all.length > 0 ? (filtering ? `${filtered.length} of ${all.length} requests` : `${all.length} request${all.length === 1 ? "" : "s"}, newest first`) : "Sent from the Test APIs tab"}
          </p>
        </div>
        <div className="row wrap">
          <button className="btn btn-small" onClick={() => setConfirmClear(true)} disabled={total === 0}>
            <Icon name="trash" /> Clear history
          </button>
        </div>
      </div>
      {confirmClear && (
        <div className="confirm-bar" role="alertdialog" aria-label="Confirm clearing request history">
          <Icon name="alert" />
          <span className="grow">
            <strong>Delete {total} saved request{total === 1 ? "" : "s"}?</strong> This cannot be undone.
          </span>
          <button className="btn btn-danger-solid" onClick={clear} disabled={busy}>
            {busy ? "Deleting…" : "Yes, delete"}
          </button>
          <button className="btn" onClick={() => setConfirmClear(false)}>
            Cancel
          </button>
        </div>
      )}
      <ErrorBanner error={error} onDismiss={() => setError(null)} />
      {(all.length > 0 || fromTime || toTime) && (
        <div className="filter-bar">
          <div className="search-box grow">
            <Icon name="search" />
            <input type="search" placeholder="Filter by URL…" value={text} onChange={(e) => setText(e.target.value)} aria-label="Filter by URL" />
          </div>
          <select value={method} onChange={(e) => setMethod(e.target.value)} aria-label="Method">
            <option value="">All methods</option>
            {["GET", "POST", "PUT", "PATCH", "DELETE"].map((m) => (
              <option key={m}>{m}</option>
            ))}
          </select>
          <select value={statusClass} onChange={(e) => setStatusClass(e.target.value)} aria-label="Status">
            {STATUS_FILTERS.map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
          <label className="inline-label">
            From <input type="datetime-local" value={fromTime} max={toTime || undefined} onChange={(e) => setFromTime(e.target.value)} />
          </label>
          <label className="inline-label">
            To <input type="datetime-local" value={toTime} min={fromTime || undefined} onChange={(e) => setToTime(e.target.value)} />
          </label>
          {filtering && (
            <button className="btn btn-ghost btn-small" onClick={resetFilters}>
              <Icon name="close" /> Reset
            </button>
          )}
        </div>
      )}
      {history.loading && !history.data && <SkeletonList rows={4} height={44} label="Loading history" />}
      <ErrorBanner error={history.error} />
      {history.data && all.length === 0 && (
        <EmptyState title="No requests yet" icon="clock">
          Requests you send from the Test APIs tab will be listed here.
        </EmptyState>
      )}
      {all.length > 0 && filtered.length === 0 && (
        <EmptyState title="No requests match" icon="search">
          Try loosening the filters.
        </EmptyState>
      )}
      {filtered.length > 0 && (
        <div className="table-scroll">
          <table className="table table-sticky">
            <thead>
              <tr>
                <th>Method</th>
                <th>Path</th>
                <th>Status</th>
                <th>Time</th>
                <th>When</th>
              </tr>
            </thead>
            <tbody>
              {filtered.slice(0, visible).map((entry) => (
                <tr key={entry.id}>
                  <td>
                    <MethodBadge method={entry.method} />
                  </td>
                  <td className="break mono-cell">{entry.url.replace(/^https?:\/\/[^/]+/, "")}</td>
                  <td>
                    <StatusBadge status={entry.status} />
                  </td>
                  <td className="muted nowrap">{entry.durationMs != null ? `${entry.durationMs} ms` : ""}</td>
                  <td className="muted nowrap" title={formatDate(entry.createdAt)}>
                    {timeAgo(entry.createdAt)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {filtered.length > visible && (
        <button className="btn btn-small show-more" onClick={() => setVisible((v) => v + PAGE)}>
          Show more ({filtered.length - visible} older)
        </button>
      )}
    </div>
  );
}

export default function HistoryTab({ project }) {
  return <ProjectHistory project={project} />;
}
