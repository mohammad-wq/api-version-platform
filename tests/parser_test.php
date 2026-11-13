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
require_once "{$root}/src/MarkdownOpenApiParser.php";

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

function parse(string $markdown)
{
    return MarkdownOpenApiParser::parse($markdown);
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
check(count(array_column($cleanDoc['warnings'], 'code')) === 0, 'A clean document raises no warnings (no false positives).');

$unknownColumnDoc = parse("# API\n\n## Users\n\n| Method | Endpoint | Responses | Description |\n|---|---|---|---|\n| GET | /u/getUsers.php | `404`: nope | list |\n");
check(in_array('UNRECOGNIZED_COLUMN', array_column($unknownColumnDoc['warnings'], 'code'), true), 'Cells in unrecognized columns emit an UNRECOGNIZED_COLUMN warning instead of being silently dropped.');

$synonymDoc = parse("# API\n\n## Users\n\n| HTTP Verb | API Route | Permissions | Authentication | Description |\n|---|---|---|---|---|\n| GET | /u/getUsers.php | `users:view` | true | list |\n");
check(count($synonymDoc['warnings']) === 0, 'Header synonyms (HTTP Verb, API Route, Permissions, Authentication) parse without warnings.');
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
check(($unquotedServer['openapi']['servers'] ?? []) === [['url' => 'https://api.example.com/v1']] && $unquotedServer['warnings'] === [], 'A Servers: URL without backticks is accepted.');

$namedNote = customersTable('`customers:view`', 'List', '`fetch/list.php`', 'GET', "| POST | `modify/save.php` | `customers:add` | true | Save |\n\nSaving through `save.php` is idempotent.\n");
check((documentedOperation($namedNote, '/customers/fetch/list.php', 'get')['description'] ?? null) === 'List', 'A note under a table that names `save.php` is not added to other endpoints.');
check((documentedOperation($namedNote, '/customers/modify/save.php')['description'] ?? null) === "Save\n\nSaving through save.php is idempotent.", 'A note under a table that names `save.php` is added to that endpoint.');

$dupField = ("# API\n\n## Docs\n\n| Method | Endpoint | Description |\n|---|---|---|\n| POST | `/d/upload.php` | Upload with `multipart/form-data`: `a`, `b?`, file field `upload` and `a` |\n");
check((documentedOperation($dupField, '/d/upload.php')['requestBody']['content']['multipart/form-data']['schema']['required'] ?? null) === ['a', 'upload'], 'A multipart field named twice is listed once in required (duplicates make the OpenAPI invalid).');

// ---------------------------------------------------------------- done

$outcome = $failures === 0 ? 'PASS' : 'FAIL';
fwrite(STDOUT, "{$outcome}: {$checks} checks, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
