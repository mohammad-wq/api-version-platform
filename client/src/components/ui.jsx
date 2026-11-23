import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { FindBar, findShortcut, highlightedHtml, useFind, useScrollToHit } from "./find.jsx";
import { Icon } from "./icons.jsx";
import { accountRoleLabel, normalizeAccountRole, normalizeProjectRole, projectRoleLabel } from "../lib/permissions.js";

/* ---------- loading ---------- */
export function Spinner({ label = "Loading…" }) {
  return (
    <div className="spinner" role="status">
      <span className="spinner-dot" /> {label}
    </div>
  );
}

/** A shimmering placeholder block. */
export function Skeleton({ w = "100%", h = 14, r, style, className = "" }) {
  return <span className={`skeleton ${className}`.trim()} style={{ width: w, height: h, borderRadius: r, ...style }} aria-hidden="true" />;
}

/** Placeholder rows used while a list loads (announced once to screen readers). */
export function SkeletonList({ rows = 3, height = 64, label = "Loading…" }) {
  return (
    <div className="skeleton-list" role="status" aria-label={label}>
      {Array.from({ length: rows }, (_, i) => (
        <div className="skeleton-row" key={i} style={{ height }}>
          <Skeleton w={36} h={36} r={10} />
          <div className="skeleton-lines">
            <Skeleton w={`${40 + ((i * 13) % 30)}%`} h={13} />
            <Skeleton w={`${25 + ((i * 17) % 30)}%`} h={10} />
          </div>
        </div>
      ))}
    </div>
  );
}

/* ---------- feedback ---------- */
const NOTICE_ICON = { error: "alert", warn: "alert", ok: "checkCircle", info: "info" };

function Banner({ kind, role, onDismiss, children }) {
  return (
    <div className={`banner banner-${kind}`} role={role}>
      <Icon name={NOTICE_ICON[kind]} className="banner-icon" />
      <span className="banner-text">{children}</span>
      {onDismiss && (
        <button className="banner-close" onClick={onDismiss} aria-label="Dismiss" type="button">
          <Icon name="close" />
        </button>
      )}
    </div>
  );
}

export function ErrorBanner({ error, onDismiss }) {
  if (!error) return null;
  const message = typeof error === "string" ? error : error.message;
  return (
    <Banner kind="error" role="alert" onDismiss={onDismiss}>
      {message}
    </Banner>
  );
}

export function Notice({ kind = "info", children, onDismiss }) {
  if (!children) return null;
  return (
    <Banner kind={kind} role="status" onDismiss={onDismiss}>
      {children}
    </Banner>
  );
}

export function Badge({ kind = "neutral", children, title }) {
  return (
    <span className={`badge badge-${kind}`} title={title}>
      {children}
    </span>
  );
}

const METHOD_KIND = {
  GET: "get",
  POST: "post",
  PUT: "put",
  PATCH: "patch",
  DELETE: "delete",
  HEAD: "head",
  OPTIONS: "options",
  TRACE: "trace",
};
export const MethodBadge = ({ method }) => <span className={`method method-${METHOD_KIND[method] ?? "get"}`}>{method}</span>;

export function StatusBadge({ status }) {
  if (status == null) return <span className="status-pill status-none">No response</span>;
  const kind = status >= 500 ? "5xx" : status >= 400 ? "4xx" : status >= 300 ? "3xx" : "2xx";
  return (
    <span className={`status-pill status-${kind}`}>
      <span className="status-dot" />
      {status}
    </span>
  );
}

export function EmptyState({ title, children, icon = "layers", action }) {
  return (
    <div className="empty">
      <div className="empty-icon">
        <Icon name={icon} size="22px" />
      </div>
      <strong>{title}</strong>
      {children && <p className="muted">{children}</p>}
      {action && <div className="empty-action">{action}</div>}
    </div>
  );
}

/* ---------- identity ---------- */
const HUES = [228, 252, 272, 296, 330, 350, 12, 28, 160, 178, 195, 212];
const hueOf = (text) => {
  let h = 0;
  for (const ch of String(text ?? "")) h = (h * 31 + ch.charCodeAt(0)) % 997;
  return HUES[h % HUES.length];
};
export const initialsOf = (text) => {
  const words = String(text ?? "?")
    .split(/[^A-Za-z0-9]+/)
    .filter(Boolean);
  if (words.length === 0) return "?";
  return (words.length > 1 ? words[0][0] + words[1][0] : words[0].slice(0, 2)).toUpperCase();
};

/** A coloured initials avatar; the colour is derived from the text so it is stable. `square` makes a project-style tile. */
export function Avatar({ name, size = 28, square = false, className = "" }) {
  return (
    <span
      className={`avatar${square ? " avatar-square" : ""} ${className}`.trim()}
      style={{ "--h": hueOf(name), width: size, height: size, fontSize: Math.max(10, Math.round(size * 0.4)) }}
      aria-hidden="true"
    >
      {square ? initialsOf(name) : initialsOf(name).slice(0, 1)}
    </span>
  );
}

export function RolePill({ role }) {
  const normalizedRole = normalizeProjectRole(role);
  return <span className={`role-pill role-${normalizedRole ?? "unknown"}`}>{projectRoleLabel(normalizedRole)}</span>;
}

export function AccountRoleBadge({ role }) {
  const normalizedRole = normalizeAccountRole(role);
  return <Badge kind="outline">{accountRoleLabel(normalizedRole)} account</Badge>;
}

