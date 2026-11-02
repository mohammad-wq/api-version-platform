import { useEffect, useMemo, useRef, useState } from "react";
import { api } from "../../api.js";
import { useAction, useAsync, usePersistentState } from "../../hooks.js";
import { Avatar, Badge, EmptyState, ErrorBanner, JsonView, Modal, Notice, Skeleton, SkeletonList, formatBytes, formatDate, timeAgo } from "../../components/ui.jsx";
import { Icon } from "../../components/icons.jsx";
import { FindBar, findShortcut, useFind } from "../../components/find.jsx";
import DiffView from "../../components/DiffView.jsx";
import { MAX_OPENAPI_IMPORT_BYTES, prepareOpenApiImport, validateOpenApiDocument } from "../../lib/openapi_import.js";
import { isVersionTaken, matchesVersionTemplate, suggestNextVersion } from "../../lib/version-number.js";

/** One "jump to" entry per path of an OpenAPI document, landing on its `"/path": {` key. */
function jumpOptionsOf(spec) {
  return Object.entries(spec?.paths ?? {}).map(([path, item]) => {
    const methods = Object.keys(item ?? {})
      .filter((k) => ["get", "put", "post", "delete", "patch", "options", "head"].includes(k))
      .map((k) => k.toUpperCase());
    return { label: `${path}${methods.length ? ` (${methods.join(", ")})` : ""}`, query: `"${path}": {` };
  });
}

const ACCEPTED = /\.(?:json|md|markdown)$/i;

/** Click-to-browse or drag-and-drop target for Markdown or OpenAPI JSON files. */
function Dropzone({ file, onFile, inputRef }) {
  const [drag, setDrag] = useState(false);
  const [problem, setProblem] = useState(null);

  const take = (picked) => {
    if (!picked) return;
    if (!ACCEPTED.test(picked.name)) {
      setProblem(`"${picked.name}" is not a supported .md, .markdown, or .json file.`);
      return;
    }
    setProblem(null);
    onFile(picked);
  };
  const clear = (event) => {
    event.stopPropagation();
    onFile(null);
    setProblem(null);
    if (inputRef.current) inputRef.current.value = "";
  };
  const browse = () => inputRef.current?.click();

  return (
    <div>
      <div
        className={`dropzone${drag ? " drag" : ""}${file ? " has-file" : ""}`}
        role="button"
        tabIndex={0}
        aria-label="Choose a file or drop it here"
        onClick={browse}
        onKeyDown={(e) => (e.key === "Enter" || e.key === " ") && (e.preventDefault(), browse())}
        onDragOver={(e) => {
          e.preventDefault();
          setDrag(true);
        }}
        onDragLeave={() => setDrag(false)}
        onDrop={(e) => {
          e.preventDefault();
          setDrag(false);
          take(e.dataTransfer.files?.[0]);
        }}
      >
        {file ? (
          <div className="dz-file">
            <span className="dz-file-icon">
              <Icon name={/\.json$/i.test(file.name) ? "braces" : "file"} size="22px" />
            </span>
            <div className="grow">
              <strong className="truncate">{file.name}</strong>
              <span className="muted">{formatBytes(file.size)} · kept while you switch tabs · click to replace</span>
            </div>
            <button type="button" className="btn btn-small btn-ghost" onClick={clear}>
              <Icon name="close" /> Remove
            </button>
          </div>
        ) : (
          <div className="dz-empty">
            <span className="dz-icon">
              <Icon name="upload" size="22px" />
            </span>
            <strong>{drag ? "Drop to select this file" : "Drag a file here, or click to browse"}</strong>
            <span className="muted">README Markdown (.md, .markdown) or OpenAPI JSON (.json)</span>
          </div>
        )}
      </div>
      <input ref={inputRef} type="file" accept=".md,.markdown,.json,text/markdown,application/json" hidden onChange={(e) => take(e.target.files?.[0])} tabIndex={-1} />
      {problem && <Notice kind="warn" onDismiss={() => setProblem(null)}>{problem}</Notice>}
    </div>
  );
}

