// Turns the parser's flat warning list into something a person can scan:
// one group per warning type, one row per endpoint or message, and compact line numbers.
// Pure functions only, so they can be unit tested without React.

const KNOWN = {
  DUPLICATE_ENDPOINT: {
    title: "Repeated endpoint",
    severity: "warning",
    // The parser reports each extra copy, so an endpoint reported N times was documented N + 1 times.
    extraCopies: true,
    hint: "The same method and path is documented more than once and only the last row is kept. Remove the extra rows, or give them different paths.",
  },
  HEADING_MERGED: {
    title: "Heading joined another endpoint",
    severity: "warning",
    hint: "This heading was treated as extra detail for an endpoint already defined, not as a new endpoint. Check that the path is the one you meant.",
  },
  LEGACY_FORMAT: {
    title: "Header block missing",
    severity: "info",
    wholeDocument: true,
    hint: "Your README doesn't start with the small settings block between two --- lines. It still imports normally; the block just sets your project title and version and makes the checks stricter.",
    // Only the Composer package has this command; the web app shows a header to paste instead (see suggestedHeader).
    command: "avp migrate --write",
  },
  UNBOUND_JSON_BLOCK: {
    title: "JSON block not attached to an endpoint",
    severity: "warning",
    hint: "A JSON example sits where the parser could not tell which endpoint it belongs to, so it was ignored. Put it directly under its endpoint, labelled Request or Response.",
  },
  INVALID_JSON_BLOCK: {
    title: "Invalid JSON example",
    severity: "warning",
    hint: "A JSON example could not be parsed and was skipped. Check for trailing commas or missing quotes.",
  },
  UNRESOLVED_PREFIX: {
    title: "URL prefix not found",
    severity: "warning",
    hint: "The path has no leading slash and no section prefix above it, so it may not be a full URL path. Add a section heading such as ## Notes — `notes/`.",
  },
  UNRESOLVED_PUBLIC_ENDPOINT: {
    title: "Public endpoint not matched",
    severity: "warning",
    hint: "An Auth: false mention did not match any documented endpoint.",
  },
  INFERRED_PERMISSION: {
    title: "Access level was guessed",
    severity: "info",
    hint: "Mark each endpoint with Auth: true or Auth: false so it is not guessed.",
  },
  DOT_SEGMENT_PATH: {
    title: "Path contains . or ..",
    severity: "warning",
    hint: "Clients may resolve a . or .. segment to a different URL. Use a plain path.",
  },
  NOT_UTF8: {
    title: "File is not UTF-8",
    severity: "warning",
    hint: "The document was read as Windows-1252, so some characters may be wrong. Save it as UTF-8.",
  },
  INVALID_SERVER: {
    title: "Server entry problem",
    severity: "warning",
    hint: "A servers entry in the header could not be used.",
  },
};

const titleCase = (code) => {
  const words = String(code ?? "warning").toLowerCase().split("_").filter(Boolean);
  const text = words.join(" ") || "warning";
  return text.charAt(0).toUpperCase() + text.slice(1);
};

/** Title, severity ("warning" | "info"), hint and optional command for a warning code. */
export function describeWarning(code) {
  const known = KNOWN[code];
  if (known) return { ...known };
  if (String(code ?? "").startsWith("FRONT_MATTER_")) {
    return {
      title: "Header problem",
      severity: "warning",
      hint: "Something in the --- header at the top of the document needs attention.",
    };
  }
  return { title: titleCase(code), severity: "warning", hint: "" };
}

const MAX_RANGE = 20000;

/** [[550, 550], [561, 563]] -> ["550", "561–563"]; overlapping and adjacent ranges are merged. */
export function compressLines(ranges) {
  const numbers = new Set();
  for (const range of Array.isArray(ranges) ? ranges : []) {
    const start = Math.trunc(Number(range?.[0]));
    const end = Math.trunc(Number(range?.[1] ?? range?.[0]));
    if (!Number.isFinite(start) || start < 1) continue;
    const last = Number.isFinite(end) && end >= start ? Math.min(end, start + MAX_RANGE) : start;
    for (let line = start; line <= last; line += 1) numbers.add(line);
  }
  const sorted = [...numbers].sort((a, b) => a - b);
  const parts = [];
  let from = null;
  let previous = null;
  for (const line of sorted) {
    if (from === null) {
      from = line;
    } else if (line !== previous + 1) {
      parts.push(from === previous ? String(from) : `${from}–${previous}`);
      from = line;
    }
    previous = line;
  }
  if (from !== null) parts.push(from === previous ? String(from) : `${from}–${previous}`);
  return parts;
}