/* ---------- breadcrumbs (the page tells the top bar where it is) ---------- */
const CrumbContext = createContext({ crumb: null, setCrumb: () => {} });
export function CrumbProvider({ children }) {
  const [crumb, setCrumb] = useState(null);
  const value = useMemo(() => ({ crumb, setCrumb }), [crumb]);
  return <CrumbContext.Provider value={value}>{children}</CrumbContext.Provider>;
}
export const useCrumbValue = () => useContext(CrumbContext).crumb;
/** Sets the last breadcrumb segment while the calling page is mounted. */
export function useCrumb(label) {
  const { setCrumb } = useContext(CrumbContext);
  useEffect(() => {
    setCrumb(label || null);
    return () => setCrumb(null);
  }, [label, setCrumb]);
}

/* ---------- popovers ---------- */
/** Closes a popover on outside click or Escape. Returns [open, setOpen, ref]; attach ref to the wrapper holding trigger + panel. */
export function usePopover() {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  useEffect(() => {
    if (!open) return undefined;
    const onDown = (event) => ref.current && !ref.current.contains(event.target) && setOpen(false);
    const onKey = (event) => event.key === "Escape" && setOpen(false);
    document.addEventListener("mousedown", onDown);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onDown);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);
  return [open, setOpen, ref];
}

export function CopyButton({ text, label = "Copy", className = "btn btn-small" }) {
  const [done, setDone] = useState(false);
  const copy = useCallback(async () => {
    try {
      await navigator.clipboard.writeText(typeof text === "function" ? text() : text);
      setDone(true);
      setTimeout(() => setDone(false), 1500);
    } catch {
      /* clipboard unavailable */
    }
  }, [text]);
  return (
    <button type="button" className={className} onClick={copy}>
      <Icon name={done ? "check" : "copy"} /> {done ? "Copied" : label}
    </button>
  );
}

/**
 * Pretty-prints JSON with light syntax colouring. Strings and other values are shown as text.
 * With `searchable`, a find bar sits above the box: Ctrl+F inside the box searches it, Enter / Shift+Enter
 * step through the matches (highlighted and scrolled into view), and `jumpOptions` ({ label, query }) lets
 * the user land on a specific part of a long document.
 */
export function JsonView({ value, maxHeight = 420, searchable = false, jumpOptions }) {
  const text = useMemo(() => (typeof value === "string" ? value : (JSON.stringify(value, null, 2) ?? "")), [value]);
  const find = useFind(searchable ? text : "");
  const preRef = useRef(null);
  const inputRef = useRef(null);

  const html = useMemo(
    () => highlightedHtml(text, { json: typeof value !== "string", hits: find.hits, length: find.length, current: find.index }),
    [text, value, find.hits, find.length, find.index],
  );
  useScrollToHit(preRef, [html]);

  const box = <pre ref={preRef} className="code" style={{ maxHeight }} tabIndex={searchable ? 0 : undefined} dangerouslySetInnerHTML={{ __html: html }} />;
  if (!searchable) return box;

  return (
    <div className="findbox" onKeyDown={findShortcut(inputRef)}>
      <FindBar
        find={find}
        inputRef={inputRef}
        jumpOptions={jumpOptions}
        onJump={(option) => {
          find.setMatchCase(true);
          find.setQuery(option.query);
        }}
        onEscape={() => preRef.current?.focus()}
      />
      {box}
    </div>
  );
}

/** A full-width dialog. Esc (outside form fields) or a click on the backdrop closes it; the page behind does not scroll. */
export function Modal({ children, onClose, className = "", backdropClassName = "", labelledBy }) {
  const dialogRef = useRef(null);
  // Move focus into the dialog when it opens and give it back to whatever opened it when it closes.
  useEffect(() => {
    const previous = document.activeElement;
    const dialog = dialogRef.current;
    if (dialog && !dialog.contains(document.activeElement)) {
      const first = dialog.querySelector("[autofocus], input:not([type=hidden]), select, textarea, button, [href]");
      (first ?? dialog).focus({ preventScroll: true });
    }
    return () => {
      if (previous && typeof previous.focus === "function" && document.contains(previous)) previous.focus({ preventScroll: true });
    };
  }, []);
  useEffect(() => {
    const onKey = (event) => {
      if (event.key === "Escape" && !/^(INPUT|TEXTAREA|SELECT)$/.test(event.target.tagName)) onClose();
    };
    document.addEventListener("keydown", onKey);
    const previous = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = previous;
    };
  }, [onClose]);
  return (
    <div className={`modal-backdrop${backdropClassName ? ` ${backdropClassName}` : ""}`} onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
      <div ref={dialogRef} tabIndex={-1} className={`modal${className ? ` ${className}` : ""}`} role="dialog" aria-modal="true" aria-labelledby={labelledBy}>
        {children}
      </div>
    </div>
  );
}

export function formatDate(value) {
  if (!value) return "";
  return new Date(value).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" });
}

export function formatBytes(bytes) {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

const UNITS = [
  ["year", 31536000],
  ["month", 2592000],
  ["day", 86400],
  ["hour", 3600],
  ["minute", 60],
];
/** "5 minutes ago" / "in 2 hours". Falls back to "just now" under a minute. */
export function timeAgo(value) {
  if (!value) return "";
  const seconds = Math.round((new Date(value).getTime() - Date.now()) / 1000);
  const abs = Math.abs(seconds);
  if (abs < 45) return "just now";
  const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: "auto" });
  for (const [unit, size] of UNITS) {
    if (abs >= size || unit === "minute") return rtf.format(Math.round(seconds / size), unit);
  }
  return "";
}