function ImportPanel({ projectId, versions = [], onImported }) {
  // Drafts survive tab switches and reloads. The chosen File can only be kept in memory.
  const [mode, setMode] = usePersistentState(`ver.${projectId}.mode`, "file");
  const [file, setFile] = usePersistentState(`ver.${projectId}.file`, null, { memoryOnly: true });
  const [text, setText] = usePersistentState(`ver.${projectId}.text`, "");
  // Version number and note need no persistence: the number is re-suggested from the version list
  // on every mount, so a version saved elsewhere never leaves this form with a stale suggestion.
  const [versionNumber, setVersionNumber] = useState("");
  const [versionNumberTouched, setVersionNumberTouched] = useState(false);
  const [note, setNote] = useState("");
  const [prepared, setPrepared] = useState(null);
  const [reviewText, setReviewText] = useState("");
  const [result, setResult] = useState(null);
  const fileInput = useRef(null);
  const { busy, error, setError, run } = useAction();

  const existingNames = useMemo(() => versions.map((version) => version.versionNumber), [versions]);
  // The first version may be named freely; every later one is suggested in that initial version's
  // format with a numeric increment, so names stay consistent across the project.
  const suggestion = useMemo(
    () => suggestNextVersion([...versions].reverse().map((version) => version.versionNumber)),
    [versions],
  );
  const lastSuggestedNumber = useRef();
  // Put a newly calculated suggestion into the field once, but never put it back after the
  // author has edited or cleared the field. It is guidance, not a lock.
  useEffect(() => {
    if (suggestion === lastSuggestedNumber.current) return;
    lastSuggestedNumber.current = suggestion;
    if (!versionNumberTouched) setVersionNumber(suggestion ?? "");
  }, [suggestion, versionNumberTouched, setVersionNumber]);
  const draftNumber = versionNumber.trim();
  const effectiveNumber = draftNumber;
  const duplicateNumber = isVersionTaken(effectiveNumber, existingNames);
  const versionTemplate = versions.length ? versions[versions.length - 1]?.versionNumber : "";
  // The first saved version establishes the project-wide naming template. A mismatch is allowed,
  // but visibly called out so authors can correct accidental inconsistencies.
  const namingMismatch = Boolean(draftNumber && versionTemplate && !matchesVersionTemplate(draftNumber, versionTemplate));

  const ready = mode === "file" ? Boolean(file) : text.trim().length > 0;
  let reviewedOpenApi = null;
  let reviewSyntaxError = null;
  try {
    reviewedOpenApi = JSON.parse(reviewText);
  } catch (parseError) {
    reviewSyntaxError = parseError.message;
  }
  const reviewErrors = reviewSyntaxError
    ? [`Invalid JSON: ${reviewSyntaxError}`]
    : validateOpenApiDocument(reviewedOpenApi);

  const resetPrepared = () => {
    setPrepared(null);
    setReviewText("");
    setResult(null);
    setError(null);
  };

  const prepare = async (event) => {
    event.preventDefault();
    resetPrepared();
    const preparedImport = await run(async () => {
      if (mode === "file" && file.size > MAX_OPENAPI_IMPORT_BYTES) throw new Error("Input must not exceed 5 MB.");
      const source = mode === "file" ? await file.text() : text;
      return prepareOpenApiImport(source, { filename: mode === "file" ? file.name : "", parseMarkdown: api.parseReadme });
    });
    if (preparedImport) {
      setPrepared(preparedImport);
      setReviewText(JSON.stringify(preparedImport.openapi, null, 2));
    }
  };

  const save = async (event) => {
    event.preventDefault();
    if (!prepared || reviewErrors.length > 0 || !effectiveNumber) return;
    // Names must be unique within the project (the backend also answers 409 for duplicates).
    if (duplicateNumber) {
      setError(`Version “${effectiveNumber}” already exists in this project. Choose a different name.`);
      return;
    }
    const version = await run(() =>
      api.createVersion({
        projectId,
        openapiJson: reviewedOpenApi,
        versionNumber: effectiveNumber,
        ...(note.trim() && { note: note.trim() }),
      }),
    );
    if (version) {
      const imported = {
        ...version,
        parse: prepared.sourceType === "markdown" ? { statistics: prepared.statistics } : null,
      };
      setResult(imported);
      setPrepared(null);
      setReviewText("");
      setFile(null);
      setText("");
      setNote("");
      setVersionNumber("");
      setVersionNumberTouched(false);
      if (fileInput.current) fileInput.current.value = "";
      onImported(imported);
    }
  };

  return (
    <form className="card import-card" onSubmit={prepared ? save : prepare}>
      <div className="card-head">
        <div>
          <h3>Add a new version</h3>
          <p className="muted">
            Upload or paste README Markdown to generate an OpenAPI specification, or import an OpenAPI JSON file. Review and validate it before saving.
          </p>
        </div>
        <div className="segmented" role="group" aria-label="Source">
          <button type="button" className={mode === "file" ? "active" : ""} aria-pressed={mode === "file"} onClick={() => { resetPrepared(); setMode("file"); }}>
            <Icon name="upload" /> Upload file
          </button>
          <button type="button" className={mode === "text" ? "active" : ""} aria-pressed={mode === "text"} onClick={() => { resetPrepared(); setMode("text"); }}>
            <Icon name="file" /> Paste text
          </button>
        </div>
      </div>

      {mode === "file" ? (
        <Dropzone file={file} onFile={(nextFile) => { resetPrepared(); setFile(nextFile); }} inputRef={fileInput} />
      ) : (
        <label>
          <span className="label-line">
            README Markdown or OpenAPI JSON <small className="muted">{text.length ? `${text.split("\n").length} lines` : "paste README content or JSON"}</small>
          </span>
          <textarea
            rows={10}
            value={text}
            onChange={(e) => { resetPrepared(); setText(e.target.value); }}
            placeholder={'# My API\n\nDescribe the API here.\n\n## Accounts — `accounts/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `fetch/getAccounts.php` | List accounts |\n\nOr paste an OpenAPI JSON object.'}
            spellCheck={false}
          />
        </label>
      )}

      <div className="form-row">
        <label>
          <span className="label-line">
            Version number <small className="muted">{!draftNumber && suggestion ? "suggested" : "required"}</small>
          </span>
          <input
            value={effectiveNumber}
            onChange={(e) => { setVersionNumberTouched(true); setVersionNumber(e.target.value); }}
            placeholder="e.g. v1.0.0"
            maxLength={50}
            aria-invalid={duplicateNumber || namingMismatch}
            required
          />
          {duplicateNumber ? (
            <small className="field-error">Version “{effectiveNumber}” already exists in this project.</small>
          ) : !draftNumber && suggestion ? (
            <small className="muted">Keeps the format of your first version — edit it if you need a different name.</small>
          ) : namingMismatch ? (
            <small className="field-error">This project uses the version pattern “{versionTemplate}”.</small>
          ) : null}
        </label>
        <label>
          <span className="label-line">
            Note <small className="muted">optional</small>
          </span>
          <input value={note} onChange={(e) => setNote(e.target.value)} placeholder="What changed?" maxLength={191} />
        </label>
      </div>

      <ErrorBanner error={error} onDismiss={() => setError(null)} />
      {prepared && (
        <section className="stack import-review" aria-label="OpenAPI review">
          <div className="row spread">
            <div>
              <h4>Review OpenAPI specification</h4>
              <p className="muted">
                Parsed as {prepared.sourceType === "markdown" ? "README Markdown" : "OpenAPI JSON"}.
                {prepared.statistics && ` Found ${prepared.statistics.endpoints} operations across ${prepared.statistics.tags} groups.`}
                {" "}Edit the JSON below; it is revalidated as you type.
              </p>
            </div>
            <button type="button" className="btn btn-ghost" disabled={busy} onClick={resetPrepared}>Back to source</button>
          </div>
          {prepared.warnings?.map((warning, index) => (
            <Notice kind="warn" key={`${warning.code}-${warning.lines?.[0] ?? index}`}>
              <strong>{warning.code}</strong>
              {warning.lines && <> · lines {warning.lines[0]}–{warning.lines[1]}</>}
              {warning.endpoint && <> · <code>{warning.endpoint}</code></>}
              <br />{warning.message}
            </Notice>
          ))}
          <label>
            <span className="label-line">OpenAPI JSON <small className="muted">{reviewText.length ? `${reviewText.split("\n").length} lines` : ""}</small></span>
            <textarea className="spec-editor" rows={24} value={reviewText} onChange={(event) => setReviewText(event.target.value)} spellCheck={false} />
          </label>
          {reviewErrors.length > 0 && (
            <Notice kind="warn">
              <strong>Fix these validation errors before saving:</strong>
              <ul>{reviewErrors.slice(0, 20).map((issue) => <li key={issue}>{issue}</li>)}</ul>
              {reviewErrors.length > 20 && <p>And {reviewErrors.length - 20} more validation errors.</p>}
            </Notice>
          )}
        </section>
      )}
      {result && (
        <Notice kind="ok" onDismiss={() => setResult(null)}>
          Created version <strong>{result.versionNumber}</strong> with {Object.keys(result.openapiJson?.paths ?? {}).length} paths
          {result.parse?.statistics && (
            <> (README parser found {result.parse.statistics.endpoints} operations in {result.parse.statistics.tags} groups)</>
          )}
          .
        </Notice>
      )}

      <div className="form-actions">
        <button className="btn btn-primary" disabled={busy || (prepared ? reviewErrors.length > 0 || duplicateNumber || !effectiveNumber : !ready)}>
          <Icon name={prepared ? "check" : "bolt"} /> {busy ? (prepared ? "Saving…" : "Parsing…") : prepared ? "Validate and save version" : "Parse and review"}
        </button>
        {!prepared && !ready && <span className="muted">{mode === "file" ? "Choose a README Markdown or OpenAPI JSON file to continue." : "Paste README Markdown or an OpenAPI JSON object to continue."}</span>}
      </div>
    </form>
  );
}

