<?php

/**
 * Regression + parity suite for MarkdownOpenApiParser.
 *
 * The UI parses README Markdown through the platform API (`POST /api/local/readme/parse`),
 * which returns exactly what this parser produces — the same code path `avp version create`
 * uses. These tests pin the behaviors the browser bundle previously got from a deleted
 * JavaScript port (servers, operationIds, dedupe) and guard the client fixtures against drift.
 *
 * Run: `php tests/parser_test.php`   (also refresh fixtures with `--refresh`)
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
// PHP warnings (like the old "Undefined array key" mishaps) must fail the suite, not scroll past.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    fwrite(STDERR, "$file:$line: $message\n");
    exit(1);
});

$root = dirname(__DIR__);
require_once "{$root}/src/autoload.php";

use Mohammad\ApiVersionPlatform\MarkdownOpenApiParser;
use Mohammad\ApiVersionPlatform\Parser\Diagnostics;
use Mohammad\ApiVersionPlatform\Parser\FrontMatter;
use Mohammad\ApiVersionPlatform\Parser\Lexer;
use Mohammad\ApiVersionPlatform\Parser\Migrator;
use Mohammad\ApiVersionPlatform\Parser\OpenApiBuilder;
use Mohammad\ApiVersionPlatform\Parser\Resolver;
use Mohammad\ApiVersionPlatform\Parser\Validator;

$failures = 0;
$checks = 0;

function fail(string $message): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "FAIL: {$message}\n");
}

function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        $GLOBALS['failures']++;
    }
}

function operationsOfDoc(array $document): array
{
    $operations = [];
    $paths = json_decode(json_encode($document['paths']), true) ?: [];
    foreach ($paths as $path => $item) {
        foreach ($item as $method => $operation) {
            if (str_starts_with((string) $method, 'x-')) {
                continue;
            }
            $operations[] = [$path, strtoupper((string) $method), $operation];
        }
    }
    return $operations;
}

/** Structural checks every parser output must satisfy for the platform's OpenAPI validator. */
function validateDocument(array $openapi): array
{
    $errors = [];
    foreach (operationsOfDoc($openapi) as [$path, $method, $operation]) {
        $location = "paths.{$path}.{$method}";
        if (empty($operation['responses'])) {
            $errors[] = "{$location} must define at least one response.";
        }
        $seen = [];
        foreach ($operation['parameters'] ?? [] as $parameter) {
            $key = ($parameter['in'] ?? '?') . ':' . ($parameter['name'] ?? '?');
            if (isset($seen[$key])) {
                $errors[] = "{$location} declares parameter {$key} more than once.";
            }
            $seen[$key] = true;
        }
        preg_match_all('/\{([^{}]+)\}/', $path, $placeholders);
        foreach ($placeholders[1] as $placeholder) {
            $declared = false;
            foreach ($operation['parameters'] ?? [] as $parameter) {
                $declared = $declared || (($parameter['in'] ?? '') === 'path' && ($parameter['name'] ?? '') === $placeholder);
            }
            if (!$declared) {
                $errors[] = "{$location} is missing the required path parameter \"{$placeholder}\".";
            }
        }
    }
    $operationIds = [];
    foreach (operationsOfDoc($openapi) as [, , $operation]) {
        $id = $operation['operationId'] ?? null;
        if (!is_string($id) || $id === '') {
            $errors[] = 'Every operation must declare an operationId.';
        } elseif (isset($operationIds[$id])) {
            $errors[] = "operationId '{$id}' is duplicated.";
        }
        $operationIds[$id] = true;
    }
    return $errors;
}

// ---------------------------------------------------------------- bundled template (parity fixture)