/** "Line 550", "Lines 12–15", "Lines 550, 561–609" (long lists are cut), or "" when there are no lines. */
export function lineText(parts, { max = 6, single: singleWord = "Line", multi: multiWord = "Lines" } = {}) {
  if (!parts.length) return "";
  const single = parts.length === 1 && !parts[0].includes("–");
  const shown = parts.slice(0, max).join(", ");
  const more = parts.length > max ? ` and ${parts.length - max} more` : "";
  return `${single ? singleWord : multiWord} ${shown}${more}`;
}

/** A YAML-safe title taken from the document's first "# Heading", or a placeholder. */
function titleFrom(source) {
  const match = /^\s{0,3}#\s+(.+?)\s*#*\s*$/m.exec(String(source ?? ""));
  const text = (match ? match[1] : "").replace(/[`*_]/g, "").replace(/\s+/g, " ").trim().slice(0, 80);
  if (!text) return "Your project name";
  return /^[A-Za-z0-9][A-Za-z0-9 ._()/-]*$/.test(text) ? text : `"${text.replace(/\\/g, "\\\\").replace(/"/g, '\\"')}"`;
}

/**
 * The header to paste at the very top of a README, with a plain-language note above each line.
 * The project title comes from the README's first heading (or a placeholder to replace).
 */
export function suggestedHeader(source) {
  return [
    "---",
    "# Required. Leave this as 1. It tells the importer this README uses the checked format.",
    "avp: 1",
    "# Required. Your project title: the name shown at the top of your API docs.",
    `title: ${titleFrom(source)}`,
    "# Optional. Your project version, for example 1.0.0.",
    "version: 1.0.0",
    "---",
    "",
  ].join("\n");
}

/**
 * Groups warnings by code. Each group lists one item per endpoint (or message), with the lines it
 * appears on and how many times. Warnings come before notes; the busiest group comes first.
 */
export function groupWarnings(warnings) {
  const list = Array.isArray(warnings) ? warnings.filter((warning) => warning && typeof warning === "object") : [];
  const byCode = new Map();
  for (const warning of list) {
    const code = String(warning.code ?? "WARNING");
    if (!byCode.has(code)) byCode.set(code, { code, info: describeWarning(code), total: 0, byItem: new Map() });
    const group = byCode.get(code);
    group.total += 1;
    const label = String(warning.endpoint ?? warning.message ?? code);
    const itemKey = `${warning.endpoint ? "e" : "m"}\u0000${label}`;
    if (!group.byItem.has(itemKey)) {
      group.byItem.set(itemKey, { key: itemKey, label, isEndpoint: Boolean(warning.endpoint), message: warning.message ?? "", total: 0, ranges: [] });
    }
    const item = group.byItem.get(itemKey);
    item.total += 1;
    if (Array.isArray(warning.lines)) item.ranges.push(warning.lines);
  }
  const groups = [...byCode.values()].map((group) => {
    const items = [...group.byItem.values()].map((item) => {
      const parts = group.info.wholeDocument ? [] : compressLines(item.ranges);
      const repeats = Boolean(group.info.extraCopies);
      return {
        key: item.key,
        label: item.label,
        isEndpoint: item.isEndpoint,
        message: item.message,
        total: item.total,
        // How many times the endpoint is written in the document (the first copy is not a warning of its own).
        copies: repeats ? item.total + 1 : null,
        parts,
        lineText: group.info.wholeDocument
          ? "Whole document"
          : lineText(parts, repeats ? { single: "Repeat on line", multi: "Repeats on lines" } : undefined),
        firstLine: parts.length ? Number.parseInt(parts[0], 10) : Number.MAX_SAFE_INTEGER,
      };
    });
    items.sort((a, b) => a.firstLine - b.firstLine || a.label.localeCompare(b.label));
    return { code: group.code, ...group.info, total: group.total, itemCount: items.length, items };
  });
  const rank = (group) => (group.severity === "info" ? 1 : 0);
  groups.sort((a, b) => rank(a) - rank(b) || b.total - a.total || a.title.localeCompare(b.title));
  return groups;
}

/** One-line summary such as "2 types · 7 items". An item is one endpoint or one message, however often it repeats. */
export function summarizeWarnings(groups) {
  const items = groups.reduce((sum, group) => sum + group.itemCount, 0);
  const types = groups.length;
  return `${types} ${types === 1 ? "type" : "types"} · ${items} ${items === 1 ? "item" : "items"}`;
}

/** Small documents open expanded; noisy ones start as a one-line summary the person can open. */
export function startsExpanded(groups) {
  const places = groups.reduce((sum, group) => sum + group.total, 0);
  return groups.length <= 2 && places <= 6;
}
