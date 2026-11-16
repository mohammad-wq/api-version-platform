# mohammad-wq/api-version-platform

Version your API documentation locally. Write your API in a Markdown file using the bundled template; the platform turns it into OpenAPI 3.0, keeps every version in plain JSON files in `.avp/`, and gives you the full API Version Platform web UI (version history, diffs, OpenAPI viewer and an API tester) on `localhost`.

No Node.js, MySQL, accounts, web server or configuration is needed.

> Local developer tool. It runs on PHP's built-in server, has no authentication, and binds to `127.0.0.1` by default. Do not expose it on a public network.

## Requirements

- PHP 8.1+ (the `json` extension is built in). No database extension and no Composer dependencies.
- `openssl`, only to test HTTPS APIs from the Test APIs tab or to publish to the HTTPS cloud

## Quick start

```bash
composer require --dev mohammad-wq/api-version-platform
vendor/bin/avp init     # one-time setup: config, local state folder, and API.md from the template
vendor/bin/avp serve    # open the URL it prints
```

That's it. `init` creates:

- `avp.config.php`: optional settings, already filled in with working defaults.
- `.avp/`: the local state: `versions.json` plus one JSON file per version, and the request history. Versions from an older release (`.avp/storage.sqlite`) are imported once, automatically, when PHP has `pdo_sqlite`. It contains its own `.gitignore`, so it never ends up in git and you don't need to edit yours.
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
composer avp -- template --output=API.md --force
```

Keep the `disableProcessTimeout` line. Composer stops any script after 300 seconds by default, and without that line `composer avp serve` would shut the UI down after five minutes.

Put `--` after `avp` whenever the command has options (anything starting with `--`). Without it, Composer takes the options for itself. Depending on your Composer version it either stops with `The "--note" option does not exist`, or silently drops the option. For example, `composer avp template --output=API.md` loses `--output`, so the template is printed to the terminal instead of being written to `API.md`. Running `vendor/bin/avp` directly never has this problem.

## CLI

```text
avp init [--force] [--readme=PATH]
avp serve [--port=8080] [--host=127.0.0.1] [--open]
avp status [--readme=PATH] [--json] [--ci]
avp version create <label> [--note=TEXT] [--readme=PATH] [--allow-unchanged] [--ci]
avp version list [--json]
avp version show <label> [--format=json|openapi|markdown]
avp check [--readme=PATH] [--json]
avp migrate [--readme=PATH] [--write]
avp template [--output=PATH] [--force]
avp --version
```

- Running `avp` with no command starts the server.
- Options take their value either way: `--note="Text"` or `--note "Text"`. Unknown options are reported, not ignored.
- Every command accepts `--config=PATH`. `avp-serve` is kept as an alias of `avp`.
- `serve` uses port 8080. If that port is busy it moves to the next free one and tells you, unless you chose the port with `--port`, `AVP_PORT` or the config file. `--open` opens the UI in your browser.
- `init --readme=PATH` pins a specific API document in `avp.config.php` (useful when both `README.md` and `API.md` contain endpoints) and does not write `API.md`.
- `check` lists what the parser warns about, without saving anything. `migrate` adds the strict-format header to an older document (a dry run unless `--write` is given).
- A document with an `avp: 1` header is parsed strictly: structural problems (unknown header keys, duplicate endpoints, bad rows, ...) are reported as warnings with line numbers. They never stop you from saving a version. Documents without the header still work but get a `LEGACY_FORMAT` notice. See [docs/FORMAT.md](docs/FORMAT.md).
- `--ci` makes `status` and `version create` exit 1 when the document has parser warnings.
- `serve` refuses a non-loopback `--host` (such as `0.0.0.0`) unless you also pass `--i-know-what-i-am-doing`: the tool has no authentication and its API tester can reach any URL the machine can.
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
- **A Composer script ignores your options (e.g. `composer avp template --output=...` prints the template instead of writing the file) or says `The "--output" option does not exist`.** Put `--` after the script name: `composer avp -- template --output=API.md`.
- **`Command "avp" is not defined.`** Your project's `composer.json` has no `avp` script. Add it as shown above, or run `vendor/bin/avp` directly.
- **`MISSING: No API document found`.** Run `vendor/bin/avp init` to create `API.md` from the template, or point `paths.readme` in `avp.config.php` at your file.

## Web UI

`avp serve` opens the same interface as the hosted platform, running as a single local project:

- **README banner**: when your API document changes, the UI shows what changed (endpoints added, removed or changed) and saves it as a new version in one click. The suggested version number is prefilled but editable.
- **Versions**: upload or paste README Markdown or OpenAPI JSON, review it, and save it as a version; view, edit, compare and delete versions.
- **Test APIs**: send requests to your API using environments (base URLs) you define. Requests are sent by the local PHP server, so CORS does not apply. Redirects are followed (at most 5), but a public host cannot redirect a request to a loopback or private address.
- **Cloud**: sign in to the hosted platform, publish versions to a cloud project and import cloud versions (see below).
- **History**: requests sent from the tester, stored locally, with a Clear button.

There is no sign-in, user or collaborator screen: the package is a single-user local tool.

Versions created from the API document (CLI or banner) store the exact Markdown source and move the `avp status` baseline. Versions imported or edited in the UI store OpenAPI only and do not change `avp status`.

## Publishing to the cloud

The **Cloud** tab connects the local project to the hosted platform (by default `https://qa.erpsite.org.pk/test_avp/`; change it in the sign-in form, `cloud.url` in `avp.config.php` or `AVP_CLOUD_URL`):

