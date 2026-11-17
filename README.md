# mohammad-wq/api-version-platform

Keep your API documentation in one Markdown file, version it, and publish it to the cloud with one command.

- Write your API in `README.md` or `API.md` (a short template is included).
- Every version is turned into an **OpenAPI 3.0** document and kept in a `.avp/` folder next to your code.
- A local web UI shows version history, diffs, the OpenAPI JSON and an API tester.
- `avp publish` signs in to the cloud platform and puts the version there.

No Node.js, database, web server or configuration is needed. Only PHP 8.1+ (and `openssl` if you use HTTPS APIs or the HTTPS cloud).

> Local developer tool. It runs on PHP's built-in server, has no authentication, and listens on `127.0.0.1` only. Do not expose it on a public network.

## Contents

[Install](#install) · [Five-minute walkthrough](#five-minute-walkthrough) · [Writing your API document](#writing-your-api-document) · [Publishing to the cloud](#publishing-to-the-cloud) · [Command reference](#command-reference) · [Web UI](#web-ui) · [Configuration](#configuration) · [Troubleshooting](#troubleshooting)

## Install

```bash
composer require --dev mohammad-wq/api-version-platform
```

The command-line tool is `vendor/bin/avp` (Windows `cmd.exe`: `vendor\bin\avp`). Everything below also works without any setup step.

## Five-minute walkthrough

```bash
vendor/bin/avp init            # optional: creates avp.config.php, .avp/ and, if you have none, API.md from the template
# edit API.md (or your README.md) so it describes your API
vendor/bin/avp check           # lists anything the parser is unsure about (never blocks you)
vendor/bin/avp version create v1.0.0 --note "First version"
vendor/bin/avp serve --open    # web UI: versions, diffs, API tester
vendor/bin/avp publish         # sign in (first time) and publish to the cloud
```

After that, the daily loop is: **edit the document → `avp publish`**. If the document changed, `publish` makes the next version for you. You can also save versions from the banner the web UI shows when your document changes.

`avp init` creates:

- `avp.config.php`: optional settings, filled in with working defaults.
- `.avp/`: local state (versions, request history, cloud sign-in). It ignores itself in git, so you never edit your `.gitignore`.
- `API.md`: only when your project has no API document yet. If `README.md`, `API.md`, `docs/API.md` or `docs/README.md` already documents endpoints, that file is used and nothing is written.

## Writing your API document

The document is plain Markdown. Start from the template (`avp template --output=API.md`) and replace the examples. The essentials:

```markdown
---
avp: 1
title: Notes API
---

# Notes API

One sentence about your API.

## Notes — `notes/`

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| GET | `fetch/getNotes.php?search=` | true | List notes. |
| POST | `modify/saveNote.php` — `{title, body?}` | true | Create a note. |
```

- The `---` header (`avp: 1`, `title`) switches on **strict mode**: problems such as duplicate endpoints or unknown header keys are listed as warnings with line numbers. Warnings never stop you from saving or publishing a version.
- A section heading `## Name — `prefix/`` sets the URL prefix for the endpoints under it.
- Document endpoints with a table (as above) or with headings such as `### POST `auth/login.php`` followed by `Request:` and `Response `data`:` JSON blocks.
- Put endpoint paths in `backticks`. `{id}` in a path is a path parameter. `list.php?status=a|b&page=1` declares query parameters. `— {title, body?}` after a path declares a JSON body (`?` marks an optional field).
- Mark every endpoint with `Auth: true` or `Auth: false`.

The full format and every warning code are in [docs/FORMAT.md](docs/FORMAT.md). An older document without the header still works (you get a `LEGACY_FORMAT` notice); `avp migrate --write` adds the header for you.

## Publishing to the cloud

The cloud platform is the hosted version of this tool (default `https://qa.erpsite.org.pk/test_avp/`). You need an account on it; the package only signs in.

### From the terminal

```bash
vendor/bin/avp login           # asks for your email and password (the password is not shown)
vendor/bin/avp publish         # publishes; asks you to sign in first if needed
```

`avp publish` decides everything it can:

| You run | What happens |
| --- | --- |
| `avp publish` | The **cloud project is named after your project** (found by name, created if missing). If your document changed since the last version, a new local version is created with the **next label** (`v1.0.1`, skipping any label the cloud project already has) and published. If nothing changed, the newest local version is published. If it is already on the cloud, nothing happens. |
| `avp publish v2.0.0` | Publishes the local version `v2.0.0`; if there is none, it is created from your document first. |
| `avp publish --note "Added orders"` | Same, with a note on the version. |
| `avp publish --project "Shop API"` | Use another cloud project name instead of your project's name. |

The project name is the one shown in the web UI (editable under *Edit details*), otherwise your project's folder name. A version name that already exists in the cloud project is refused with a message; publish under another name.

Other commands: `avp logout` forgets the sign-in. Your sign-in is saved in `.avp/cloud.json`, so you only sign in once, and the web UI shares it.

**Scripts and CI.** Set `AVP_CLOUD_EMAIL` and `AVP_CLOUD_PASSWORD` and `avp publish` signs in by itself (passwords are never accepted as command-line options). Piped input also works: `printf 'me@x.com\nsecret\n' | avp login`.

Use another server with `avp login --url=https://your-host/app/`, `cloud.url` in `avp.config.php`, or `AVP_CLOUD_URL`.

### From the web UI

Open the **Cloud** tab in `avp serve`: sign in (the eye icon shows or hides the password), pick a local version and a cloud project (or create one), and click **Publish to cloud**. The same tab lists your cloud projects and their versions, and **Import** copies a cloud version into your local project.

## Command reference

```text
avp init [--force] [--readme=PATH]
avp serve [--port=8080] [--host=127.0.0.1] [--open]
avp status [--readme=PATH] [--json] [--ci]
avp check [--readme=PATH] [--json] [--ci]
avp migrate [--readme=PATH] [--write]
avp version create <label> [--note=TEXT] [--readme=PATH] [--allow-unchanged] [--ci]
avp version list [--json]
avp version show <label> [--format=json|openapi|markdown]
avp login [--url=URL] [--email=EMAIL]
avp logout
avp publish [label] [--note=TEXT] [--project=NAME]
avp template [--output=PATH] [--force]
avp --version
```

- `avp` with no command starts the server. Every command accepts `--config=PATH`. Options take `--name=value` or `--name value`; unknown options are reported.
- `serve` uses port 8080 and moves to the next free port if it is busy (unless you chose the port with `--port`, `AVP_PORT` or the config). `--open` opens the browser. A non-loopback `--host` (like `0.0.0.0`) is refused unless you also pass `--i-know-what-i-am-doing`.
- `check` lists parser warnings without saving anything. `--ci` (on `check`, `status` and `version create`) exits 1 when there are warnings, except the `LEGACY_FORMAT` notice.
- `init --readme=PATH` pins the API document in `avp.config.php` (useful when both `README.md` and `API.md` contain endpoints).
- Version labels are 1–50 characters, in the CLI and the UI alike.

`avp status` works like `git status` for your document:

| State | Meaning | Exit code |
| --- | --- | --- |
| `uninitialized` | Valid document, no version created from it yet | 0 |
| `clean` | Matches the last version created from it | 0 |
| `modified` | Changed since that version | 2 |
| `missing` / `invalid` | Cannot be read, or has no endpoints | 1 |

Line endings (CRLF/LF) and a UTF-8 BOM do not count as changes. A non-UTF-8 document (Windows "ANSI") is read as Windows-1252 with a `NOT_UTF8` warning; save it as UTF-8 to keep every character.

### Optional: a Composer shortcut

To type `composer avp` instead of `vendor/bin/avp`, add to your `composer.json`:

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

Keep the `disableProcessTimeout` line (Composer otherwise stops scripts after 300 seconds, which would end `serve`). Put `--` after `avp` when the command has options: `composer avp -- publish --note="Added orders"`. Running `vendor/bin/avp` directly never needs this. Composer scripts cannot ask questions, so `composer avp login` and `composer avp publish` stop with a message when they need to prompt. Run `vendor/bin/avp login` once in a terminal (the sign-in is saved), after which `composer avp -- publish` works; or set `AVP_CLOUD_EMAIL` and `AVP_CLOUD_PASSWORD`.

## Web UI

`avp serve` runs the web UI for your single local project:

- **README banner**: when your document changes, it shows what changed (endpoints added, removed, changed) and saves a new version in one click.
- **Versions**: upload or paste Markdown or OpenAPI JSON; view, edit, compare and delete versions.
- **Test APIs**: send requests using environments (base URLs) you define. Requests go through the local PHP server, so CORS does not apply. Redirects are followed (at most 5) but never from a public host into a private address.
- **Cloud**: sign in, publish, browse and import (see above).
- **History**: the requests you sent from the tester, with a Clear button.

Versions made from your document (CLI, `publish` or the banner) keep the exact Markdown and move the `avp status` baseline. Versions imported or edited in the UI store OpenAPI only and do not.

## Configuration

Nothing needs configuring. `avp.config.php` (from `avp init`, see `config/avp.config.example.php`) overrides the defaults:

```php
return [
    'paths' => [
        'readme' => null,                     // null = auto-detect; or e.g. 'docs/my-api.md'
        'storage' => '.avp',                  // state folder (JSON files)
    ],
    'server' => [
        'host' => '127.0.0.1',
        // 'port' => 8080,                    // pin the port (no automatic fallback)
    ],
    'cloud' => [
        'url' => 'https://qa.erpsite.org.pk/test_avp/',
    ],
];
```

With `readme` set to `null` (or no config file), the first of `README.md`, `API.md`, `docs/API.md`, `docs/README.md` that contains endpoints is used. Relative paths resolve from the config file's folder, or the project root when there is no config. Environment variables override the file: `AVP_README_PATH`, `AVP_STORAGE_PATH`, `AVP_HOST`, `AVP_PORT`, `AVP_CLOUD_URL`; `AVP_CLOUD_EMAIL` and `AVP_CLOUD_PASSWORD` supply the cloud sign-in.

## Troubleshooting

- **`vendor/bin/avp` is not recognized.** In `cmd.exe` use `vendor\bin\avp`; in PowerShell `.\vendor\bin\avp`.
- **`MISSING: No API document found`.** Run `avp init` (creates `API.md`), or set `paths.readme` in `avp.config.php`.
- **`Not signed in` / `Your cloud sign-in expired`.** Run `avp login` (or just `avp publish`, which asks). In a script, set `AVP_CLOUD_EMAIL` and `AVP_CLOUD_PASSWORD`.
- **`Version 'v1.0.0' already exists in the cloud project`.** Publish under another name (`avp publish v1.1.0`), or edit the document and run `avp publish` to get the next free label.
- **`Could not reach the cloud server` / SSL error.** Check the URL (`avp login --url=...`). On Windows, PHP may lack a CA bundle: set `openssl.cafile` in `php.ini`.
- **The password is visible when typing.** Hiding it needs a real terminal (PowerShell or `cmd.exe` on Windows, any Unix shell); in editors that run commands without a terminal it falls back to visible input. Use the environment variables there.
- **`Cannot ask for "Email": there is no terminal to type in`.** You ran `avp` through a Composer script or a CI job. Run `vendor/bin/avp login` in a terminal, or set `AVP_CLOUD_EMAIL` and `AVP_CLOUD_PASSWORD`.
- **A Composer script ignores your options or says `The "--note" option does not exist`.** Put `--` after the script name, or run `vendor/bin/avp` directly.
- **`avp version create` says the README is clean.** Nothing changed since the last version; add `--allow-unchanged` to make one anyway.

## How it works

```
bin/avp                 CLI: init / serve / status / check / migrate / version / login / logout / publish / template
src/Config.php          defaults, avp.config.php, AVP_* variables, API document auto-detection
src/router.php          /api/* -> local API, static UI files, everything else -> index.html
src/LocalApi.php        the web UI's API routes for one local project
src/VersionService.php  document status, version create/import/edit/delete
src/Storage.php         JSON-file store (atomic writes, file lock): versions, request history, project settings
src/Cloud.php           client for the hosted platform: sign in, projects, versions
src/Publisher.php       `avp publish`: project name, version label and conflict handling
src/Prompt.php          terminal questions, including the hidden password prompt
src/MarkdownOpenApiParser.php   facade: document -> OpenAPI 3.0.3
src/Parser/             FrontMatter, Lexer, Resolver, OpenApiBuilder, DocumentParser, Validator, Migrator
resources/ui/           pre-built web UI (generated, committed)
resources/templates/    the template used by `avp template`, `avp init` and the UI
client/                 React source of the UI (shipped, so the bundle can be audited and rebuilt)
```

## For maintainers

The UI in `resources/ui/` is a build of `client/`; do not edit it by hand. After changing the frontend:

```bash
cd client && npm install && npm run build:package     # builds into client/dist-package
cd .. && php scripts/sync.php --ui=client/dist-package
cp client/src/templates/API_README_TEMPLATE.md resources/templates/api-readme.md   # if the template changed
php scripts/lint.php && composer test
```

The hosted multi-user backend (`php_backend`) uses the same parser. After changing `src/Parser/` or `src/MarkdownOpenApiParser.php`, run `php scripts/sync-hosted.php --backend=PATH/TO/php_backend` (it writes `includes/parser/` and the `includes/markdownOpenApiParser.php` loader; the parser runs on PHP 8.0+). The hosted client's template and `test-fixtures/API_README_TEMPLATE.openapi.json` are copies of this package's, so refresh them too. Release by tagging (`git tag v1.3.0 && git push --tags`); the version comes from the tag.

## Changelog

See [CHANGELOG.md](CHANGELOG.md). The package is in beta: the parser and the local API may still change between minor versions.

## License

MIT