$templatePath = "{$root}/client/src/templates/API_README_TEMPLATE.md";
$templateFixturePath = "{$root}/client/test-fixtures/API_README_TEMPLATE.openapi.json";
$templateOpenApi = MarkdownOpenApiParser::parse((string) file_get_contents($templatePath))['openapi'];
$templateJson = json_encode($templateOpenApi, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

if (in_array('--refresh', $argv, true)) {
    file_put_contents($templateFixturePath, $templateJson);
    fwrite(STDOUT, "Refreshed {$templateFixturePath}\n");
}

check(
    // Line endings are ignored: a Windows checkout (core.autocrlf) stores the fixture with CRLF.
    $templateJson === str_replace("\r\n", "\n", (string) file_get_contents($templateFixturePath)),
    "client/test-fixtures/API_README_TEMPLATE.openapi.json matches the PHP parser output (edit the template, then run `php tests/parser_test.php --refresh`).",
);

$dangers = validateDocument($templateOpenApi);
check($dangers === [], 'Template OpenAPI passes the platform structural validation: ' . implode(' | ', $dangers));

// The UI's auth detection consumes this spec: a public POST login whose Success example carries
// the token and its expiry, used against a BearerAuth scheme.
$loginOperation = null;
foreach (operationsOfDoc($templateOpenApi) as [, , $operation]) {
    if (($operation['operationId'] ?? '') === 'post_auth_login_php') {
        $loginOperation = $operation;
        break;
    }
}
check($loginOperation !== null, 'The template parses a POST auth/login.php operation (operationId post_auth_login_php).');
if ($loginOperation !== null) {
    $example = json_encode($loginOperation, JSON_UNESCAPED_UNICODE) ?: '';
    $requestExample = $loginOperation['requestBody']['content']['application/json']['example'] ?? null;
    check(is_array($requestExample) && array_keys($requestExample) === ['user_name', 'login_password'], 'The login request example is persisted with its argument order (identifier, then password); preserves loginFields detection.');
    check(str_contains($example, 'token'), 'The login success example contains the token property (tokenPath data.token).');
    check(str_contains($example, 'expires_in'), 'The login success example contains expires_in (expiresPath data.expires_in).');
    check(($loginOperation['security'] ?? ['BearerAuth']) === [], 'The login operation is public (security: []).');
}

// ---------------------------------------------------------------- the dummy-project fixture

$fixtureBinary = "{$root}/client/test-fixtures/DUMMY_PROJECT_TEST_README.md";
check(is_file($fixtureBinary), 'client/test-fixtures/DUMMY_PROJECT_TEST_README.md exists.');
if (is_file($fixtureBinary)) {
    $fixture = MarkdownOpenApiParser::parse((string) file_get_contents($fixtureBinary));
    $fixtureErrors = validateDocument($fixture['openapi']);
    check($fixtureErrors === [], 'Fixture OpenAPI passes structural validation: ' . implode(' | ', $fixtureErrors));
    $warnings = array_column($fixture['warnings'], 'code');
    check(!in_array('NO_ENDPOINTS_FOUND', $warnings, true), 'The fixture yields endpoints.');
    check(
        array_key_exists('endpoints', $fixture['statistics']) && count($fixture['statistics']) === 3,
        'Statistics report endpoints, methods and tags like the platform save API documents.',
    );
}

// ---------------------------------------------------------------- servers + environments (was JS-only)

/** The strict-aware result (errors listed separately); MarkdownOpenApiParser::parse folds them into warnings. */
function parse(string $markdown)
{
    return Mohammad\ApiVersionPlatform\Parser\DocumentParser::parse($markdown);
}

$serversDoc = parse("# Ticket API\n\nServers: `http://api.example.com/backend/`\n\nEnvironments: Production=https://api.prod.com|login=/auth/login.php|token=data.token|method=POST; Staging=https://api.staging.com/v2\n\n## Accounts\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `fetch/getAccounts.php` | List accounts |\n");
$servers = $serversDoc['openapi']['servers'] ?? [];
check($servers === [
    ['url' => 'http://api.example.com/backend'],
    ['url' => 'https://api.prod.com', 'description' => 'Production', 'x-login-url' => '/auth/login.php', 'x-token-path' => 'data.token', 'x-login-method' => 'POST'],
    ['url' => 'https://api.staging.com/v2', 'description' => 'Staging'],
], 'Servers:/Environments: lines produce servers with the x-login-* conventions (URLs normalized, trailing slash dropped).');

$invalidServers = parse("# Ticket API\n\nServers: ftp://nope\n\nEnvironments: Prod=https://x.com, extra\n\n## Accounts\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `fetch/getAccounts.php` | List accounts |\n");
check(($invalidServers['openapi']['servers'] ?? []) === [], 'Invalid server declarations produce no servers.');
$codes = array_column($invalidServers['warnings'], 'code');
check(in_array('INVALID_SERVER', $codes, true), 'Invalid server URLs warn with INVALID_SERVER.');

check(
    !isset(parse("# Ticket API\n\n## Accounts\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `fetch/getAccounts.php` | List |\n")['openapi']['servers']),
    'A document without Servers/Environments line carries no servers array.',
);

// ---------------------------------------------------------------- unique parameters (the `?search=` bug)

$duplicate = parse("# Customer API\n\n## Customers — `customers/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `fetch/getCustomers.php?search=&page=1` | List customers, accepts `?search=` (any string) |\n");
$customers = operationsOfDoc($duplicate['openapi'])[0] ?? null;
check($customers !== null, 'The duplicate-parameter fixture parses one operation.');
if ($customers !== null) {
    [$path, $method, $operation] = $customers;
    $errors = validateDocument($duplicate['openapi']);
    check($errors === [], "No duplicate name+in parameters across parameter sources: " . implode(' | ', $errors));
    $searchParams = array_filter($operation['parameters'] ?? [], static fn (array $parameter): bool => ($parameter['name'] ?? '') === 'search' && ($parameter['in'] ?? '') === 'query');
    check(count($searchParams) === 1, 'The `?search=` mention and the template query produce a single `search` parameter (' . count($searchParams) . ' found).');
}

// ---------------------------------------------------------------- hr-rule artifacts (summary "---")

$hrDoc = parse("# Health API\n\n---\n\n## Health — `health/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `me.php` | Who am I |\n\n### GET `health.php`\n\n---\n");
foreach (operationsOfDoc($hrDoc['openapi']) as [$path, $method, $operation]) {
    check(($operation['summary'] ?? '') !== '---', "GET {$path} summary must not be a captured horizontal rule.");
    check(!str_ends_with((string) ($operation['description'] ?? ''), '---'), "GET {$path} description must not end in a horizontal rule.");
}

// ---------------------------------------------------------------- endpoints tables without an Auth column

// Parsed without PHP notices (checked by the error handler); the guard on the auth column lookup
// is what used to emit "PHP Warning: Undefined array key auth" on every parse.
$noAuthColumn = parse("# Authless API\n\n## Users\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `fetch/user.php` | Current user |\n");
check(count(operationsOfDoc($noAuthColumn['openapi'])) === 1, 'Tables without an Auth column parse normally (no PHP warning, one endpoint).');

// ----------------------------------------------------------------- fault tolerance (hostile inputs)

// None of these may throw a PHP error, a warning, or an exception; the contract
// (valid OpenAPI 3.0.3 skeleton, no duplicate name+in parameters) must still hold.
$hostileInputs = [
    'empty string' => "",
    'whitespace only' => "   \n\t\n   ",
    'binary garbage' => "\x00\x01\x02\xFF\xFE\xFD binary \x93\x94\x00",
    'invalid utf8' => "Valid \xC3 start, then invalid \x80\x81\x82 bytes end",
    'table without separator row' => "# API\n\n| method | endpoint | description\n| GET | /u/getUsers.php | list |\n",
    'unclosed code spans' => "# API\n\n## Users\n\n| method | endpoint | description |\n| --- | --- | --- |\n| GET | `/u/getUsers.php | never closed `{{token}} and ``nested`\n",
    'html injection in cells' => "# API\n\n## Users\n\n| method | endpoint | description |\n| --- | --- | --- |\n| GET | /u/getUsers.php | <script>alert(1)</script> & \"quotes\" <img src=x> |\n",
    'control characters' => "# API\n\n## Users\n\n| method | endpoint | description |\n| --- | --- | --- |\n| GET | /u/getUsers.php | a\x07b\x1Bc\rd\x08e |\n",
    'very long line (100k chars)' => "# API\n\n## Users\n\n| method | endpoint | description |\n| --- | --- | --- |\n| GET | /u/getUsers.php | " . str_repeat('lorem ', 15000) . " |\n",
];
$contractError = static function (array $doc): ?string {
    if (($doc['openapi']['openapi'] ?? null) !== '3.0.3') return 'missing openapi version';
    foreach ($doc['openapi']['paths'] ?? [] as $pathItem) {
        foreach ($pathItem as $method => $operation) {
            if (!in_array(strtolower($method), ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'], true)) continue;
            $seen = [];
            foreach ($operation['parameters'] ?? [] as $parameter) {
                $key = ($parameter['name'] ?? '') . ':' . ($parameter['in'] ?? '');
                if (isset($seen[$key])) return "duplicate parameter {$key}";
                $seen[$key] = true;
            }
        }
    }
    return null;
};
foreach ($hostileInputs as $description => $markdown) {
    $hostileDoc = parse($markdown);
    $problem = $contractError($hostileDoc);
    check($problem === null, "Hostile input ({$description}) is fault tolerant and the contract still holds" . ($problem !== null ? ": {$problem}" : ''));
}

// ----------------------------------------------------------------- accuracy edge cases

$dupDoc = parse("# API\n\n## Users\n\n| method | endpoint | description |\n| --- | --- | --- |\n| GET | /u/getUsers.php | a |\n| GET | /u/getUsers.php | b |\n| get | /u/getUsers.php | c |\n");
check(in_array('DUPLICATE_ENDPOINT', array_column($dupDoc['warnings'], 'code'), true), 'Duplicate method+path rows emit a DUPLICATE_ENDPOINT warning instead of silently overwriting.');

$cleanDoc = parse("# API\n\n## Users\n\n| method | endpoint | description |\n| --- | --- | --- |\n| GET | /u/getUsers.php | a |\n| POST | /u/makeUser.php | b |\n");
check(codesOf($cleanDoc) === [], 'A clean document raises no warnings (no false positives).');

$unknownColumnDoc = parse("# API\n\n## Users\n\n| Method | Endpoint | Responses | Description |\n|---|---|---|---|\n| GET | /u/getUsers.php | `404`: nope | list |\n");
check(in_array('UNRECOGNIZED_COLUMN', array_column($unknownColumnDoc['warnings'], 'code'), true), 'Cells in unrecognized columns emit an UNRECOGNIZED_COLUMN warning instead of being silently dropped.');

$synonymDoc = parse("# API\n\n## Users\n\n| HTTP Verb | API Route | Permissions | Authentication | Description |\n|---|---|---|---|---|\n| GET | /u/getUsers.php | `users:view` | true | list |\n");
check(codesOf($synonymDoc) === [], 'Header synonyms (HTTP Verb, API Route, Permissions, Authentication) parse without warnings.');
$synonymOp = operationsOfDoc($synonymDoc['openapi'])[0][2] ?? null;
check($synonymOp !== null && in_array('users:view', $synonymOp['x-permissions'] ?? [], true), 'Permissions from a synonym-named column land in x-permissions.');

// ---------------------------------------------------------------- documented README features (JS parity)

/** One table row in a `customers/` section; returns the decoded operation for $path/$method. */
function customersTable(string $permission, string $description, string $endpoint = '`modify/save.php`', string $method = 'POST', string $after = ''): string
{
    return "# API\n\n## Customers — `customers/`\n\n| Method | Endpoint | Permission | Auth | Description |\n|---|---|---|---|---|\n| {$method} | {$endpoint} | {$permission} | true | {$description} |\n{$after}";
}

function documentedOperation(string $markdown, string $path, string $method = 'post'): array
{
    $document = json_decode(json_encode(parse($markdown)['openapi']), true);
    return $document['paths'][$path][$method] ?? [];
}

$chained = documentedOperation(customersTable('`customers:add`/`edit`', 'Save'), '/customers/modify/save.php');
check(($chained['x-permissions'] ?? null) === ['customers:add', 'customers:edit'], '`customers:add`/`edit` expands to customers:add and customers:edit.');
$referenceDoc = json_decode(json_encode(parse("# API\n\n## Lookup — `masters/`\n\n| Entity | List (GET) | Remove (POST) | Permission |\n|---|---|---|---|\n| Colors | `fetch/colors.php` | `remove/removeColor.php` | `colors:view`/`delete` |\n")['openapi']), true);
check(($referenceDoc['paths']['/masters/remove/removeColor.php']['post']['x-permissions'] ?? null) === ['colors:delete'], 'Reference tables keep chained `resource:view`/`delete` permissions for their Remove column.');

$descriptionBody = documentedOperation(customersTable('`customers:add`', 'Save: `{customer_name, phone?}`'), '/customers/modify/save.php');
check(($descriptionBody['requestBody']['content']['application/json']['schema'] ?? null) === ['type' => 'object', 'properties' => ['customer_name' => ['type' => 'string'], 'phone' => ['type' => 'string']], 'required' => ['customer_name']], 'A `{...}` span in the Description column is the JSON body of a write endpoint.');
check(!isset(documentedOperation(customersTable('`customers:view`', 'Returns `{id, name}`', '`fetch/one.php`', 'GET'), '/customers/fetch/one.php', 'get')['requestBody']), 'A `{...}` span in a GET description is not a request body.');

$removeOnly = customersTable('`customers:view`', 'List', '`fetch/list.php`', 'GET', "| POST | `remove/removeCustomer.php` | `customers:delete` | true | Remove |\n\nRemove errors: `409` still has orders.\n");
check(array_keys(documentedOperation($removeOnly, '/customers/fetch/list.php', 'get')['responses'] ?? []) === [200], 'A "Remove errors:" line does not add its codes to non-remove endpoints.');
check((documentedOperation($removeOnly, '/customers/remove/removeCustomer.php')['responses'][409] ?? null) === ['description' => 'still has orders'], 'A "Remove errors:" line documents remove endpoints.');

$proseHeader = documentedOperation(customersTable('`customers:add`', 'Save. Requests must include the `Idempotency-Key` header'), '/customers/modify/save.php');
check(($proseHeader['parameters'] ?? null) === [['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']]], 'Prose such as "Requests must include the `Idempotency-Key` header" documents a required header parameter.');

$anyOf = documentedOperation(customersTable('`tickets:view` or `tickets:view_all`', 'List', '`fetch/list.php`', 'GET'), '/customers/fetch/list.php', 'get');
check(($anyOf['x-permissions-any-of'] ?? null) === ['tickets:view', 'tickets:view_all'] && !isset($anyOf['x-permissions']), '`a:view` or `a:view_all` becomes x-permissions-any-of (either permission is enough).');

$typed = documentedOperation(customersTable('`customers:add`', 'Save', '`modify/save.php` — `{id: integer, tags: string[], note?}`'), '/customers/modify/save.php');
check(($typed['requestBody']['content']['application/json']['schema'] ?? null) === ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']], 'note' => ['type' => 'string']], 'required' => ['id', 'tags']], 'Typed shorthand `{id: integer, tags: string[], note?}` is typed, not split into words.');

$unquotedServer = parse("# API\n\nServers: https://api.example.com/v1/\n\n## Users\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `/u/list.php` | List |\n");
check(($unquotedServer['openapi']['servers'] ?? []) === [['url' => 'https://api.example.com/v1']] && codesOf($unquotedServer) === [], 'A Servers: URL without backticks is accepted.');

$namedNote = customersTable('`customers:view`', 'List', '`fetch/list.php`', 'GET', "| POST | `modify/save.php` | `customers:add` | true | Save |\n\nSaving through `save.php` is idempotent.\n");
check((documentedOperation($namedNote, '/customers/fetch/list.php', 'get')['description'] ?? null) === 'List', 'A note under a table that names `save.php` is not added to other endpoints.');
check((documentedOperation($namedNote, '/customers/modify/save.php')['description'] ?? null) === "Save\n\nSaving through save.php is idempotent.", 'A note under a table that names `save.php` is added to that endpoint.');

$dupField = ("# API\n\n## Docs\n\n| Method | Endpoint | Description |\n|---|---|---|\n| POST | `/d/upload.php` | Upload with `multipart/form-data`: `a`, `b?`, file field `upload` and `a` |\n");
check((documentedOperation($dupField, '/d/upload.php')['requestBody']['content']['multipart/form-data']['schema']['required'] ?? null) === ['a', 'upload'], 'A multipart field named twice is listed once in required (duplicates make the OpenAPI invalid).');

// ================================================================ split parser: Lexer

/** Warning codes of a parse result, without the LEGACY_FORMAT hint every header-less document gets. */
function codesOf(array $doc): array
{
    return array_values(array_filter(array_column($doc['warnings'], 'code'), static fn (string $code): bool => $code !== 'LEGACY_FORMAT'));
}

/** A strict document: the front-matter header plus $body. */
function strictDoc(string $body, string $header = "---\navp: 1\ntitle: Test API\n---\n"): string
{
    return $header . $body;
}

function errorCodes(array $doc): array
{
    return array_column($doc['errors'], 'code');
}

const USERS_TABLE = "\n## Users — `users/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `list.php` | List users |\n";

$split = Lexer::split("\xEF\xBB\xBF# Title\r\nline two\rline three\n");
check($split['lines'] === ['# Title', 'line two', 'line three', ''] && $split['notUtf8'] === false, 'Lexer::split drops a BOM and splits CRLF, CR and LF line endings.');
$split = Lexer::split("caf\xE9 \x80");
check($split['notUtf8'] === true && preg_match('//u', implode("\n", $split['lines'])) === 1 && str_contains($split['lines'][0], 'café'), 'Lexer::split reads non-UTF-8 text as Windows-1252 and reports it.');

$hidden = Lexer::hideHtmlComments(['keep <!-- one --> this', '<!-- start', 'hidden line', 'end --> tail', 'a <!-- b', '--> c']);
check($hidden === ['keep  this', '', '', ' tail', 'a ', ' c'], 'Lexer::hideHtmlComments hides inline and multi-line comments but keeps the line count.');

$blocks = Lexer::readBlocks(Lexer::hideHtmlComments(explode("\n", "# Title\n\nSome text\nmore text\n\n---\n\n| A | B |\n|---|---|\n| 1 | a\\|b |\n\n```json\n{\"x\":1}\n```\n\n## Next")));
check(array_column($blocks, 'kind') === ['heading', 'paragraph', 'table', 'fence', 'heading'], 'Lexer::readBlocks finds headings, paragraphs, tables and fences, and skips horizontal rules.');
check($blocks[1]['text'] === "Some text\nmore text" && $blocks[1]['lines'] === [3, 4], 'A paragraph keeps its text and 1-based line range.');
check($blocks[2]['header'] === ['A', 'B'] && $blocks[2]['rows'][0]['cells'] === ['1', 'a|b'] && $blocks[2]['rows'][0]['line'] === 10, 'A table block has its header, rows (escaped pipes kept) and row line numbers.');
check($blocks[3]['lang'] === 'json' && $blocks[3]['text'] === '{"x":1}', 'A fenced block keeps its language and text.');
check(Lexer::splitTableRow('| a | `b|c` \\| d |') === ['a', '`b', 'c` | d'], 'Lexer::splitTableRow splits on unescaped pipes only.');

$lexed = Lexer::lex(explode("\n", "# My API\n\nThe intro paragraph.\n\n## Users — `users/`\n\nBase path: `v2/people`\n\n### Detail\n\n## Plain\n"));
check($lexed['title'] === 'My API' && $lexed['description'] === 'The intro paragraph.', 'Lexer::lex reports the title (first H1) and the description (first paragraph under it).');
$byTitle = array_column($lexed['sections'], null, 'title');
check($byTitle['Users']['prefix'] === 'v2/people' && $byTitle['Detail']['prefix'] === 'v2/people' && $byTitle['Plain']['prefix'] === null, 'Section prefixes come from "Base path:" and are inherited by child headings.');
check(Lexer::cleanTitle('Users — `users/` (v2)') === 'Users' && Lexer::cleanTitle('**Bold** `x`') === 'Bold', 'Lexer::cleanTitle drops the prefix span and inline markup.');
check(Lexer::prefixFromHeading('Users — `users/`') === 'users' && Lexer::prefixFromHeading('POST `a.php`') === null && Lexer::prefixFromHeading('X — `a/b/`, `a/c/`') === 'a', 'Lexer::prefixFromHeading takes the prefix span, ignores method headings and uses the common prefix.');
check(Lexer::stripInline('**a** _b_ `**c**`') === 'a b **c**' && Lexer::codeSpans('x `a` y `b`') === ['a', 'b'] && Lexer::firstSentence('One. Two.') === 'One.', 'Lexer text helpers: stripInline keeps code spans, codeSpans, firstSentence.');
check(Lexer::cleanDescription("List. Auth: true\n---\nmore") === 'List. more', 'Lexer::cleanDescription removes Auth markers and horizontal rules.');

// ================================================================ split parser: Resolver + OpenApiBuilder

$diagnostics = new Diagnostics(false);
$resolver = new Resolver($diagnostics);
$resolver->resolve(Lexer::lex(explode("\n", "# API\n\n```json\n{\"statusCode\":200,\"data\":{}}\n```\n\nEvery endpoint except `auth/login.php` requires a token.\n\nServers: https://x.example.com/\n\n## Auth — `auth/`\n\n| Method | Endpoint | Permission | Description |\n|---|---|---|---|\n| POST | `login.php` — `{user, pass}` | | Sign in |\n| GET | `me.php?id=1` | `users:view` | Who am I. Errors: `404` no such user |\n"))['sections']);
check(count($resolver->endpoints) === 2 && $resolver->endpoints[0]['method'] === 'POST' && $resolver->endpoints[0]['path'] === '/auth/login.php', 'Resolver collects endpoints with the prefix applied.');
check($resolver->endpoints[0]['public'] === true && $resolver->endpoints[1]['public'] === false, 'Resolver marks "Every endpoint except `x`" targets public.');
check($resolver->endpoints[1]['permission'] === ['users:view'] && $resolver->endpoints[1]['request']['parameters'][0]['name'] === 'id', 'Resolver reads permissions and query parameters.');
check(isset($resolver->endpoints[1]['responses'][404]) && $resolver->responseEnvelope !== null && $resolver->servers === [['url' => 'https://x.example.com']], 'Resolver reads error statuses, the response envelope and servers.');
check($resolver->endpoints[0]['request']['body']['schema']['required'] === ['user', 'pass'], 'Resolver turns `{user, pass}` into a JSON body schema.');
check(Resolver::normalizeServerUrl('https://a.com/x/') === 'https://a.com/x' && Resolver::normalizeServerUrl('ftp://a.com') === null && Resolver::normalizeServerUrl('https://u:p@a.com?q=1') === null, 'Resolver::normalizeServerUrl accepts only clean HTTP(S) URLs.');

$schema = OpenApiBuilder::inferSchema(['email' => 'a@b.c', 'n' => 3, 'tags' => ['x'], 'ok' => true, 'none' => null], ['email' => 'valid email format', 'n' => 'minimum 3 characters']);
check($schema['properties']->email === ['type' => 'string', 'description' => 'valid email format', 'format' => 'email'] && $schema['properties']->n === ['type' => 'integer', 'description' => 'minimum 3 characters'] && $schema['properties']->none === ['type' => 'string', 'nullable' => true], 'OpenApiBuilder::inferSchema infers types and applies // comments.');
check(OpenApiBuilder::decodeJsonExample("{\n \"a\": 1, // note\n \"b\": [1, 2,],\n}") === ['a' => 1, 'b' => [1, 2]], 'OpenApiBuilder::decodeJsonExample accepts // comments and trailing commas.');
check(OpenApiBuilder::decodeJsonExample('{ nope') === null && OpenApiBuilder::decodeJsonExample('{}') instanceof \stdClass, 'OpenApiBuilder::decodeJsonExample rejects invalid JSON and keeps `{}` as an object.');
check(OpenApiBuilder::jsonComments("{\n \"a\": 1, // first\n \"u\": \"http://x\"\n}") === ['a' => 'first'], 'OpenApiBuilder::jsonComments ignores // inside strings.');
check(OpenApiBuilder::shorthandSchema('{id: integer, tags: string[], note?}')['required'] === ['id', 'tags'], 'OpenApiBuilder::shorthandSchema reads typed shorthand with optional fields.');
$used = [];
check(OpenApiBuilder::operationId('GET', '/a/b.php', $used) === 'get_a_b_php' && OpenApiBuilder::operationId('GET', '/a/b.php', $used) === 'get_a_b_php_2' && OpenApiBuilder::operationId('GET', '/', $used) === 'get_root', 'OpenApiBuilder::operationId is unique per method+path and has a root fallback.');
check(OpenApiBuilder::reasonPhrase(404) === 'Not Found' && OpenApiBuilder::reasonPhrase(503) === 'Server Error' && OpenApiBuilder::reasonPhrase(299) === 'Response', 'OpenApiBuilder::reasonPhrase covers common codes.');
check(OpenApiBuilder::querySchema('YYYY-MM-DD') === ['type' => 'string', 'format' => 'date'] && OpenApiBuilder::querySchema('a|b')['enum'] === ['a', 'b'] && OpenApiBuilder::queryExample('7') === 7, 'OpenApiBuilder::querySchema / queryExample read query template values.');

$built = (new OpenApiBuilder())->build(
    [['method' => 'GET', 'path' => '/x/{id}', 'tag' => 'X', 'summary' => 'S', 'description' => 'D', 'permission' => [], 'public' => false, 'request' => ['parameters' => []], 'responses' => [], 'line' => 3]],
    ['title' => 'T', 'description' => '', 'version' => '2.0.0', 'servers' => [['url' => 'https://s.example.com']], 'auth' => 'bearer'],
    new Diagnostics(false),
);
$builtDoc = json_decode(json_encode($built['openapi']), true);
check($builtDoc['info'] === ['title' => 'T', 'version' => '2.0.0'] && $builtDoc['servers'] === [['url' => 'https://s.example.com']] && isset($builtDoc['components']['securitySchemes']['BearerAuth']), 'OpenApiBuilder::build writes info, servers and the bearer scheme.');
check($builtDoc['paths']['/x/{id}']['get']['parameters'][0]['in'] === 'path' && $builtDoc['paths']['/x/{id}']['get']['security'] === [['BearerAuth' => []]] && $built['methods'] === ['GET' => 1] && $built['tags'] === 1, 'OpenApiBuilder::build adds path parameters and security, and counts methods and tags.');
$noAuth = (new OpenApiBuilder())->build([], ['title' => 'T', 'description' => '', 'version' => '1.0.0', 'servers' => [], 'auth' => 'none'], new Diagnostics(false));
check(!isset($noAuth['openapi']['components']), 'OpenApiBuilder::build leaves out the security scheme for auth: none.');

$lenient = new Diagnostics(false);
$strictDiag = new Diagnostics(true);
foreach ([$lenient, $strictDiag] as $d) {
    $d->warn('DUPLICATE_ENDPOINT', 'dup', [3, 3], ['method' => 'GET', 'path' => '/a']);
    $d->warn('INFERRED_PERMISSION', 'info', [1, 1]);
}
check(count($lenient->errors()) === 0 && count($lenient->warnings()) === 2, 'Diagnostics (lenient) keeps everything as warnings.');
check(array_column($strictDiag->errors(), 'code') === ['DUPLICATE_ENDPOINT'] && array_column($strictDiag->warnings(), 'code') === ['INFERRED_PERMISSION'] && $strictDiag->errors()[0]['endpoint'] === 'GET /a', 'Diagnostics (strict) turns structural problems into errors and keeps advisory warnings.');

// ================================================================ front-matter

$fm = FrontMatter::extract(explode("\n", "---\navp: 1\ntitle: \"Quoted: title\"\nversion: 2.1.0 # trailing comment\n# a comment line\nauth: none\nservers:\n  - https://a.example.com\n  - 'https://b.example.com'\n---\n# Body"));
check($fm['present'] && $fm['errors'] === [] && $fm['data']['title'] === 'Quoted: title' && $fm['data']['version'] === '2.1.0' && $fm['data']['auth'] === 'none', 'FrontMatter reads bare, quoted and commented values.');
check($fm['data']['servers'] === ['https://a.example.com', 'https://b.example.com'], 'FrontMatter reads a `- item` list.');
check(array_slice($fm['lines'], 0, 10) === array_fill(0, 10, '') && $fm['lines'][10] === '# Body' && count($fm['lines']) === 11, 'FrontMatter blanks the header lines so later line numbers do not move.');
$inline = FrontMatter::extract(['---', 'avp: 1', 'title: T', 'servers: [https://a.example.com, https://b.example.com]', '---']);
check($inline['errors'] === [] && $inline['data']['servers'] === ['https://a.example.com', 'https://b.example.com'], 'FrontMatter reads an inline list.');
check(FrontMatter::extract(['---', '', '# Title', 'text'])['present'] === false && FrontMatter::extract(['# Title'])['present'] === false && FrontMatter::extract(['---', 'title: x', '---'])['present'] === false, 'Without an `avp:` key the top of the file is not a header (a leading rule stays a rule).');

$cases = [
    'unknown key' => ["---\navp: 1\ntitle: T\ncolour: red\n---\n", 'FRONT_MATTER_UNKNOWN_KEY'],
    'duplicate key' => ["---\navp: 1\ntitle: T\ntitle: U\n---\n", 'FRONT_MATTER_DUPLICATE_KEY'],
    'unsupported avp' => ["---\navp: 2\ntitle: T\n---\n", 'FRONT_MATTER_UNSUPPORTED_VERSION'],
    'missing title' => ["---\navp: 1\n---\n", 'FRONT_MATTER_MISSING_TITLE'],
    'empty title' => ["---\navp: 1\ntitle:\n---\n", 'FRONT_MATTER_MISSING_TITLE'],
    'bad auth' => ["---\navp: 1\ntitle: T\nauth: basic\n---\n", 'FRONT_MATTER_INVALID_VALUE'],
    'bad version' => ["---\navp: 1\ntitle: T\nversion: \"!!\"\n---\n", 'FRONT_MATTER_INVALID_VALUE'],
    'list for scalar' => ["---\navp: 1\ntitle:\n  - a\n---\n", 'FRONT_MATTER_INVALID_VALUE'],
    'syntax' => ["---\navp: 1\ntitle: T\nthis is not a pair\n---\n", 'FRONT_MATTER_SYNTAX'],
    'unterminated' => ["---\navp: 1\ntitle: T\n\n# Title\n", 'FRONT_MATTER_UNTERMINATED'],
    'bad server' => ["---\navp: 1\ntitle: T\nservers:\n  - ftp://nope\n---\n", 'INVALID_SERVER'],
];
foreach ($cases as $name => [$header, $code]) {
    $doc = parse($header . "\n## Users — `users/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `list.php` | List |\n");
    check(in_array($code, errorCodes($doc), true), "Front-matter {$name} is reported as {$code}.");
}
$unterminated = parse("---\navp: 1\ntitle: T\n\n# Title\n");
check(errorCodes($unterminated)[0] === 'FRONT_MATTER_UNTERMINATED' && $unterminated['errors'][0]['lines'][0] === 1, 'An unterminated header is an error that points at line 1.');

// ================================================================ headings that share a last path segment

// `### GET /users/{id}` and `### GET /orders/{id}` used to collapse into one endpoint (only the last segment was compared).
$shopHeadings = "### GET `/users/{id}`\nUser by id.\n\n### GET `/orders/{id}`\nOrder by id.\n\n### GET `/products/{id}`\nProduct by id.\n\n### DELETE `/users/{id}`\nDelete a user.\n\n### DELETE `/orders/{id}`\nDelete an order.\n";
$shopStrict = parse(strictDoc($shopHeadings));
check($shopStrict['statistics']['endpoints'] === 5 && $shopStrict['warnings'] === [] && $shopStrict['errors'] === [], 'Five headings that end in {id} are five endpoints with no warnings (strict document).');
$shopOps = array_map(static fn (array $op): string => "{$op[1]} {$op[0]}", operationsOfDoc($shopStrict['openapi']));
sort($shopOps);
check($shopOps === ['DELETE /orders/{id}', 'DELETE /users/{id}', 'GET /orders/{id}', 'GET /products/{id}', 'GET /users/{id}'], 'Each heading keeps its own path and method.');
$shopLegacy = parse("# Shop\n\n" . str_replace('`', '', $shopHeadings));
check($shopLegacy['statistics']['endpoints'] === 5 && codesOf($shopLegacy) === [], 'The same five headings work in a legacy document.');
$sameTail = parse(strictDoc("### GET `/users/list`\nA.\n\n### GET `/orders/list`\nB.\n"));
check($sameTail['statistics']['endpoints'] === 2, 'Headings /users/list and /orders/list are two endpoints.');
// A table row plus a detail heading for the same endpoint must still be one endpoint.
$tableAndHeading = parse(strictDoc("\n## Notes — `notes/`\n\n| Method | Endpoint | Auth | Description |\n|---|---|---|---|\n| GET | `fetch/getNotes.php` | true | List notes. |\n\n### GET `fetch/getNotes.php`\nDetail text for the list call.\n"));
check($tableAndHeading['statistics']['endpoints'] === 1 && $tableAndHeading['warnings'] === [], 'A table row and its detail heading with the same relative path stay one endpoint, with no warning.');
$tailHeading = parse(strictDoc("\n## Notes — `notes/`\n\n| Method | Endpoint | Auth | Description |\n|---|---|---|---|\n| GET | `fetch/getNotes.php` | true | List notes. |\n\n### GET `getNotes.php`\nDetail text.\n"));
check($tailHeading['statistics']['endpoints'] === 1 && codesOf($tailHeading) === ['HEADING_MERGED'], 'A heading that only matches the tail of a table path joins it and says so with HEADING_MERGED.');
$absoluteDiffers = parse(strictDoc("\n## Notes — `notes/`\n\n| Method | Endpoint | Auth | Description |\n|---|---|---|---|\n| GET | `fetch/getNotes.php` | true | List notes. |\n\n### GET `/archive/getNotes.php`\nAn absolute path that differs is another endpoint.\n"));
check($absoluteDiffers['statistics']['endpoints'] === 2, 'An absolute heading path that differs from the table row is a separate endpoint.');

// ================================================================ strict documents

$ok = parse(strictDoc(USERS_TABLE));
check($ok['errors'] === [] && codesOf($ok) === [] && !in_array('LEGACY_FORMAT', array_column($ok['warnings'], 'code'), true), 'A valid strict document has no errors and no LEGACY_FORMAT warning.');
check($ok['openapi']['info']['title'] === 'Test API' && $ok['openapi']['info']['version'] === '1.0.0' && $ok['statistics']['endpoints'] === 1, 'The strict document is parsed like any other (title from the header, default version 1.0.0).');

$overrides = parse(strictDoc("# Heading Title\n\nHeading description.\n" . USERS_TABLE, "---\navp: 1\ntitle: Header Title\nversion: 3.2.1\ndescription: Header description.\nservers:\n  - https://api.example.com/v1/\n---\n"));
check($overrides['errors'] === [] && $overrides['openapi']['info'] === ['title' => 'Header Title', 'version' => '3.2.1', 'description' => 'Header description.'], 'Header title, version and description override the heading and first paragraph.');
check($overrides['openapi']['servers'] === [['url' => 'https://api.example.com/v1']], 'Header servers become the OpenAPI servers (URLs normalized).');
$fallback = parse(strictDoc("# Heading Title\n\nHeading description.\n" . USERS_TABLE));
check($fallback['openapi']['info']['description'] === 'Heading description.', 'Without a header description, the first paragraph under the title is still used.');

$merged = parse(strictDoc("\nEnvironments: Prod=https://api.example.com|login=/auth/login.php; Other=https://other.example.com\n" . USERS_TABLE, "---\navp: 1\ntitle: T\nservers:\n  - https://api.example.com\n---\n"));
check($merged['openapi']['servers'] === [['url' => 'https://api.example.com', 'description' => 'Prod', 'x-login-url' => '/auth/login.php'], ['url' => 'https://other.example.com', 'description' => 'Other']], 'A body Environments: entry for a header server adds its details; other body servers follow.');

$none = parse(strictDoc(USERS_TABLE, "---\navp: 1\ntitle: T\nauth: none\n---\n"));
$noneDoc = json_decode(json_encode($none['openapi']), true);
check($noneDoc['paths']['/users/list.php']['get']['security'] === [] && !isset($noneDoc['components']), 'auth: none makes every endpoint public and drops the bearer scheme.');
$bearer = json_decode(json_encode(parse(strictDoc(USERS_TABLE))['openapi']), true);
check($bearer['paths']['/users/list.php']['get']['security'] === [['BearerAuth' => []]], 'auth defaults to bearer.');

// Every structural problem a lenient parse only warns about is an error in a strict document.
$problems = [
    'INVALID_METHOD' => "\n## U — `u/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| FETCH | `list.php` | x |\n",
    'MISSING_ENDPOINT_PATH' => "\n## U — `u/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | | x |\n| GET | `list.php` | y |\n",
    'INVALID_TABLE_ROW' => "\n## U — `u/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| | | |\n| GET | `list.php` | y |\n",
    'DUPLICATE_ENDPOINT' => "\n## U — `u/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `list.php` | a |\n| GET | `list.php` | b |\n",
    'UNRESOLVED_PREFIX' => "\n## No prefix here\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `list.php` | a |\n",
    'UNRECOGNIZED_COLUMN' => "\n## U — `u/`\n\n| Method | Endpoint | Notes | Description |\n|---|---|---|---|\n| GET | `list.php` | n | a |\n",
    'TABLE_ROW_TOO_WIDE' => "\n## U — `u/`\n\n| Method | Endpoint | Auth | Description |\n|---|---|---|---|\n| GET | `list.php?status=a|b` | true | List |\n",
    'INVALID_SERVER' => "\nServers: ftp://nope\n\n## U — `u/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `list.php` | a |\n",
    'NO_ENDPOINTS_FOUND' => "\nJust text.\n",
    'UNBOUND_JSON_BLOCK' => "\n## U — `u/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `list.php` | a |\n\nSome text\n\n```json\n{\"a\": 1}\n```\n",
    'INVALID_JSON_BLOCK' => "\n## U — `u/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `list.php` | a |\n\nResponse:\n\n```json\n{ nope\n```\n",
    'DOT_SEGMENT_PATH' => "\n## U — `u/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `../list.php` | a |\n",
    'UNRESOLVED_PUBLIC_ENDPOINT' => "\nEvery endpoint except `ghost.php` requires a token.\n\n## U — `u/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `list.php` | a |\n",
];
foreach ($problems as $code => $body) {
    $strictResult = parse(strictDoc($body));
    $lenientResult = parse($body);
    check(in_array($code, errorCodes($strictResult), true) && !in_array($code, array_column($strictResult['warnings'], 'code'), true), "Strict: {$code} is an error, not a warning.");
    $lenientCode = in_array($code, ['INVALID_METHOD', 'MISSING_ENDPOINT_PATH'], true) ? 'INVALID_TABLE_ROW' : $code; // the lenient parser keeps its single row code
    check(in_array($lenientCode, codesOf($lenientResult), true) && $lenientResult['errors'] === [], "Lenient (no header): the same document only warns {$lenientCode}.");
}
$escapedPipe = parse(strictDoc("\n## U — `u/`\n\n| Method | Endpoint | Auth | Description |\n|---|---|---|---|\n| GET | `list.php?status=a\\|b` | true | List |\n| GET | `other.php` | true | Other | |\n"));
check($escapedPipe['errors'] === [] && !in_array('TABLE_ROW_TOO_WIDE', codesOf($escapedPipe), true), 'An escaped pipe, or an empty extra trailing cell, is not a too-wide row.');
$notUtf8 = parse(strictDoc(USERS_TABLE) . "caf\xE9\n");
check(in_array('NOT_UTF8', errorCodes($notUtf8), true), 'Strict: a non-UTF-8 document is an error.');

$rowDoc = strictDoc("\n## U — `u/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `list.php` | a |\n| FETCH | `bad.php` | b |\n");
$badLine = array_search('| FETCH | `bad.php` | b |', explode("\n", $rowDoc), true) + 1;
$rowResult = parse($rowDoc);
check($rowResult['errors'][0]['lines'] === [$badLine, $badLine] && $rowResult['statistics']['endpoints'] === 1, 'Strict errors carry the real line number (the header does not shift it) and the rest of the document is still parsed.');
check(parse(strictDoc("\n## U — `u/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `list.php` | a |\n| GET | `list.php` | b |\n"))['errors'][0]['endpoint'] === 'GET /u/list.php', 'A strict error names the endpoint it is about.');

// ================================================================ legacy documents

$legacy = parse("# API\n\n## Users — `users/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `list.php` | List |\n");
check($legacy['errors'] === [] && array_count_values(array_column($legacy['warnings'], 'code'))['LEGACY_FORMAT'] === 1, 'A document without a header has no errors and exactly one LEGACY_FORMAT warning.');
$ruleStart = parse("---\n\n# API\n\n## Users — `users/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `list.php` | List |\n");
check($ruleStart['errors'] === [] && $ruleStart['statistics']['endpoints'] === 1 && in_array('LEGACY_FORMAT', array_column($ruleStart['warnings'], 'code'), true), 'A document that merely starts with a horizontal rule is still a legacy document.');
check(array_keys($legacy) === ['openapi', 'warnings', 'statistics', 'errors'] && array_keys($legacy['statistics']) === ['endpoints', 'methods', 'tags'], 'The parse result keeps its shape (openapi, warnings, statistics) and adds errors.');

// ================================================================ Migrator

$legacyDoc = "# Ticket API\n\nIntro text.\n\nServers: https://api.example.com/v1/\n\nEnvironments: Prod=https://api.prod.com|login=/auth/login.php; Staging=https://api.staging.com\n\n## Accounts — `accounts/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `fetch/list.php` | List |\n";
$migrated = Migrator::migrate($legacyDoc);
check(str_starts_with($migrated, "---\navp: 1\ntitle: Ticket API\nservers:\n  - https://api.example.com/v1\n  - https://api.prod.com\n  - https://api.staging.com\n---\n\n# Ticket API"), 'Migrator adds the header (avp, title, servers) in front of the unchanged body.');
check(str_ends_with($migrated, $legacyDoc), 'Migrator leaves the body byte-for-byte untouched.');
check(Migrator::migrate($migrated) === $migrated, 'Migrator is idempotent: a document that has the header is returned unchanged.');
$before = parse($legacyDoc);
$after = parse($migrated);
check($after['errors'] === [] && json_encode($after['openapi']) === json_encode($before['openapi']) && $after['statistics'] === $before['statistics'], 'A migrated document produces the same OpenAPI, with no errors.');
check(!in_array('LEGACY_FORMAT', array_column($after['warnings'], 'code'), true), 'A migrated document is no longer "legacy".');
$crlf = Migrator::migrate(str_replace("\n", "\r\n", $legacyDoc));
check(str_starts_with($crlf, "---\r\navp: 1\r\n") && substr_count($crlf, "\n") === substr_count($crlf, "\r\n"), 'Migrator keeps CRLF line endings.');
$bom = Migrator::migrate("\xEF\xBB\xBF" . $legacyDoc);
check(str_starts_with($bom, "\xEF\xBB\xBF---\n") && !str_contains(substr($bom, 3), "\xEF\xBB\xBF") && parse($bom)['errors'] === [], 'Migrator keeps a BOM at the very start, in front of the header.');
$quoted = Migrator::migrate("# Billing: v2 #1 API\n\n## U — `u/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `l.php` | x |\n");
check(str_starts_with($quoted, "---\navp: 1\ntitle: \"Billing: v2 #1 API\"\n---\n") && parse($quoted)['openapi']['info']['title'] === 'Billing: v2 #1 API', 'Migrator quotes a title that would not read back as plain text.');
$untitled = Migrator::migrate("## U — `u/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `l.php` | x |\n");
check(str_contains($untitled, 'title: Untitled API') && parse($untitled)['errors'] === [], 'Migrator gives a document without a heading the title "Untitled API".');

// ================================================================ Validator

$report = Validator::check($legacyDoc);
// The facade never blocks: strict errors come back as warnings (first), with no errors left.
$folded = MarkdownOpenApiParser::parse(strictDoc("
## U — `u/`

| Method | Endpoint | Description |
|---|---|---|
| GET | `list.php` | a |
| GET | `list.php` | b |
"));
check($folded['errors'] === [] && ($folded['warnings'][0]['code'] ?? '') === 'DUPLICATE_ENDPOINT' && $folded['statistics']['endpoints'] >= 1, 'MarkdownOpenApiParser::parse reports strict errors as warnings and still returns the OpenAPI.');
check($report['ok'] === true && $report['strict'] === false && $report['errors'] === [] && $report['statistics']['endpoints'] === 1, 'Validator::check reports a legacy document as ok but not strict.');
$strictReport = Validator::check(strictDoc("\n## U — `u/`\n\n| Method | Endpoint | Description |\n|---|---|---|\n| GET | `l.php` | a |\n| GET | `l.php` | b |\n"));
check($strictReport['ok'] === false && $strictReport['strict'] === true && array_column($strictReport['errors'], 'code') === ['DUPLICATE_ENDPOINT'], 'Validator::check reports strict errors and sets ok to false.');
check(Validator::check(strictDoc(USERS_TABLE))['ok'] === true, 'Validator::check passes a clean strict document.');
check(Validator::check($migrated)['strict'] === true && Validator::check(file_get_contents($templatePath))['ok'] === true && Validator::check(file_get_contents($templatePath))['strict'] === true, 'The bundled template is a strict document with no errors.');
check(trim(str_replace("\r\n", "\n", (string) file_get_contents("{$root}/resources/templates/api-readme.md"))) === trim(str_replace("\r\n", "\n", (string) file_get_contents($templatePath))), 'The two bundled template copies have identical content.');

// ---------------------------------------------------------------- done

$outcome = $failures === 0 ? 'PASS' : 'FAIL';
fwrite(STDOUT, "{$outcome}: {$checks} checks, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
