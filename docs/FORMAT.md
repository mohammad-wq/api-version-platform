# API document format

An API document is a Markdown file (`API.md`, `README.md`, ...) that the parser turns into an OpenAPI 3.0.3
document. `avp template` prints a short example to start from.

There are two ways to write one:

| | Strict (recommended) | Legacy |
| --- | --- | --- |
| Starts with | a front-matter header with `avp: 1` | anything else |
| Structural problems | reported as **warnings** (nothing is guessed silently) | reported as warnings where the parser guesses |
| Result | the warnings list the problems with line numbers | the same, plus one `LEGACY_FORMAT` notice |

A problem never stops you from saving a version: the OpenAPI is always built from what the parser could
read, and the warnings tell you where to look. (`avp check --ci`, and `--ci` on `status` and `version create`,
are the opt-in way to fail on warnings.)

Everything below the header is the same Markdown dialect in both modes, so upgrading is just adding the
header (`avp migrate` does it for you and leaves the body untouched).

## The front-matter header

A small YAML subset between two `---` lines at the very top of the file (line 1 must be `---`). No YAML
extension is needed.

```markdown
---
avp: 1
title: Example Service API
version: 1.0.0
description: One or two sentences about the API.
auth: bearer
servers:
  - https://api.example.com/v1
---

# Example Service API
...
```

| Key | Required | Meaning |
| --- | --- | --- |
| `avp` | yes | Format version. Must be `1`. Its presence turns strict mode on. |
| `title` | yes | OpenAPI `info.title` (max 200 characters). Wins over the first `# heading`. |
| `version` | no | OpenAPI `info.version`: 1-50 characters such as `1.0.0` or `2024-05` (default `1.0.0`). |
| `description` | no | OpenAPI `info.description`. Default: the first paragraph under the `# title`. |
| `auth` | no | `bearer` (default: endpoints need a bearer token unless marked public) or `none` (every endpoint is public and the bearer scheme is left out). |
| `servers` | no | List of absolute `http(s)` URLs (no credentials, query or fragment; trailing `/` dropped). A `Servers:` / `Environments:` line in the body for the same URL adds its name and login settings to that entry; other body servers follow. |

Syntax: `key: value` (bare, `"double-quoted"` JSON string or `'single-quoted'`), `# comment` lines, lists as
`- item` lines or inline `[a, b]`. Quote a value that contains `: ` or ` #` (`migrate` does this for you).

If the file starts with `---` but there is no `avp:` key, it is not a header: a leading horizontal rule stays
a horizontal rule and the document is legacy.

Line numbers in errors and warnings are line numbers of the file, header included.

## The body

Unchanged from the template: a `# Title`, a `## Conventions` section, then one `## Section — `prefix/``
per module with endpoint tables (`Method | Endpoint | Permission | Description`) or `### POST `path``
headings with `Request:` / `Response `data`:` JSON blocks. See the short comment in the template.

## Strict-mode problems (strict documents only)

In a strict document these problems are reported as warnings `{code, message, lines: [first, last], endpoint?}`,
listed before the other warnings. `Parser\Validator::check()` and `Parser\DocumentParser::parse()` return them
separately as `errors`. The rest of the document is still parsed, so you see every problem at once.

Header problems:

| Code | When |
| --- | --- |
| `FRONT_MATTER_UNTERMINATED` | No closing `---` within the first 80 lines. |
| `FRONT_MATTER_SYNTAX` | A header line is not `key: value`, a list item, or a comment; a quoted value is malformed. |
| `FRONT_MATTER_UNKNOWN_KEY` | A key other than `avp`, `title`, `version`, `description`, `auth`, `servers`. |
| `FRONT_MATTER_DUPLICATE_KEY` | A key is set twice. |
| `FRONT_MATTER_UNSUPPORTED_VERSION` | `avp` is not `1`. |
| `FRONT_MATTER_MISSING_TITLE` | `title` is missing or empty. |
| `FRONT_MATTER_INVALID_VALUE` | `auth` is not `bearer`/`none`; `version` is malformed; `title` too long; a list where a single value is expected (or the reverse for `servers`). |
| `INVALID_SERVER` | A server URL in the header (or a `Servers:`/`Environments:` line) is not a clean absolute `http(s)` URL. |

Structural problems (the lenient parser only warns about these):

| Code | When |
| --- | --- |
| `NO_ENDPOINTS_FOUND` | The document has no endpoints. |
| `INVALID_METHOD` | An endpoint-table row has text in the Method column but no HTTP method (`GET POST PUT PATCH DELETE HEAD OPTIONS`). |
| `MISSING_ENDPOINT_PATH` | A row has a method but no endpoint path. |
| `INVALID_TABLE_ROW` | Any other table row that is not an endpoint (for example an empty row). |
| `UNRESOLVED_PREFIX` | A relative path in a section whose heading has no `` `prefix/` `` and no `Base path:`. |
| `DUPLICATE_ENDPOINT` | The same method and path is documented twice. |
| `UNRECOGNIZED_COLUMN` | A table column the parser does not read (it would silently drop the data). |
| `UNRESOLVED_PUBLIC_ENDPOINT` | "Every endpoint except `x` requires ..." names an endpoint that does not exist. |
| `UNBOUND_JSON_BLOCK` | A JSON block has no `Request`/`Response`/`Success` label, or a request body is given for `GET`/`HEAD`. |
| `INVALID_JSON_BLOCK` | A labelled JSON block cannot be read. |
| `DOT_SEGMENT_PATH` | A path contains a `.` or `..` segment. |
| `NOT_UTF8` | The file is not valid UTF-8. |

## Warnings (all documents)

Warnings are advice; they never make a strict document invalid. `LEGACY_FORMAT` (no header), `INFERRED_PERMISSION`
(permissions read from a reference table's List/Create/Update/Remove columns), and in legacy documents also
every code in the table above.

## Tools

- `avp check` lists the warnings (strict-mode problems included) without saving anything. In code,
  `Parser\Validator::check($markdown)` returns `{ok, strict, errors, warnings, statistics}`.
- `Parser\Migrator::migrate($markdown)` adds the header to a legacy document. It writes `title:` (from the
  first heading) and the servers the document already declares, keeps the body, line endings and BOM, and is
  idempotent. The migrated document produces the same OpenAPI; because it is now checked strictly, problems
  the lenient parser let through can show up as extra warnings.

## Parser layout

`MarkdownOpenApiParser::parse()` is a thin entry point over `src/Parser/`:

| Class | Stage |
| --- | --- |
| `FrontMatter` | Reads and validates the header. |
| `Lexer` | Text to structure: comments hidden, blocks (headings, tables, fences, paragraphs), a section tree; inline-text helpers. |
| `Resolver` | Sections to endpoints: conventions, servers, prefixes, tables and headings, bound details, permissions. |
| `OpenApiBuilder` | Endpoints to the OpenAPI document; schema inference and shorthand helpers. |
| `Diagnostics` | Collects warnings and, in strict mode, turns the structural ones into errors. |
| `DocumentParser` | Runs the stages. |
