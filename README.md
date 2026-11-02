# mohammad-wq/api-version-platform

Version your API documentation locally. Write your API in a Markdown file using the bundled template; the platform turns it into OpenAPI 3.0, keeps every version in a local SQLite database, and gives you the full API Version Platform web UI (version history, diffs, OpenAPI viewer and an API tester) on `localhost`.

No Node.js, MySQL, accounts, web server or configuration is needed.

> Local developer tool. It runs on PHP's built-in server, has no authentication, and binds to `127.0.0.1` by default. Do not expose it on a public network.

## Requirements

- PHP 8.1+ with `pdo_sqlite` (enabled in most PHP builds)
- `openssl`, only to test HTTPS APIs from the Test APIs tab

## Quick start

```bash
composer require --dev mohammad-wq/api-version-platform
vendor/bin/avp init     # one-time setup: config, local state folder, and API.md from the template
vendor/bin/avp serve    # open the URL it prints
```

That's it. `init` creates:

- `avp.config.php`: optional settings, already filled in with working defaults.
- `.avp/`: the local version database. It contains its own `.gitignore`, so it never ends up in git and you don't need to edit yours.
- `API.md`: the template, written only when your project has no API document yet. Replace the example endpoints with your own. If your `README.md` (or `API.md`, `docs/API.md`) already documents endpoints, it is used instead and nothing is written.

`init` is optional: every command also works with no setup at all.

Save your first version, from the web UI's banner or from the CLI:

```bash
vendor/bin/avp version create v1.0.0 --note "First version"
```

Edit your API document, and the UI shows what changed and offers to save it as the next version.

**Shells.** `vendor/bin/avp` works in macOS/Linux terminals, Git Bash, WSL and Windows PowerShell. In Windows `cmd.exe`, use backslashes: `vendor\bin\avp`.

### Optional: a Composer shortcut

To type `composer avp` instead, add a script to your project's `composer.json`:

```json
{
    "scripts": {
        "avp": [
            "Composer\\Config::disableProcessTimeout",
            "@php vendor/bin/avp"
        ]
    }
}
```

```bash
composer avp serve
composer avp status
composer avp -- version create v1.0.0 --note="First version"
```

Keep the `disableProcessTimeout` line. Composer stops any script after 300 seconds by default, and without that line `composer avp serve` would shut the UI down after five minutes.

Put `--` after `avp` whenever the command has options (anything starting with `--`). Without it, Composer takes the options for itself. Depending on your Composer version it either stops with `The "--note" option does not exist`, or silently drops the option. Running `vendor/bin/avp` directly never has this problem.

## CLI

```text
avp init [--force]
avp serve [--port=8080] [--host=127.0.0.1] [--open]
avp status [--readme=PATH] [--json]
avp version create <label> [--note=TEXT] [--readme=PATH] [--allow-unchanged]
avp version list [--json]
avp version show <label> [--format=json|openapi|markdown]
avp template [--output=PATH] [--force]
avp --version
```

- Running `avp` with no command starts the server.
- Options take their value either way: `--note="Text"` or `--note "Text"`. Unknown options are reported, not ignored.
- Every command accepts `--config=PATH`. `avp-serve` is kept as an alias of `avp`.
- `serve` uses port 8080. If that port is busy it moves to the next free one and tells you, unless you chose the port with `--port`, `AVP_PORT` or the config file. `--open` opens the UI in your browser.
- Version labels are 1–50 characters, the same in the CLI and the web UI.

`avp status` works like `git status` for your API document:

| State | Meaning | Exit code |
| --- | --- | --- |
| `uninitialized` | Document is valid but no version was created from it yet | 0 |
| `clean` | Document matches the last version created from it | 0 |
| `modified` | Document changed since that version | 2 |
| `missing` / `invalid` | Document cannot be read, or has no endpoints | 1 |

