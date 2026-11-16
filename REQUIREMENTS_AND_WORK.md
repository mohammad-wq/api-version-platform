# Local API Version Platform - Requirements and Work Plan

## Purpose

Ship this repository as a Packagist package that runs locally inside a PHP project. It turns a project README written in the platform's Markdown template into versioned API documentation and OpenAPI output.

This is a **single-project, single-user local tool**. It is not a hosted collaboration service.

## Initial-state assessment

The package already provides a useful foundation:

- `vendor/bin/avp-serve` launches a bundled React build and PHP backend.
- `MarkdownOpenApiParser` converts Markdown into OpenAPI 3.0.3.
- Version creation validates Markdown and calculates a normalized OpenAPI SHA-256 hash.
- The packaged frontend and backend are copies of a separate multi-user application.

The current implementation is not aligned with the intended product:

| Area | Current behavior | Required direction |
| --- | --- | --- |
| Storage | MySQL is mandatory; records refer to users and project membership. | Local package-owned storage; SQLite is the default. |
| Identity | Registration, login, JWT, profiles, users, roles, and project-members tables/routes are active. | No accounts, JWTs, roles, collaborators, or profile UI. |
| Project model | A user can own or join many remote-style projects. | One local project per configured workspace; optional multi-workspace support can be deferred. |
| Source document | Markdown can be uploaded for a version, but is not kept as the working README. | A configured README is the authoritative working file. |
| Change detection | A hash is stored for derived OpenAPI only. No baseline is linked to the README file. | Compare a normalized README hash with the last committed source hash. |
| UI | Compiled bundle includes login/collaboration/share/account flows. | Rebuild a local-only UI; remove those flows completely. |
| CLI | Only `init`, `check`, and serving exist. | Add template, status, version/commit, and history operations. |

Important implementation facts discovered during review:

- `backend/versions/modify/insertUpdateVersion.php` accepts Markdown but stores only the derived `openapi` JSON and its hash. The original document and its committed source hash are lost.
- Most backend routes call `requireAuth()` and depend on `users`, `projects`, `project_members`, or `request_history` tables.
- `resources/ui` is a compiled artifact. Editing it directly is not a maintainable way to remove pages; the source frontend must be changed and rebuilt before `scripts/sync.php` packages it.
- The current `runner/execute.php` is an unrestricted local HTTP request runner with disabled TLS verification. It is unrelated to local versioning and should be removed from the first release unless explicitly retained and secured.
- The repository has no schema installer/migration, even though the README requires a pre-existing MySQL schema.

## Implementation progress

The local core, CLI and template are implemented. The bundled UI is now the hosted web app's React build in its built-in local mode (`client/`, `npm run build:package`), served by `src/LocalApi.php`, which implements the web client's route names for one implicit user and one project on SQLite. Login, sign-up, profile, collaborators and project deletion are removed from the client entirely (the UI is always local; no mode detection). The Test APIs tab and its request history are retained: the runner verifies TLS, and the API only accepts loopback `Host` headers and JSON POST bodies. The former multi-user PHP backend is archived under `legacy/`. Remaining release work is automated test coverage and a Packagist release smoke test.

## Product requirements

### 1. Installation and configuration

- The package must install through Composer and expose `vendor/bin/avp` (the existing `avp-serve` name may remain as a compatibility alias during migration).
- `vendor/bin/avp init` must create:
  - `avp.config.php`, containing local paths and storage settings;
  - `.avp/` for package state, ignored by Git; and
  - a default README template if the configured README does not exist and the user requests it.
- The default storage driver is SQLite at `.avp/storage.sqlite`. No MySQL, PDO MySQL extension, database credentials, JWT secret, or web-server configuration may be required.
- Configuration must support at least `readme_path`, `storage_path`, `server.host`, and `server.port`.
- The package must provide a documented `--readme=path` override for commands that operate on the source document.

### 2. README template

- `avp template` writes the canonical Markdown template to stdout; `avp template --output=README.api.md` writes it to a file.
- The web UI must offer **Download README template**. It downloads the exact same template exposed by the CLI.
- The template must be versioned in package source (for example `resources/templates/api-readme.md`) and covered by parser tests.
- A user may configure an existing `README.md`; the package must never overwrite it without an explicit `--force` or confirmed UI action.

### 3. Local version model

