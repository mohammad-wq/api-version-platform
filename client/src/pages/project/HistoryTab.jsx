import { Fragment, useState } from "react";
import { api } from "../../api.js";
import { useAction, useAsync } from "../../hooks.js";
import { EmptyState, ErrorBanner, MethodBadge, SkeletonList, StatusBadge, formatDate, timeAgo } from "../../components/ui.jsx";
import { Icon } from "../../components/icons.jsx";
import HistoryDetail from "../../components/HistoryDetail.jsx";

/** The URL without its scheme and host, as shown in the Path column. */
const pathOf = (url) => String(url ?? "").replace(/^https?:\/\/[^/]+/, "");

export default function HistoryTab({ project }) {
  const history = useAsync(() => api.getRequestHistory(project.id, 100), [project.id]);
  const { busy, error, setError, run } = useAction();
  const [confirmClear, setConfirmClear] = useState(false);
  const [openId, setOpenId] = useState(null);
  const all = history.data ?? [];

  const clear = async () => {
    const done = await run(async () => {
      await api.clearRequestHistory(project.id);
      return true;
    });
    if (done) {
      setConfirmClear(false);
      history.reload();
    }
  };

  return (
    <div className="card history-card">
      <div className="card-head">
        <div>
          <h3>History</h3>
          <p className="muted">{all.length > 0 ? `${all.length} request${all.length === 1 ? "" : "s"}, newest first` : "Sent from the Test APIs tab"}</p>
        </div>
        <button className="btn btn-small" onClick={() => setConfirmClear(true)} disabled={all.length === 0}>
          <Icon name="trash" /> Clear history
        </button>
      </div>
      {confirmClear && (
        <div className="confirm-bar" role="alertdialog" aria-label="Confirm clearing request history">
          <Icon name="alert" />
          <span className="grow">
            <strong>Delete {all.length} saved request{all.length === 1 ? "" : "s"}?</strong> This cannot be undone.
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
      {history.loading && !history.data && <SkeletonList rows={4} height={44} label="Loading history" />}
      <ErrorBanner error={history.error} />
      {history.data && all.length === 0 && (
        <EmptyState title="No requests yet" icon="clock">
          Requests you send from the Test APIs tab will be listed here.
        </EmptyState>
      )}
      {all.length > 0 && (
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
              {all.map((entry) => (
                <Fragment key={entry.id}>
                <tr className={`history-row${openId === entry.id ? " open" : ""}`} onClick={() => setOpenId(openId === entry.id ? null : entry.id)}>
                  <td>
                    <button
                      type="button"
                      className="history-toggle"
                      aria-expanded={openId === entry.id}
                      aria-label={`${openId === entry.id ? "Hide" : "Show"} details for ${entry.method} ${pathOf(entry.url)}`}
                      onClick={(event) => {
                        event.stopPropagation();
                        setOpenId(openId === entry.id ? null : entry.id);
                      }}
                    >
                      <Icon name="chevron" size="14px" />
                      <MethodBadge method={entry.method} />
                    </button>
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
                {openId === entry.id && (
                  <tr className="history-detail-row">
                    <td colSpan={5}>
                      <HistoryDetail entry={entry} projectId={project.id} />
                    </td>
                  </tr>
                )}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