/** Lets an editor fix the stored OpenAPI JSON directly. */
function SpecEditor({ version, onSaved, onCancel }) {
  const [text, setText] = useState(() => JSON.stringify(version.openapiJson, null, 2));
  const [saved, setSaved] = useState(null);
  const { busy, error, setError, run } = useAction();

  let parsed = null;
  let syntaxError = null;
  let validationErrors = [];
  try {
    parsed = JSON.parse(text);
    if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) throw new Error("The document must be a JSON object.");
    validationErrors = validateOpenApiDocument(parsed);
  } catch (e) {
    parsed = null;
    syntaxError = e.message;
  }

  // Find in the editor: the match is selected (and scrolled to) without taking focus from the find box,
  // so Enter keeps stepping through matches; Esc or "Edit match" puts the cursor on the match for editing.
  const find = useFind(text);
  const taRef = useRef(null);
  const findInput = useRef(null);
  const hitsRef = useRef({ hits: [], length: 0 });
  hitsRef.current = { hits: find.hits, length: find.length };
  const jumpOptions = useMemo(() => jumpOptionsOf(version.openapiJson), [version.openapiJson]);

  const selectHit = (focus) => {
    const ta = taRef.current;
    const { hits, length } = hitsRef.current;
    const start = hits[find.index];
    if (!ta || start === undefined) return false;
    const lineHeight = parseFloat(getComputedStyle(ta).lineHeight) || 20;
    const line = ta.value.slice(0, start).split("\n").length - 1;
    ta.scrollTop = Math.max(0, line * lineHeight - ta.clientHeight / 2);
    if (focus) ta.focus();
    ta.setSelectionRange(start, start + length);
    return true;
  };
  // Only when the user navigates the search (not on every keystroke in the document), so typing is never interrupted.
  useEffect(() => {
    selectHit(false);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [find.index, find.query, find.matchCase]);

  const save = async (asNew) => {
    const result = await run(() =>
      asNew
        ? api.createVersion({ projectId: version.projectId, openapiJson: parsed, note: `Edited from ${version.versionNumber}` })
        : api.updateVersionSpec(version.id, parsed),
    );
    if (result) {
      setSaved(asNew ? `Saved as new version ${result.versionNumber}.` : "Changes saved.");
      onSaved(result, asNew);
    }
  };

  return (
    <div className="stack">
      <p className="muted">
        Edit the OpenAPI JSON directly. It is validated as an OpenAPI document before saving. <strong>Save changes</strong> overwrites version{" "}
        {version.versionNumber}; <strong>Save as new version</strong> keeps it untouched.
      </p>
      <div className="findbox" onKeyDown={findShortcut(findInput)}>
        <FindBar
          find={find}
          inputRef={findInput}
          jumpOptions={jumpOptions}
          onJump={(option) => {
            find.setMatchCase(true);
            find.setQuery(option.query);
          }}
          onAccept={() => selectHit(true)}
          onEscape={() => (find.count ? selectHit(true) : taRef.current?.focus())}
        />
        <textarea ref={taRef} className="spec-editor" rows={24} value={text} onChange={(e) => setText(e.target.value)} spellCheck={false} />
      </div>
      {syntaxError && <Notice kind="warn">Invalid JSON: {syntaxError}</Notice>}
      {validationErrors.length > 0 && (
        <Notice kind="warn">
          <strong>Fix these validation errors before saving:</strong>
          <ul>{validationErrors.slice(0, 20).map((issue) => <li key={issue}>{issue}</li>)}</ul>
          {validationErrors.length > 20 && <p>And {validationErrors.length - 20} more validation errors.</p>}
        </Notice>
      )}
      <ErrorBanner error={error} onDismiss={() => setError(null)} />
      {saved && <Notice kind="ok">{saved}</Notice>}
      <div className="row">
        <button className="btn btn-primary" disabled={busy || !parsed || validationErrors.length > 0} onClick={() => save(false)}>
          {busy ? "Saving…" : "Save changes"}
        </button>
        <button className="btn" disabled={busy || !parsed || validationErrors.length > 0} onClick={() => save(true)}>
          Save as new version
        </button>
        <button className="btn btn-ghost" disabled={busy} onClick={() => setText(JSON.stringify(version.openapiJson, null, 2))}>
          Reset
        </button>
        <button className="btn btn-ghost" onClick={onCancel}>
          Cancel
        </button>
      </div>
    </div>
  );
}