- A version is an immutable snapshot with: numeric ID, version label, optional note, full Markdown source, normalized source hash, derived OpenAPI JSON, OpenAPI hash, creation time, and parser warnings/statistics.
- Version labels must be unique within the workspace. The first release may accept any non-empty label; SemVer validation is optional but recommended.
- Editing a historical version is not allowed. A correction creates a new version, preserving an auditable history.
- The latest version is the most recently committed snapshot, unless the user explicitly selects another snapshot as latest.
- Deletion should be soft-delete only if a restore command/UI is supplied; otherwise use a clear permanent-delete confirmation. The first release may omit deletion.

### 4. Git-like working-copy status

The configured README is the working copy. The platform must maintain a single **baseline source hash**: the normalized hash of the Markdown that was last successfully committed as a version.

- `avp status` must report one of: `uninitialized`, `clean`, `modified`, `missing`, or `invalid`.
- `clean` means the current normalized README hash equals the latest committed snapshot's `source_hash`.
- `modified` means the file exists and its normalized README hash differs from that baseline.
- `missing` means the configured file cannot be read; `invalid` means parsing/validation fails. Neither status may silently change the baseline.
- Newline differences (CRLF/LF) and a UTF-8 BOM must not create a false change. Define this normalization once in a shared `SourceHasher` service.
- A successful version commit—whether through CLI or UI—must atomically store the snapshot and advance the baseline. Immediately afterwards, `avp status` and the UI must show `clean`.
- The UI must re-check status on load, after a version commit, after a file is selected/imported, and on an intentional Refresh action. Automatic file watching is optional for the first release because browser access to arbitrary local files is restricted.

### 5. CLI workflow

Required commands:

```text
avp init [--readme=README.md] [--force]
avp template [--output=PATH] [--force]
avp status [--readme=PATH] [--json]
avp version create <label> [--note=TEXT] [--readme=PATH]
avp version list [--json]
avp version show <label> [--format=markdown|openapi|json]
avp serve [--host=127.0.0.1] [--port=8080]
```

- `version create` must refuse an unchanged document by default with a clear message such as “README is clean; no new version created.” A `--allow-unchanged` escape hatch is optional.
- Before writing, it must parse the README, show parser warnings, and fail without changing storage/baseline if validation fails.
- `--json` output must be stable and appropriate for CI usage.
- Exit codes: `0` success/clean, `1` validation or operational failure, `2` changed/uncommitted status (for `status` only).

### 6. Web UI workflow

The web UI is a local frontend only; it may call the package's local PHP API but must not depend on an external service.

- Landing/dashboard: README path, current status, latest version, last commit time, and actions to download template, refresh status, create version, and open history.
- Create version: show version label/note fields, a preview of parser warnings and endpoint count, and a prominent “commit current README” action.
- History: list snapshots with labels, timestamps, notes, hashes, parser statistics, Markdown/OpenAPI download, and a diff view between two versions (line diff is sufficient initially).
- Import/upload: allow the user to choose a Markdown file and explicitly either copy it to the configured README or commit it as a new snapshot. Do not imply continuous filesystem monitoring from the browser.
- Remove every login/register/logout/profile/users/admin/collaborators/project-members/share-link page, navigation item, API client, and token storage behavior.
- Do not display collaboration roles, user avatars, or owner/editor/viewer concepts.

### 7. Local HTTP API

Keep the server router. The API under `/api` is the web client's route set, implemented locally in `src/LocalApi.php` (`/api/local/status`, `/api/local/readme`, `/api/local/readme/save`, and the hosted backend's `.php` route names for projects, versions, the request runner and request history). Scripts and CI use the CLI's `--json` output rather than HTTP.

- No authorization header, cookies, JWT, CORS credential mode, user identity, or user-provided database credentials.
- Bind to `127.0.0.1` by default. If `--host=0.0.0.0` is used, display a warning that the unauthenticated local tool should not be exposed on an untrusted network.
- Keep inputs size-limited and return structured JSON errors.
- The request runner is retained for the Test APIs tab; it verifies TLS, and the API accepts only loopback `Host` headers and JSON POST bodies.

### 8. Storage and migrations

Use SQLite through PDO with `ext-pdo_sqlite` as the only database extension requirement.

Suggested schema:

```sql
CREATE TABLE metadata (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

CREATE TABLE versions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  label TEXT NOT NULL UNIQUE,
  note TEXT,
  markdown TEXT NOT NULL,
  source_hash TEXT NOT NULL,
  openapi_json TEXT NOT NULL,
  openapi_hash TEXT NOT NULL,
  parser_warnings_json TEXT NOT NULL,
  parser_statistics_json TEXT NOT NULL,
  created_at TEXT NOT NULL
);
```