1. Sign in with your account on the hosted platform. The sign-in token is kept in `.avp/cloud.json` (readable only by you, never sent to the browser) and removed by **Sign out** or when the cloud rejects it.
2. **Publish a version**: pick a local version and a cloud project (or create one). The version's OpenAPI document is sent as a new cloud version; publishing a name that already exists in that project is refused by the cloud.
3. **Your cloud projects**: browse the projects you can access and their versions, and **Import** one as a local version.

The local server makes these calls, so cloud CORS settings do not matter. Accounts are created on the hosted platform; the package only signs in.

## Configuration

Nothing needs configuring. `avp.config.php` (created by `avp init`, see `config/avp.config.example.php`) lets you override the defaults:

```php
return [
    'paths' => [
        'readme' => null,                     // null = auto-detect; or e.g. 'docs/my-api.md'
        'storage' => '.avp',                  // state folder (JSON files)
    ],
    'server' => [
        'host' => '127.0.0.1',
        // 'port' => 8080,                    // set to pin the port (no automatic fallback)
    ],
];
```

With `readme` set to `null` (or with no config file), the first of `README.md`, `API.md`, `docs/API.md` and `docs/README.md` that contains endpoints is used. Relative paths are resolved from the config file's folder, or from the project root when there is no config file. Environment variables override the file: `AVP_README_PATH`, `AVP_STORAGE_PATH`, `AVP_HOST`, `AVP_PORT`, `AVP_CLOUD_URL`.

## How it works

```
bin/avp               CLI: init / serve / status / check / migrate / version / template
src/Config.php        defaults, avp.config.php, AVP_* env vars, API document auto-detection
src/router.php        /api/* -> local API, static UI files, everything else -> index.html
src/LocalApi.php      the web client's API routes for one local user and project
src/VersionService.php  README status, version create/import/edit/delete
src/Storage.php       JSON-file store: versions, request history, project metadata (atomic writes, file lock)
src/Cloud.php         client for the hosted platform: sign in, projects, publish and import versions
src/MarkdownOpenApiParser.php  facade: README Markdown -> OpenAPI 3.0.3
src/Parser/           FrontMatter, Lexer, Resolver, OpenApiBuilder, DocumentParser, Validator, Migrator
src/autoload.php      PSR-4 loader used when Composer's autoloader is not present
resources/ui/         pre-built web client (generated, committed)
resources/templates/  README template served by `avp template`, `avp init` and the UI
client/               React source of the UI (included in Composer archives, so the bundle can be audited and rebuilt)
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

`npm run build` (the hosted web build) is unaffected.

**Hosted web version.** The multi-user backend (`php_backend`) uses the same parser. After changing `src/Parser/` or `src/MarkdownOpenApiParser.php`, copy it over with `php scripts/sync-hosted.php --backend=PATH/TO/php_backend`; this writes `includes/parser/` and `includes/markdownOpenApiParser.php` (which keeps the global `MarkdownOpenApiParser` class the hosted endpoints call). The parser code runs on PHP 8.0+. The hosted client's `src/templates/API_README_TEMPLATE.md` and `test-fixtures/API_README_TEMPLATE.openapi.json` are copies of this package's, so refresh them too. Then commit, tag a new version (`git tag v1.2.0 && git push --tags`) and consumers run `composer update`. The version comes from the tag; `composer.json` has no `version` field, so there is nothing else to bump.

## Changelog

See [CHANGELOG.md](CHANGELOG.md). The package is in beta: the parser and the local API may still change between minor versions.

## License

MIT