function VersionViewer({ versionId, canEdit, onChanged, onDeleted, onClose }) {
  const version = useAsync(() => api.getVersion(versionId), [versionId]);
  const [editing, setEditing] = useState(false);
  const [collapsed, setCollapsed] = useState(false);
  // Deleting a version is confirmed with the app's confirm bar, not window.confirm.
  const removal = useAction();
  const [confirmDelete, setConfirmDelete] = useState(false);
  const remove = async () => {
    const done = await removal.run(async () => {
      await api.deleteVersion(version.data.id);
      return true;
    });
    if (done) {
      setConfirmDelete(false);
      onDeleted();
    }
  };
  const jumpOptions = useMemo(() => jumpOptionsOf(version.data?.openapiJson), [version.data]);

  const download = () => {
    const blob = new Blob([JSON.stringify(version.data.openapiJson, null, 2)], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `openapi-${version.data.versionNumber}.json`;
    link.click();
    URL.revokeObjectURL(url);
  };

  return (
    <div className={`card viewer-card${collapsed ? " is-collapsed" : ""}`}>
      <div className="card-head">
        <div className="row" style={{ gap: 12 }}>
          <span className="tile-icon">
            <Icon name="braces" size="18px" />
          </span>
          <div>
            <h3>{version.data ? `Version ${version.data.versionNumber}: OpenAPI JSON` : "Loading version…"}</h3>
            {version.data && (
              <p className="muted">
                {Object.keys(version.data.openapiJson?.paths ?? {}).length} paths · saved {timeAgo(version.data.createdAt)}
              </p>
            )}
          </div>
        </div>
        <div className="row wrap">
          <button
            type="button"
            className="btn btn-ghost"
            onClick={() => setCollapsed((value) => !value)}
            aria-expanded={!collapsed}
            title={collapsed ? "Expand the JSON view" : "Collapse the JSON view"}
          >
            <Icon name={collapsed ? "expand" : "collapse"} /> {collapsed ? "Expand" : "Collapse"}
          </button>
          {!collapsed && version.data && canEdit && !editing && (
            <button className="btn" onClick={() => setEditing(true)}>
              <Icon name="edit" /> Edit JSON
            </button>
          )}
          {!collapsed && version.data && (
            <button className="btn" onClick={download}>
              <Icon name="download" /> Download JSON
            </button>
          )}
          {!collapsed && version.data && canEdit && (
            <button className="btn btn-ghost" onClick={() => setConfirmDelete(true)} title="Delete this version">
              <Icon name="trash" /> Delete version
            </button>
          )}
          <button className="btn btn-ghost" onClick={onClose}>
            <Icon name="close" /> Close
          </button>
        </div>
      </div>
      {confirmDelete && version.data && (
        <div className="confirm-bar" role="alertdialog" aria-label="Confirm version deletion">
          <Icon name="alert" />
          <span className="grow">
            <strong>Delete version {version.data.versionNumber}?</strong> Comparisons that include it lose one side. This cannot be undone.
          </span>
          <button className="btn btn-danger-solid" onClick={remove} disabled={removal.busy}>
            {removal.busy ? "Deleting…" : "Yes, delete"}
          </button>
          <button className="btn" onClick={() => setConfirmDelete(false)}>
            Cancel
          </button>
        </div>
      )}
      {confirmDelete && <ErrorBanner error={removal.error} onDismiss={() => removal.setError(null)} />}
      {/* Kept mounted while collapsed so search hits, editor drafts and scroll position survive. */}
      <div className="viewer-body" style={collapsed ? { display: "none" } : undefined}>
      {version.loading && !version.data && <Skeleton h={220} r={10} />}
      <ErrorBanner error={version.error} />
      {version.data &&
        (editing ? (
          <SpecEditor
            version={version.data}
            onCancel={() => setEditing(false)}
            onSaved={(result, asNew) => {
              onChanged(result, asNew);
              if (!asNew) {
                version.reload();
                setEditing(false);
              }
            }}
          />
        ) : (
          <JsonView value={version.data.openapiJson} maxHeight={560} searchable jumpOptions={jumpOptions} />
        ))}
      </div>
    </div>
  );
}

export default function VersionsTab({ project, can, reloadProject, goToTab }) {
  const versions = useAsync(() => api.getVersions(project.id), [project.id]);
  const [viewing, setViewing] = usePersistentState(`ver.${project.id}.viewing`, null);
  const [from, setFrom] = usePersistentState(`ver.${project.id}.from`, "");
  const [to, setTo] = usePersistentState(`ver.${project.id}.to`, "");
  const [comparison, setComparison] = usePersistentState(`ver.${project.id}.comparison`, null, { memoryOnly: true });
  const compare = useAction();

  const list = versions.data ?? [];

  // Newest first: index 0 is the newest version, so a version is older than another when its index is larger.
  const indexById = useMemo(() => new Map(list.map((version, index) => [String(version.id), index])), [list]);
  const fromIndex = from ? indexById.get(String(from)) : undefined;
  const toIndex = to ? indexById.get(String(to)) : undefined;
  // Each dropdown is constrained by the other: "From" only offers versions older than the selected "To",
  // and "To" only offers versions newer than the selected "From".
  const fromOptions = toIndex === undefined ? list : list.filter((_, index) => index > toIndex);
  const toOptions = fromIndex === undefined ? list : list.filter((_, index) => index < fromIndex);

  const selectFrom = (value) => {
    setFrom(value);
    const nextFrom = value ? indexById.get(String(value)) : undefined;
    const currentTo = to ? indexById.get(String(to)) : undefined;
    // The newly picked "From" must be older than the current "To"; otherwise reset "To".
    if (value && to && !(nextFrom !== undefined && currentTo !== undefined && nextFrom > currentTo)) setTo("");
  };

  const selectTo = (value) => {
    setTo(value);
    const nextTo = value ? indexById.get(String(value)) : undefined;
    const currentFrom = from ? indexById.get(String(from)) : undefined;
    // The newly picked "To" must be newer than the current "From"; otherwise reset "From".
    if (value && from && !(currentFrom !== undefined && nextTo !== undefined && currentFrom > nextTo)) setFrom("");
  };

  // Saved picks can go stale (deleted versions, or a reversed pair saved before this constraint existed).
  useEffect(() => {
    if (!versions.data) return;
    const fi = from ? indexById.get(String(from)) : undefined;
    const ti = to ? indexById.get(String(to)) : undefined;
    if (from && fi === undefined) setFrom("");
    else if (to && ti === undefined) setTo("");
    else if (fi !== undefined && ti !== undefined && fi === ti) setTo("");
    else if (fi !== undefined && ti !== undefined && fi < ti) {
      setFrom(String(to));
      setTo(String(from));
    }
  }, [versions.data, indexById, from, to]);

  const onImported = () => {
    setComparison(null);
    versions.reload();
    reloadProject();
  };

  const runCompare = async (toId, fromId) => {
    setComparison(null);
    const result = await compare.run(() => api.compareVersions(toId, fromId));
    if (result) setComparison(result);
  };

  // Newest first, so the "previous" version of list[i] is list[i + 1].
  const compareWithPrevious = (index) => {
    setTo(String(list[index].id));
    setFrom(list[index + 1] ? String(list[index + 1].id) : "");
    runCompare(list[index].id, list[index + 1]?.id);
  };

  return (
    <div className="stack">
      {can("EDITOR") ? (
        <ImportPanel projectId={project.id} versions={list} onImported={onImported} />
      ) : (
        <Notice kind="info">You have read-only access, so you cannot add versions.</Notice>
      )}

      <div className="card">
        <div className="card-head">
          <div>
            <h3>Version history</h3>
            <p className="muted">{list.length > 0 ? `${list.length} version${list.length === 1 ? "" : "s"}, newest first` : "Every upload is stored as its own version."}</p>
          </div>
        </div>
        {versions.loading && !versions.data && <SkeletonList rows={2} height={78} label="Loading versions" />}
        <ErrorBanner error={versions.error} />
        {versions.data && list.length === 0 && (
          <EmptyState title="No versions yet" icon="layers">
            Upload or paste an OpenAPI JSON document above to create the first one.
          </EmptyState>
        )}

        {list.length > 0 && (
          <ol className="timeline">
            {list.map((version, index) => (
              <li key={version.id} className={`tl-item${index === 0 ? " latest" : ""}${viewing === version.id ? " viewing" : ""}`}>
                <span className="tl-dot" aria-hidden="true" />
                <div className="tl-card">
                  <div className="tl-main">
                    <div className="row wrap" style={{ gap: 8 }}>
                      <strong className="tl-version">{version.versionNumber}</strong>
                      {index === 0 && <Badge kind="ok">latest</Badge>}
                    </div>
                    {version.note && <p className="tl-note">{version.note}</p>}
                    <div className="tl-meta">
                      <Avatar name={version.author?.email} size={20} />
                      <span className="truncate">{version.author?.email}</span>
                      <span className="dot-sep" />
                      <time dateTime={version.createdAt} title={formatDate(version.createdAt)}>
                        {timeAgo(version.createdAt)}
                      </time>
                      <span className="tl-abs muted">{formatDate(version.createdAt)}</span>
                    </div>
                  </div>
                  <div className="tl-actions">
                    <button className="btn btn-small" onClick={() => setViewing(version.id)}>
                      <Icon name="braces" /> View JSON
                    </button>
                    {index < list.length - 1 && (
                      <button className="btn btn-small" onClick={() => compareWithPrevious(index)}>
                        <Icon name="columns" /> Compare with previous
                      </button>
                    )}
                  </div>
                </div>
              </li>
            ))}
          </ol>
        )}
      </div>

      {viewing && (
        <VersionViewer
          key={viewing}
          versionId={viewing}
          canEdit={can("EDITOR")}
          onChanged={(result, asNew) => {
            setComparison(null);
            versions.reload();
            // The JSON editor saves outside the import form, so the project (its sidebar version
            // count and latest-version label) must be refreshed too.
            reloadProject();
            if (asNew) setViewing(result.id);
          }}
          onDeleted={() => {
            setViewing(null);
            setComparison(null);
            versions.reload();
            reloadProject();
          }}
          onClose={() => setViewing(null)}
        />
      )}

      {list.length >= 2 && (
        <div className="card">
          <div className="card-head">
            <div>
              <h3>Compare versions</h3>
              <p className="muted">Pick two versions to see what changed. Each list only offers versions that pair with the other side: older ones for “From”, newer ones for “To”.</p>
            </div>
          </div>
          <div className="compare-bar">
            <label>
              <span className="label-line">
                From <small className="muted">older</small>
              </span>
              <select value={from} onChange={(e) => selectFrom(e.target.value)}>
                <option value="">Select…</option>
                {fromOptions.map((v) => (
                  <option key={v.id} value={v.id}>
                    {v.versionNumber}
                  </option>
                ))}
              </select>
            </label>
            <label>
              <span className="label-line">
                To <small className="muted">newer</small>
              </span>
              <select value={to} onChange={(e) => selectTo(e.target.value)}>
                <option value="">Select…</option>
                {toOptions.map((v) => (
                  <option key={v.id} value={v.id}>
                    {v.versionNumber}
                  </option>
                ))}
              </select>
            </label>
            <button className="btn btn-primary" disabled={!from || !to || from === to || compare.busy} onClick={() => runCompare(to, from)}>
              <Icon name="columns" /> {compare.busy ? "Comparing…" : "Compare"}
            </button>
          </div>
          <ErrorBanner error={compare.error} onDismiss={() => compare.setError(null)} />
          {compare.busy && <SkeletonList rows={1} height={64} label="Comparing" />}
        </div>
      )}

      {comparison && <DiffView project={project} comparison={comparison} onClose={() => setComparison(null)} can={can} reloadProject={reloadProject} />}
    </div>
  );
}