- Store `schema_version` in `metadata` and run idempotent migrations during `init`, `check`, and normal command startup.
- Use transactions for a version insert plus baseline update.
- The baseline may be derived from the newest version's `source_hash`; storing `last_committed_version_id` in `metadata` makes “latest” explicit and avoids ambiguity after future features.
- SQLite database and any `.avp/` exports must be excluded from the distributed package and recommended in the host project's `.gitignore`.

## Non-functional requirements

- PHP 8.1+ support; add `ext-pdo_sqlite` and remove `ext-pdo_mysql`, `ext-curl`, and JWT requirements unless another retained feature needs them.
- The source of truth must live in this repository. Do not ship only compiled UI output without the frontend source or reproducible build instructions.
- Unit tests must cover Markdown parsing, source normalization/hashing, status transitions, CLI exit codes, SQLite migrations, and atomic commit behavior.
- Integration tests must cover CLI commit → clean status and UI/API commit → clean status.
- Package release metadata must use an OSI-compatible license if publishing publicly on Packagist; `proprietary` is technically accepted by Composer but conflicts with typical public-package expectations.
- Documentation must clearly state that this is a local developer tool and must not be exposed publicly without adding authentication and transport security.

## Recommended implementation order

### Phase 0 — Decide and protect scope

1. Confirm one workspace per installed project for v1.
2. Preserve the existing README change separately; it is currently modified in the working tree.
3. Archive or remove the inherited multi-user backend only after the replacement API is covered by tests.

### Phase 1 — Core local domain

1. Add `Storage`, `MigrationRunner`, `VersionRepository`, `SourceHasher`, `ReadmeService`, and `VersionService` under `src/`.
2. Add the SQLite schema/migrations and `.avp` path resolution.
3. Extract Markdown parsing behind a service and return consistent parse results.
4. Implement atomic create-version behavior and the status state machine.
5. Add automated tests for all status transitions.

### Phase 2 — CLI and template

1. Replace the current parser in `bin/avp-serve` with a command dispatcher, retaining `avp-serve` as an alias if desired.
2. Implement `init`, `template`, `status`, and `version` subcommands.
3. Add the canonical README template and parser fixtures.
4. Update the public README with the install-to-first-version quick start.

### Phase 3 — Local API and server hardening

1. Replace `/backend/*` routing with `/api/*` endpoints backed by the new services.
2. Delete authentication, user, collaborator, share, profile, request-history, and remote-project routes/configuration.
3. Remove unsafe request execution and permissive CORS behavior.
4. Add endpoint tests using a temporary workspace and SQLite database.

### Phase 4 — Rebuild the UI

1. Obtain or restore the React source; do not reverse-edit `resources/ui/assets/index-*.js`.
2. Remove account/collaboration UI and build the local dashboard, commit flow, history, downloads, and diff.
3. Generate the bundled files into `resources/ui/` using the existing sync/release flow.
4. Verify that a UI commit changes status from `modified` to `clean` without a page reload.

### Phase 5 — Release readiness

1. Remove obsolete Composer dependencies and configuration examples.
2. Add a CI matrix for supported PHP versions, linting, unit tests, and package installation smoke test.
3. Add `CHANGELOG.md`, license, security policy, issue templates, and Packagist/GitHub release instructions.
4. Tag a pre-release, install it into a blank PHP project, and verify the full CLI and browser workflow.

## Acceptance scenarios

1. In a blank PHP project, install the package, run `avp init`, download or generate the template, add one endpoint, and run `avp version create v1.0.0`. `avp status` reports `clean`.
2. Edit the configured README. `avp status` returns exit code `2` and reports `modified`; the UI shows a pending change.
3. Commit the modified README from the UI as `v1.1.0`. The version stores the exact Markdown source and generated OpenAPI; the UI immediately reports `clean` and the CLI also reports `clean`.
4. Edit the README again and commit using the CLI. Reloading the UI shows the new latest version and `clean` state.
5. A malformed README produces parser diagnostics and creates no version or baseline update.
6. No route, navigation item, schema, or configuration setting requires login, users, collaborators, JWT, MySQL, or a remote service.

## Explicit v1 exclusions

- Multi-user collaboration, authentication, roles, share links, hosted synchronization, and cloud storage.
- Automatic filesystem watching in the browser.
- Live API request execution, avatars, account management, and user request history.
- Production/public network hosting.