Line-ending (CRLF/LF) and UTF-8 BOM differences do not count as changes. A document saved in a non-UTF-8 encoding (such as Windows "ANSI") is read as Windows-1252 and versioned with a `NOT_UTF8` warning. Save it as UTF-8 to be sure every character is kept.

### Troubleshooting

- **`vendor/bin/avp` is not recognized.** In `cmd.exe`, use `vendor\bin\avp`. In PowerShell, `.\vendor\bin\avp` works too.
- **A Composer script ignores your options or says `The "--output" option does not exist`.** Put `--` after the script name: `composer avp -- template --output=API.md`.
- **`Command "avp" is not defined.`** Your project's `composer.json` has no `avp` script. Add it as shown above, or run `vendor/bin/avp` directly.
- **`MISSING: No API document found`.** Run `vendor/bin/avp init` to create `API.md` from the template, or point `paths.readme` in `avp.config.php` at your file.

## Web UI

`avp serve` opens the same interface as the hosted platform, running as a single local project:

- **README banner**: when your API document changes, the UI shows what changed (endpoints added, removed or changed) and saves it as a new version in one click. The suggested version number is prefilled but editable.
- **Versions**: upload or paste README Markdown or OpenAPI JSON, review it, and save it as a version; view, edit, compare and delete versions.
- **Test APIs**: send requests to your API using environments (base URLs) you define. Requests are sent by the local PHP server, so CORS does not apply.
- **History**: requests sent from the tester, stored locally.

Accounts, collaborators and share links belong to the hosted platform and are not part of the package.

Versions created from the API document (CLI or banner) store the exact Markdown source and move the `avp status` baseline. Versions imported or edited in the UI store OpenAPI only and do not change `avp status`.

## Configuration

Nothing needs configuring. `avp.config.php` (created by `avp init`, see `config/avp.config.example.php`) lets you override the defaults:

```php
return [
    'paths' => [
        'readme' => null,                     // null = auto-detect; or e.g. 'docs/my-api.md'
        'storage' => '.avp/storage.sqlite',
    ],
    'server' => [
        'host' => '127.0.0.1',
        // 'port' => 8080,                    // set to pin the port (no automatic fallback)
    ],
];
```

With `readme` set to `null` (or with no config file), the first of `README.md`, `API.md`, `docs/API.md` and `docs/README.md` that contains endpoints is used. Relative paths are resolved from the config file's folder, or from the project root when there is no config file. Environment variables override the file: `AVP_README_PATH`, `AVP_STORAGE_PATH`, `AVP_HOST`, `AVP_PORT`.

## How it works

```
bin/avp               CLI: init / serve / status / version / template
src/Config.php        defaults, avp.config.php, AVP_* env vars, API document auto-detection
src/router.php        /api/* -> local API, static UI files, everything else -> index.html
src/LocalApi.php      the web client's API routes for one local user and project
src/VersionService.php  README status, version create/import/edit/delete
src/Storage.php       SQLite schema and migrations
src/MarkdownOpenApiParser.php  README Markdown -> OpenAPI 3.0.3
resources/ui/         pre-built web client (generated, committed)
resources/templates/  README template served by `avp template`, `avp init` and the UI
client/               React source shared with the hosted web app (not in Composer archives)
```

## Updating the bundled UI (maintainers)

The UI in `resources/ui/` is the web app's React build in local mode. Do not edit it by hand. After changing the frontend in `client/`:

```bash
cd client
npm install
npm run build:package          # builds into client/dist-package with VITE_API_BASE=./api
cd ..
php scripts/sync.php --ui=client/dist-package
cp client/src/templates/API_README_TEMPLATE.md resources/templates/api-readme.md   # if the template changed
php scripts/lint.php
composer test
```

`npm run build` (the hosted web build) is unaffected. Then commit, tag a new version (`git tag v1.2.0 && git push --tags`) and consumers run `composer update`. The version comes from the tag; `composer.json` has no `version` field, so there is nothing else to bump.

## Changelog

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

## License

MIT
