import { useEffect, useMemo, useState } from "react";
import { Icon } from "./icons.jsx";
import { groupWarnings, startsExpanded, suggestedHeader, summarizeWarnings } from "../lib/parser-warnings.js";
import { mode } from "../mode.js";

const ITEM_LIMIT = 5;

/** A short piece of text to copy, with one line saying what to do with it. */
function CopyBlock({ intro, text, label }) {
  const [copied, setCopied] = useState(false);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(true);
      setTimeout(() => setCopied(false), 1800);
    } catch {
      /* the text stays visible and selectable */
    }
  };
  return (
    <div className="pw-fix">
      <p className="pw-fix-intro">{intro}</p>
      <div className="pw-command">
        <pre>{text}</pre>
        <button type="button" className="btn btn-small btn-ghost" onClick={copy} aria-label={`Copy ${label}`}>
          <Icon name={copied ? "check" : "copy"} /> {copied ? "Copied" : "Copy"}
        </button>
      </div>
    </div>
  );
}

function WarningGroup({ group, source, onDismiss }) {
  const [all, setAll] = useState(false);
  const shown = all ? group.items : group.items.slice(0, ITEM_LIMIT);
  const hidden = group.items.length - shown.length;
  return (
    <li className={`pw-group pw-${group.severity}`}>
      <div className="pw-group-head">
        <span className="pw-icon" aria-hidden="true">
          <Icon name={group.severity === "info" ? "info" : "alert"} />
        </span>
        <div className="pw-group-text">
          <div className="pw-group-title">
            <strong>{group.title}</strong>
            <span className="pw-count" title={`${group.itemCount} ${group.itemCount === 1 ? "item" : "items"}`}>
              {group.itemCount}
            </span>
          </div>
          {group.hint && <p className="pw-hint">{group.hint}</p>}
        </div>
        <button type="button" className="btn btn-small btn-ghost pw-dismiss" onClick={onDismiss} aria-label={`Dismiss: ${group.title}`} title="Hide this for this review only">
          Dismiss
        </button>
      </div>
      {/* The header fix differs by product: the package has a command that edits the file; on the web there is no file to edit, so offer the header to paste. */}
      {group.command &&
        (mode.local ? (
          <CopyBlock intro="To add it, run this in your project folder:" text={group.command} label="the command" />
        ) : (
          <CopyBlock intro="To add it, paste this at the very top of your README, then import it again:" text={suggestedHeader(source)} label="the header" />
        ))}
      {/* A note about the whole document has no lines to list: the hint above already says everything. */}
      {!group.wholeDocument && (
        <ul className="pw-items">
          {shown.map((item) => (
            <li key={item.key} className="pw-item">
              <span className="pw-item-label">{item.isEndpoint ? <code>{item.label}</code> : item.label}</span>
              <span className="pw-item-meta">
                {item.lineText && <span className="pw-lines">{item.lineText}</span>}
                {item.copies ? <span className="pw-times">Documented {item.copies} times</span> : item.total > 1 ? <span className="pw-times">{item.total} times</span> : null}
              </span>
            </li>
          ))}
        </ul>
      )}
      {group.items.length > ITEM_LIMIT && (
        <button type="button" className="btn btn-small btn-ghost pw-more" onClick={() => setAll((value) => !value)} aria-expanded={all}>
          {all ? "Show fewer" : `Show ${hidden} more`}
        </button>
      )}
    </li>
  );
}

/**
 * The README parser's warnings, grouped by type. Small lists open by default; a noisy document starts as a
 * one-line summary so it never pushes the editor and the save button out of view. Nothing here blocks saving.
 */
export default function ParserWarnings({ warnings, source = "" }) {
  const groups = useMemo(() => groupWarnings(warnings), [warnings]);
  const signature = useMemo(() => groups.map((group) => `${group.code}:${group.total}`).join("|"), [groups]);
  const [open, setOpen] = useState(() => startsExpanded(groups));
  const [dismissed, setDismissed] = useState(() => new Set());

  // A new review (different warnings) starts fresh.
  useEffect(() => {
    setOpen(startsExpanded(groups));
    setDismissed(new Set());
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [signature]);

  if (groups.length === 0) return null;

  const visible = groups.filter((group) => !dismissed.has(group.code));
  const dismissedCount = groups.length - visible.length;
  const warningGroups = visible.filter((group) => group.severity !== "info");
  const tone = warningGroups.length > 0 ? "warning" : "info";

  return (
    <section className={`pw pw-root-${tone}`} aria-label="README parser warnings">
      <button type="button" className="pw-toggle" aria-expanded={open} onClick={() => setOpen((value) => !value)}>
        <span className="pw-icon" aria-hidden="true">
          <Icon name={tone === "info" ? "info" : "alert"} />
        </span>
        <span className="pw-toggle-text">
          <strong>README check</strong>
          <span className="pw-summary">{summarizeWarnings(groups)}</span>
        </span>
        <span className="pw-toggle-action">{open ? "Hide details" : "Show details"}</span>
        <Icon name="chevron" className={`pw-chevron${open ? " open" : ""}`} />
      </button>

      {!open && visible.length > 0 && (
        <ul className="pw-chips" aria-label="Warning types">
          {visible.map((group) => (
            <li key={group.code} className={`pw-chip pw-${group.severity}`}>
              {group.title} <b>{group.itemCount}</b>
            </li>
          ))}
        </ul>
      )}

      {open && (
        <div className="pw-body">
          <p className="pw-lead">
            These never stop you from saving. Fix them in your README, or save as is.
          </p>
          {visible.length > 0 ? (
            <ul className="pw-groups">
              {visible.map((group) => (
                <WarningGroup key={group.code} group={group} source={source} onDismiss={() => setDismissed((current) => new Set(current).add(group.code))} />
              ))}
            </ul>
          ) : (
            <p className="muted pw-all-dismissed">Everything is dismissed for this review.</p>
          )}
          {dismissedCount > 0 && (
            <div className="pw-dismissed" role="status">
              <span>
                {dismissedCount} {dismissedCount === 1 ? "type" : "types"} dismissed for this review.
              </span>
              <button type="button" className="btn btn-small btn-ghost" onClick={() => setDismissed(new Set())}>
                Show again
              </button>
            </div>
          )}
        </div>
      )}
    </section>
  );
}
