# Changelog

## 1.3.0 - 2026-11-17

Beta. This release changes how versions are stored and how documents are parsed; see **Upgrading** below.

### Upgrading from 1.2.x

- Run `composer update mohammad-wq/api-version-platform`. The first command you run afterwards imports an existing `.avp/storage.sqlite` into the new JSON files automatically (needs `pdo_sqlite` that one time; the old file is left in place and can be deleted afterwards).
- Your API document keeps working. It gets a `LEGACY_FORMAT` notice; `avp migrate --write` adds the new front-matter header.
- A custom `paths.storage` that points at a `.sqlite` file now becomes a folder next to it (`data/my.sqlite` is imported into `data/my/`).
- The request history keeps only method, URL, status and timing (newest 200).
- `ext-pdo`, `ext-pdo_sqlite` and `composer-runtime-api` are no longer required.

### Added and changed

- **No sign-in.** The UI is always the local single-project app: the login/sign-up, profile and collaborators screens and their API client code are removed (previously a failed startup check could fall back to the hosted login screen).
- The bundled README template is much shorter (89 lines instead of 431): a front-matter header, a one-comment how-to, and a few example endpoints.
- `scripts/sync-hosted.php` copies the parser into the hosted web backend (`php_backend`), so the web version parses documents exactly like the package. The parser avoids PHP 8.1-only features so it also runs on PHP 8.0.
- Robustness: an invalid JSON request body is a `400` with a clear message (it used to surface as "name is required"); a known route called with the wrong method is a `405`; unknown addresses such as stale bookmarks redirect to the app and missing files are a real `404` (an unknown multi-segment URL used to render a blank page). Cloud calls have a 20 s timeout and cannot hit PHP's time limit; a cloud server that redirects (for example http to https) is reported with the address to use; an `http://` cloud URL on another machine shows a cleartext-password warning. The JSON store retries a read or replace that collides with another process (Windows) instead of reporting an empty list, and `--host=` with no value is rejected.
- Fixed: parsing a document with the new front-matter header crashed on a PHP without the `mbstring` extension (the package now runs its whole test suite with no optional extensions). The same fix is in the copy used by the hosted backend (`scripts/sync-hosted.php`).
- A cloud server that does not answer is reported as "did not answer within 20 seconds" instead of a generic stream error.
- The built UI in `resources/ui/` is marked as binary in `.gitattributes`, so its bytes are never altered by line-ending conversion; `.github/` is no longer shipped in Composer archives. The default Vite dev proxy no longer names an internal address.
- The History tab is just the list of requests and a Clear button.
- **Strict format.** A document that starts with an `avp: 1` front-matter header (`title`, optional `version`, `description`, `servers`, `auth`) is parsed strictly: structural problems are reported as line-numbered warnings (they never block saving a version). `avp check` lists them, `avp migrate` adds the header to an older document; documents without a header still parse leniently with a `LEGACY_FORMAT` notice. The bundled template uses the new format. See `docs/FORMAT.md`.
- **Parser split** into `Parser\FrontMatter`, `Lexer`, `Resolver`, `OpenApiBuilder`, `DocumentParser`, `Validator` and `Migrator`; `MarkdownOpenApiParser` is a facade with the same output for existing documents (verified on real documents and mutated inputs). The parser tests grew from 50 to 152 checks.
- `MarkdownOpenApiParser` is now in the `Mohammad\ApiVersionPlatform` namespace and autoloaded (PSR-4); `src/autoload.php` replaces the manual `require_once` calls.
- `avp serve` refuses a non-loopback host unless `--i-know-what-i-am-doing` is passed.
- The API tester follows redirects itself (at most 5) and refuses to follow a redirect from a public host to a loopback or private address.
- State folders are created with mode 0775 instead of 0777.
- **No database extension.** Versions, request history and project settings are plain JSON files in `.avp/` (atomic writes, file lock). `ext-pdo` and `ext-pdo_sqlite` are no longer required, nor `composer-runtime-api`: the package has no dependencies beyond PHP. An existing `.avp/storage.sqlite` is imported once when `pdo_sqlite` is available. The request history keeps only the method, URL, status and timing (newest 200).
- **Publish from the terminal.** `avp login` (asks for email and password; the password is hidden in a real terminal), `avp logout` and `avp publish`. `publish` signs in if needed, uses the project's name as the cloud project (created when missing) and resolves the version itself: a changed document becomes the next local label (skipping labels the cloud project already has) and is published, an unchanged one publishes the newest local version, `avp publish v2.0.0` names it, `--project` and `--note` override the defaults. `AVP_CLOUD_EMAIL` / `AVP_CLOUD_PASSWORD` sign in without a prompt for scripts and CI. The sign-in in `.avp/cloud.json` is shared with the web UI.
- The Cloud tab's password field has a show/hide button.
- The README is reorganized around the workflow (install, write the document, version, publish).
- **Cloud tab**: sign in to the hosted platform (default `https://qa.erpsite.org.pk/test_avp/`), publish a local version to a cloud project (or create one), browse your cloud projects and import their versions. New config key `cloud.url` / env `AVP_CLOUD_URL`.
- `avp init --readme=PATH` pins the API document; `--ci` on `status` and `version create` fails on parser warnings.
- `client/` (React source) and `tests/` are now included in Composer archives.
- CI: GitHub Actions runs the lint, parser and storage tests and the client tests.

### 1.1.1

- Fix: the web UI showed a blank page (`usePersistentState is not defined`) on the Versions tab, which opens by default. The bundled UI is rebuilt. This also affected 1.0.5 and 1.1.0.
- Docs: the Composer shortcut now disables Composer's 300-second script timeout, which otherwise stopped `composer avp serve` after five minutes.

### 1.1.0

- **Zero setup**: no config file is needed. The API document is auto-detected (`README.md`, `API.md`, `docs/API.md`, `docs/README.md`). `avp init` also writes `API.md` from the template when the project has none, and `.avp/` ignores itself in git.
- `avp serve` moves to the next free port when 8080 is busy, prints the URL only once the server answers, and adds `--open`.
- The CLI and the web server always read the same files, including when `serve` runs from a subfolder without a config file.
- Options accept `--name value` as well as `--name=value`. Unknown options, missing values and invalid `--format`/`--port` values are reported instead of ignored. Adds `avp --version`.
- Non-UTF-8 (Windows-1252) API documents can be versioned, with a `NOT_UTF8` warning.
- The local API rejects malformed request fields with `400` instead of returning PHP warnings in the JSON, and never prints PHP errors into responses.
- Version labels are limited to 50 characters everywhere (CLI, banner, import).
- Clear errors for invalid config values (wrong types, ports outside 1–65535) and unreadable databases. Concurrent writers wait for the database lock instead of failing.
- The parser warns about `.`/`..` path segments (`DOT_SEGMENT_PATH`).
- Composer archives no longer ship `tests/`, which needed fixtures that aren't in the archive.
