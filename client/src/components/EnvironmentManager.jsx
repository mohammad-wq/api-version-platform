import { useEffect, useMemo, useRef, useState } from "react";
import { Icon } from "./icons.jsx";
import { ErrorBanner, Modal, Notice } from "./ui.jsx";
import { baseUrlError, environmentLabel, hostOf } from "../lib/project-environments.js";
import { useAction } from "../hooks.js";

/**
 * Modal dialog to add, edit, reorder, duplicate and delete environments.
 *
 * Opens over the tester (Postman-style) instead of permanently occupying a
 * column of the page. Edits are staged locally and shared with collaborators
 * only through Save; the footer always shows how many environments differ from
 * the saved state, and Discard reverts everything at once. Deleting asks for
 * confirmation. Only the base URL is required. Any project member — including
 * Viewers — can manage environments, because they are testing data rather than
 * project details.
 */
export default function EnvironmentManager({ session, initialId, onClose, onSaved = () => {} }) {
  const { environments, active, savedEnvironments, hasUnsavedChanges } = session;
  const [editingId, setEditingId] = useState(initialId ?? active?.id);
  const [touched, setTouched] = useState(false);
  const [confirmingDelete, setConfirmingDelete] = useState(false);
  const urlRef = useRef(null);
  // An environment created by "Add environment" that was never filled in is
  // removed on close, so cancelled additions do not linger in the list.
  const [createdId] = useState(() => {
    const savedIds = new Set((savedEnvironments ?? []).map((environment) => environment.id));
    return initialId && !savedIds.has(initialId) ? initialId : null;
  });
  const editing = environments.find((environment) => environment.id === editingId) ?? environments[0];
  const urlError = baseUrlError(editing?.baseUrl ?? "");
  const { busy, error, run } = useAction();

  const savedById = useMemo(
    () => new Map((savedEnvironments ?? []).map((environment) => [environment.id, environment])),
    [savedEnvironments],
  );
  const isModified = (environment) => {
    const saved = savedById.get(environment.id);
    return !saved || JSON.stringify(saved) !== JSON.stringify(environment);
  };
  const invalidCount = environments.filter((environment) => baseUrlError(environment.baseUrl)).length;
  const modifiedCount = environments.filter(isModified).length;
  const duplicateName = Boolean(
    editing?.name?.trim() &&
      environments.some((other) => other.id !== editing.id && other.name.trim().toLowerCase() === editing.name.trim().toLowerCase()),
  );
  const canReorder = (index) => !busy && environments.length > 1;

  // Called by Escape, the backdrop click and the close buttons; removes an
  // untouched environment created by this dialog before closing.
  const close = () => {
    const created = createdId && environments.find((environment) => environment.id === createdId);
    if (created && !created.name.trim() && !created.baseUrl.trim()) session.removeEnvironment(created.id);
    onClose();
  };

  useEffect(() => {
    setTouched(false);
    setConfirmingDelete(false);
    if (editing && !editing.baseUrl) urlRef.current?.focus();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editingId]);

  const add = () => setEditingId(session.addEnvironment());
  const duplicate = () => {
    const id = session.duplicateEnvironment(editing.id);
    if (id) setEditingId(id);
  };
  const remove = async () => {
    const next = environments.find((environment) => environment.id !== editing.id);
    const removed = await run(() => session.removeEnvironment(editing.id));
    setConfirmingDelete(false);
    if (removed && next) setEditingId(next.id);
  };
  const save = async (event) => {
    event?.preventDefault?.();
    setTouched(true);
    if (invalidCount > 0) return;
    const saved = await run(session.saveEnvironments);
    if (saved) {
      setTouched(false);
      onSaved();
    }
  };
  const onListKeyDown = (event) => {
    if (event.key !== "ArrowDown" && event.key !== "ArrowUp") return;
    const index = environments.findIndex((environment) => environment.id === editing?.id);
    const next = environments[index + (event.key === "ArrowDown" ? 1 : -1)];
    if (next) {
      event.preventDefault();
      setEditingId(next.id);
    }
  };

  return (
    <Modal onClose={close} className="env-modal" labelledBy="env-manager-title">
      <div className="env-manager">
        <header className="env-modal-head">
          <div className="grow">
            <h3 id="env-manager-title">
              Environments
              {hasUnsavedChanges && modifiedCount > 0 && (
                <span className="env-tag env-tag-unsaved" title={`${modifiedCount} environment${modifiedCount === 1 ? "" : "s"} differ from the saved state`}>
                  {modifiedCount} unsaved
                </span>
              )}
            </h3>
            <p className="muted">
              Environments are shared with project collaborators through Save. Sign-in tokens stay in this browser.
            </p>
          </div>
          <button type="button" className="icon-btn" onClick={close} aria-label="Close">
            <Icon name="close" />
          </button>
        </header>

        {invalidCount > 0 && (
          <div className="env-modal-notices">
            <Notice kind="warn">
              {invalidCount === 1 ? "One environment needs" : `${invalidCount} environments need`} a valid base URL before changes can be saved.
            </Notice>
          </div>
        )}

        <div className="env-manager-body">
          <nav className="env-manager-list" aria-label="Environments" onKeyDown={onListKeyDown}>
            {environments.map((environment, index) => {
              const selected = environment.id === editing?.id;
              const invalid = Boolean(baseUrlError(environment.baseUrl));
              return (
                <div className={`env-manager-item${selected ? " selected" : ""}${isModified(environment) ? " modified" : ""}`} key={environment.id}>
                  <button
                    type="button"
                    className="env-item-main"
                    aria-current={selected ? "true" : undefined}
                    onClick={() => setEditingId(environment.id)}
                    disabled={busy}
                  >
                    <span
                      className={`env-dot${invalid ? " env-dot-danger" : environment.id === active?.id ? " env-dot-ok" : ""}`}
                      aria-hidden="true"
                    />
                    <span className="env-option-text">
                      <span className="env-option-name">
                        {environmentLabel(environment)}
                        {environment.source === "readme" && <span className="env-tag">README</span>}
                        {environment.insecure === true && <span className="env-tag" title="Requests to this environment accept any TLS certificate">TLS check off</span>}
                        {environment.id === active?.id && <span className="env-tag env-tag-active">Active</span>}
                      </span>
                      <span className={`env-option-url${invalid ? " invalid" : ""}`}>
                        {environment.baseUrl || (invalid ? "Needs a base URL" : "No base URL")}
                      </span>
                    </span>
                    {isModified(environment) && (
                      <span className="env-mod-dot" title="Unsaved changes" aria-label="Unsaved changes" />
                    )}
                  </button>
                  <span className="env-item-tools">
                    <button
                      type="button"
                      className="icon-btn env-item-tool"
                      onClick={() => session.moveEnvironment(environment.id, -1)}
                      disabled={!canReorder(index) || index === 0}
                      aria-label={`Move ${environmentLabel(environment)} up`}
                      title="Move up"
                    >
                      <Icon name="up" />
                    </button>
                    <button
                      type="button"
                      className="icon-btn env-item-tool"
                      onClick={() => session.moveEnvironment(environment.id, 1)}
                      disabled={!canReorder(index) || index === environments.length - 1}
                      aria-label={`Move ${environmentLabel(environment)} down`}
                      title="Move down"
                    >
                      <Icon name="down" />
                    </button>
                  </span>
                </div>
              );
            })}
            <button type="button" className="btn btn-small btn-ghost env-manager-add" onClick={add} disabled={busy}>
              <Icon name="plus" /> Add environment
            </button>
          </nav>

          {editing && (
            <form className="env-manager-form stack" onSubmit={save}>
              <ErrorBanner error={error} />
              <div className="env-manager-form-title">
                <strong>{environmentLabel(editing)}</strong>
                {isModified(editing) && <span className="env-tag env-tag-unsaved">Unsaved</span>}
                {urlError && <span className="env-tag env-tag-error">Invalid</span>}
              </div>
              <label>
                <span className="label-line">
                  Base URL <small className="muted">required</small>
                </span>
                <input
                  ref={urlRef}
                  className="mono-input"
                  value={editing.baseUrl}
                  onChange={(event) => {
                    session.setBaseUrl(editing.id, event.target.value);
                  }}
                  onBlur={() => setTouched(true)}
                  placeholder="https://api.example.com"
                  spellCheck={false}
                  aria-invalid={Boolean(urlError && touched)}
                  disabled={busy}
                />
                {urlError && (touched || editing.baseUrl) ? <small className="field-error">{urlError}</small> : <small className="muted">Endpoint paths are appended to this URL.</small>}
              </label>
              <label>
                <span className="label-line">
                  Name <small className="muted">optional</small>
                </span>
                <input
                  value={editing.name}
                  onChange={(event) => {
                    session.renameEnvironment(editing.id, event.target.value);
                  }}
                  placeholder={hostOf(editing.baseUrl) || "e.g. Staging"}
                  maxLength={80}
                  aria-invalid={Boolean(duplicateName)}
                  disabled={busy}
                />
                {duplicateName ? (
                  <small className="field-error">Another environment already uses this name.</small>
                ) : (
                  <small className="muted">Shown in the toolbar and shared with collaborators.</small>
                )}
              </label>
              <label className="check">
                <input
                  type="checkbox"
                  checked={editing.insecure === true}
                  onChange={(event) => session.setInsecure(editing.id, event.target.checked)}
                  disabled={busy}
                />
                <span>
                  Skip TLS certificate check <small className="muted">for QA or internal servers with a self-signed certificate</small>
                </span>
              </label>
              {editing.insecure === true && (
                <Notice kind="warn">Requests to this environment accept any certificate. Only use this for servers you trust.</Notice>
              )}
              {editing.source === "readme" && (
                <Notice kind="info">This environment comes from the API specification. Save it to share it with collaborators.</Notice>
              )}
              <div className="env-manager-actions">
                <button
                  type="button"
                  className="btn btn-primary btn-small"
                  disabled={busy || editing.id === active?.id || Boolean(urlError)}
                  onClick={() => {
                    session.selectEnvironment(editing.id);
                    close();
                  }}
                  title={urlError ? "Set a valid base URL first" : undefined}
                >
                  <Icon name="check" /> {editing.id === active?.id ? "Active environment" : "Use this environment"}
                </button>
                <span className="grow" />
                <button type="button" className="btn btn-small" onClick={duplicate} disabled={busy}>
                  <Icon name="copy" /> Duplicate
                </button>
                {confirmingDelete ? (
                  <span className="env-confirm" role="alertdialog" aria-label="Confirm environment deletion">
                    <span>
                      Delete <strong>{environmentLabel(editing)}</strong>?
                    </span>
                    <button type="button" className="btn btn-small btn-danger" onClick={remove} disabled={busy}>
                      {busy ? "Deleting…" : "Yes, delete"}
                    </button>
                    <button type="button" className="btn btn-small" onClick={() => setConfirmingDelete(false)} disabled={busy}>
                      Cancel
                    </button>
                  </span>
                ) : (
                  <button
                    type="button"
                    className="btn btn-small btn-danger"
                    onClick={() => setConfirmingDelete(true)}
                    disabled={busy || environments.length <= 1}
                    title={environments.length <= 1 ? "At least one environment is needed" : "Delete this environment"}
                  >
                    <Icon name="trash" /> Delete
                  </button>
                )}
              </div>
            </form>
          )}
        </div>

        <footer className="env-modal-foot">
          {hasUnsavedChanges ? (
            <span className="env-unsaved-note">
              <span className="env-dot env-dot-warn" aria-hidden="true" />
              {modifiedCount} unsaved change{modifiedCount === 1 ? "" : "s"}
            </span>
          ) : (
            <span className="env-unsaved-note muted">All changes saved</span>
          )}
          <span className="grow" />
          <button
            type="button"
            className="btn"
            onClick={() => {
              session.resetEnvironments();
              setConfirmingDelete(false);
            }}
            disabled={!hasUnsavedChanges || busy}
            title="Revert every environment to the last saved state"
          >
            Discard
          </button>
          <button
            type="button"
            className="btn btn-primary"
            onClick={save}
            disabled={session.environmentsLoading || busy || invalidCount > 0 || !hasUnsavedChanges}
          >
            <Icon name="check" /> {busy ? "Saving…" : "Save changes"}
          </button>
          <button type="button" className="btn" onClick={close}>
            Done
          </button>
        </footer>
      </div>
    </Modal>
  );
}
