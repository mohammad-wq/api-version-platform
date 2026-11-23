import assert from "node:assert/strict";
import test from "node:test";
import { compressLines, describeWarning, groupWarnings, lineText, startsExpanded, suggestedHeader, summarizeWarnings } from "./parser-warnings.js";

/** The value on the header's `title:` line. */
const titleLine = (header) => header.split("\n").find((line) => line.startsWith("title:"));

test("the suggested header is a ---, avp, title, version block with a plain note above each line", () => {
  const lines = suggestedHeader("# Notes API\n\nText").split("\n");
  assert.equal(lines[0], "---");
  assert.deepEqual(lines.filter((line) => !line.startsWith("#") && line !== ""), ["---", "avp: 1", "title: Notes API", "version: 1.0.0", "---"]);
  assert.match(lines.join("\n"), /# Required\. Leave this as 1\./);
  assert.match(lines.join("\n"), /# Required\. Your project title: the name shown at the top of your API docs\./);
  assert.match(lines.join("\n"), /# Optional\. Your project version, for example 1\.0\.0\./);
  // Every key is directly preceded by its own note.
  for (const key of ["avp:", "title:", "version:"]) {
    const index = lines.findIndex((line) => line.startsWith(key));
    assert.ok(lines[index - 1].startsWith("# "), `a note should precede ${key}`);
  }
});

test("the suggested header takes its title from the first heading", () => {
  assert.equal(titleLine(suggestedHeader("# Notes API\n\nText")), "title: Notes API");
  assert.equal(titleLine(suggestedHeader("intro\n\n## Not first level\n# Shop API v2 #\n")), "title: Shop API v2");
  assert.equal(titleLine(suggestedHeader("# `Orders` **service**")), "title: Orders service");
});

test("a title that YAML would misread is quoted, and a missing heading gets a placeholder", () => {
  assert.equal(titleLine(suggestedHeader("# API: version 2")), 'title: "API: version 2"');
  assert.equal(titleLine(suggestedHeader('# Say "hi" # now')), 'title: "Say \\"hi\\" # now"');
  assert.equal(titleLine(suggestedHeader("no heading at all")), "title: Your project name");
  assert.equal(titleLine(suggestedHeader(undefined)), "title: Your project name");
  assert.equal(titleLine(suggestedHeader("# " + "x".repeat(200))).length < 100, true);
});

test("one repeated endpoint is reported as two copies, not one", () => {
  const groups = groupWarnings([{ code: "DUPLICATE_ENDPOINT", message: "m", lines: [552, 552], endpoint: "POST /b" }]);
  assert.equal(groups[0].itemCount, 1);
  assert.equal(groups[0].items[0].copies, 2);
  assert.equal(summarizeWarnings(groups), "1 type · 1 item");
});

test("a single line is shown as one line, never as a 1-1 range", () => {
  assert.deepEqual(compressLines([[550, 550]]), ["550"]);
  assert.equal(lineText(compressLines([[550, 550]])), "Line 550");
  assert.equal(lineText(compressLines([[1, 1]])), "Line 1");
});

test("a real range stays a range and several lines are listed", () => {
  assert.equal(lineText(compressLines([[12, 15]])), "Lines 12–15");
  assert.equal(lineText(compressLines([[550, 550], [561, 563]])), "Lines 550, 561–563");
});

test("adjacent, overlapping and unsorted ranges are merged", () => {
  assert.deepEqual(compressLines([[561, 561], [562, 562], [563, 563], [560, 560]]), ["560–563"]);
  assert.deepEqual(compressLines([[10, 14], [12, 20], [30, 30]]), ["10–20", "30"]);
});

test("bad or missing line data is ignored", () => {
  assert.deepEqual(compressLines(undefined), []);
  assert.deepEqual(compressLines([null, [0, 0], ["x", "y"], [-3, -1]]), []);
  assert.equal(lineText([]), "");
});

test("long line lists are cut with a count", () => {
  const parts = Array.from({ length: 10 }, (_, i) => String(i * 10 + 1));
  assert.equal(lineText(parts), "Lines 1, 11, 21, 31, 41, 51 and 4 more");
});

test("the 56-warning example collapses into two groups with compact lines", () => {
  const warnings = [];
  for (let line = 561; line <= 609; line += 1) {
    warnings.push({ code: "DUPLICATE_ENDPOINT", message: "GET /api/v1/test/duplicate-a is documented more than once; the last row wins.", lines: [line, line], endpoint: "GET /api/v1/test/duplicate-a" });
  }
  warnings.push({ code: "DUPLICATE_ENDPOINT", message: "x", lines: [550, 550], endpoint: "GET /api/v1/test/duplicate-a" });
  for (const [method, path, line] of [["POST", "b", 552], ["GET", "c", 554], ["GET", "d", 556], ["PUT", "e", 558], ["DELETE", "f", 560]]) {
    warnings.push({ code: "DUPLICATE_ENDPOINT", message: "m", lines: [line, line], endpoint: `${method} /api/v1/test/duplicate-${path}` });
  }
  warnings.push({ code: "LEGACY_FORMAT", message: "no header", lines: [1, 1] });
  const groups = groupWarnings(warnings);
  assert.equal(groups.length, 2);
  assert.equal(groups[0].code, "DUPLICATE_ENDPOINT");
  assert.equal(groups[0].total, 55);
  assert.equal(groups[0].items.length, 6);
  assert.equal(groups[0].items[0].label, "GET /api/v1/test/duplicate-a");
  assert.equal(groups[0].items[0].total, 50);
  assert.equal(groups[0].items[0].lineText, "Repeats on lines 550, 561–609");
  assert.equal(groups[0].items[1].lineText, "Repeat on line 552");
  // The badge counts endpoints (6), and each row says how often it was written: 50 warnings means 51 copies.
  assert.equal(groups[0].itemCount, 6);
  assert.equal(groups[0].items[0].copies, 51);
  assert.equal(groups[0].items[1].copies, 2);
  assert.equal(groups[1].code, "LEGACY_FORMAT");
  assert.equal(groups[1].severity, "info");
  assert.equal(groups[1].items[0].lineText, "Whole document");
  assert.equal(groups[1].items[0].copies, null);
  assert.equal(summarizeWarnings(groups), "2 types · 7 items");
  assert.equal(startsExpanded(groups), false);
});

test("warnings come before notes, and the busiest group first", () => {
  const groups = groupWarnings([
    { code: "LEGACY_FORMAT", message: "a" },
    { code: "UNBOUND_JSON_BLOCK", message: "b", lines: [3, 3] },
    { code: "DUPLICATE_ENDPOINT", message: "c", lines: [5, 5], endpoint: "GET /a" },
    { code: "DUPLICATE_ENDPOINT", message: "c", lines: [6, 6], endpoint: "GET /a" },
  ]);
  assert.deepEqual(groups.map((group) => group.code), ["DUPLICATE_ENDPOINT", "UNBOUND_JSON_BLOCK", "LEGACY_FORMAT"]);
});

test("unknown codes get a readable title and front-matter codes share one", () => {
  assert.equal(describeWarning("SOMETHING_NEW").title, "Something new");
  assert.equal(describeWarning("FRONT_MATTER_UNKNOWN_KEY").title, "Header problem");
  assert.equal(describeWarning(undefined).severity, "warning");
});

test("a few small warnings start expanded, noisy documents start collapsed", () => {
  assert.equal(startsExpanded(groupWarnings([{ code: "DUPLICATE_ENDPOINT", message: "m", lines: [2, 2], endpoint: "GET /a" }])), true);
  assert.equal(startsExpanded([]), true);
});

test("non-object entries are skipped", () => {
  assert.deepEqual(groupWarnings([null, "x", 3]), []);
  assert.deepEqual(groupWarnings(undefined), []);
});
