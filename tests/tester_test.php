<?php

/**
 * Tests for the Cloud tab's server routes (sign in, publish) and for the Test APIs tester's HTTPS handling.
 * The TLS part needs the `openssl` command line tool (to make a throwaway self-signed server); without it that part is skipped.
 * Run: `php tests/tester_test.php`
 */

declare(strict_types=1);

error_reporting(E_ALL);

$root = dirname(__DIR__);
$failures = 0;
$checks = 0;
$skipped = [];
$cleanup = [];
function check(bool $ok, string $message, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}" . ($detail !== '' ? "\n      " . str_replace("\n", "\n      ", trim($detail)) : '') . "\n");
    }
}
function tempDir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'avp-tester-' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);
    return $dir;
}
function removeTree(string $dir): void
{
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? removeTree($path) : @unlink($path);
    }
    @rmdir($dir);
}
function freePort(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $name = (string) stream_socket_get_name($probe, false);
    fclose($probe);
    return (int) substr($name, strrpos($name, ':') + 1);
}
function waitForPort(int $port): bool
{
    for ($i = 0; $i < 60; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
        if (is_resource($socket)) {
            fclose($socket);
            return true;
        }
        usleep(100_000);
    }
    return false;
}
/** @return array{0: int, 1: array<string, mixed>} */
function api(int $port, string $method, string $path, ?array $body = null): array
{
    $context = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 25,
        'header' => "Host: 127.0.0.1:{$port}\r\n" . ($body !== null ? "Content-Type: application/json\r\n" : ''),
        'content' => $body !== null ? json_encode($body) : '',
    ]]);
    $text = (string) @file_get_contents("http://127.0.0.1:{$port}/api{$path}", false, $context);
    $json = json_decode($text, true);
    return [is_array($json) ? (int) $json['statusCode'] : 0, is_array($json) ? $json : ['raw' => $text]];
}
/** Starts `avp serve` for a fresh project folder. @return array{0: resource, 1: int, 2: string} */
function startServe(array $env = []): array
{
    global $root;
    $project = tempDir();
    copy($root . '/resources/templates/api-readme.md', $project . '/README.md');
    $port = freePort();
    $base = array_filter(getenv(), fn ($k) => !str_starts_with((string) $k, 'AVP_'), ARRAY_FILTER_USE_KEY);
    $process = proc_open([PHP_BINARY, $root . '/bin/avp', 'serve', "--port={$port}"], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/avp-tester-test.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/avp-tester-test.log', 'a']], $pipes, $project, $env + $base);
    waitForPort($port);
    // The first request after start-up can be dropped (the readiness probe's connection); warm the server up first.
    for ($i = 0; $i < 20 && api($port, 'GET', '/local/status')[0] !== 200; $i++) usleep(100_000);
    return [$process, $port, $project];
}
/** Stops `avp serve` and the PHP server it started. */
function stopServe($process): void
{
    $status = proc_get_status($process);
    if ($status['running'] && PHP_OS_FAMILY === 'Windows') {
        @exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
    }
    proc_terminate($process);
}

// ------------------------------------------------------------------ Cloud tab routes: sign in and publish

$stateDir = tempDir();
$stateFile = $stateDir . '/state.json';
$mockPort = freePort();
$mock = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$mockPort}", $root . '/tests/fixtures/mock_cloud.php'], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/avp-mock.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/avp-mock.log', 'a']], $mockPipes, $root, ['AVP_MOCK_STATE' => $stateFile] + getenv());
waitForPort($mockPort);

[$serve, $port, $project] = startServe(['AVP_CLOUD_URL' => "http://127.0.0.1:{$mockPort}/"]);
[$code, $response] = api($port, 'POST', '/cloud/login', ['email' => 'test@test.com', 'password' => 'test12345']);
check($code === 200 && ($response['data']['connected'] ?? false) === true && ($response['data']['user']['email'] ?? '') === 'test@test.com', 'sign in to the mock cloud', json_encode($response));
check(!isset($response['data']['url']) && !isset($response['data']['linkedProject']), 'the sign-in answer does not carry the cloud address');
[$code, $response] = api($port, 'GET', '/cloud/status');
check($code === 200 && array_keys($response['data']) === ['connected', 'user'], 'the status answer holds only connected and user (no address)', json_encode($response['data'] ?? null));
[$code] = api($port, 'GET', '/cloud/projects');
check($code === 404, 'there is no cloud project list route');
[$code] = api($port, 'GET', '/cloud/version?version_id=1');
check($code === 404, 'there is no cloud version download route');
[$code] = api($port, 'POST', '/cloud/import', ['version_id' => 1]);
check($code === 404, 'there is no cloud import route');

[$code, $response] = api($port, 'POST', '/versions/modify/insertUpdateVersion.php', ['version' => 'v1', 'openapi' => ['openapi' => '3.0.3', 'paths' => ['/a' => ['get' => ['responses' => new stdClass()]]]]]);
$versionId = (int) ($response['data']['id'] ?? 0);
check($code === 201 && $versionId > 0, 'a local version to publish exists', json_encode($response));
[$code, $response] = api($port, 'POST', '/cloud/publish', ['version_id' => $versionId]);
check($code === 200 && ($response['data']['version'] ?? '') === 'v1' && ($response['data']['project']['name'] ?? '') === basename($project) && ($response['data']['projectCreated'] ?? false) === true, 'publish goes to a cloud project named after this project (created)', json_encode($response));
check(!isset($response['data']['url']), 'the publish answer does not carry a cloud address');
[$code, $response] = api($port, 'POST', '/cloud/publish', ['version_id' => $versionId]);
check($code === 409 && str_contains((string) $response['message'], 'already exists'), 'publishing the same name again is a conflict', json_encode($response));
[$code, $response] = api($port, 'POST', '/cloud/publish', ['version_id' => $versionId, 'version' => 'v1-cloud', 'note' => 'again']);
check($code === 200 && ($response['data']['projectCreated'] ?? true) === false, 'a different cloud name goes to the same project (not created twice)', json_encode($response));
$state = json_decode((string) file_get_contents($stateFile), true);
check(count($state['projects']) === 1 && $state['projects'][0]['name'] === basename($project) && count($state['versions']) === 2, 'the mock cloud has one project with both versions');
[$code, $response] = api($port, 'POST', '/cloud/publish', ['version_id' => 99999]);
check($code === 404, 'publishing an unknown local version is a 404', json_encode($response));
api($port, 'POST', '/cloud/logout', []);
[$code, $response] = api($port, 'POST', '/cloud/publish', ['version_id' => $versionId]);
check($code === 401, 'publishing when signed out is a 401', json_encode($response));
stopServe($serve);
stopServe($mock);
removeTree($project);
removeTree($stateDir);

// ------------------------------------------------------------------ History, the tester's answer fields, limits and the router

/** A raw GET with response headers. @return array{0: int, 1: array<string, string>, 2: string} */
function httpRaw(int $port, string $path): array
{
    $context = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 20, 'header' => "Host: 127.0.0.1:{$port}\r\n"]]);
    $body = (string) @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
    $status = 0;
    $headers = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int) $m[1];
        elseif (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $headers[strtolower(trim($k))] = trim($v); }
    }
    return [$status, $headers, $body];
}

$targetDir = tempDir();
file_put_contents($targetDir . '/target.php', <<<'PHP'
<?php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/big') { header('Content-Type: text/plain'); echo str_repeat('x', 10 * 1024 * 1024 + 5); return true; }
if ($p === '/exact') { header('Content-Type: text/plain'); echo str_repeat('x', 10 * 1024 * 1024); return true; }
if ($p === '/slow') { set_time_limit(0); sleep(40); echo 'late'; return true; }
header('Content-Type: application/json'); echo json_encode(['ok' => true]); return true;
PHP);
$targetPort = freePort();
$target = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$targetPort}", $targetDir . '/target.php'], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/avp-target.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/avp-target.log', 'a']], $targetPipes, $targetDir);
waitForPort($targetPort);
[$serve, $port, $project] = startServe();
$run = static fn (array $extra): array => api($port, 'POST', '/runner/execute.php', $extra + ['method' => 'GET', 'save_history' => true, 'environment' => 'Local']);

[$code, $response] = $run(['url' => "http://127.0.0.1:{$targetPort}/ok?token=SECRET&a=1"]);
check($code === 200 && ($response['data']['status'] ?? 0) === 200 && ($response['data']['truncated'] ?? null) === false && ($response['data']['binary'] ?? null) === false && ($response['data']['size'] ?? 0) > 0, 'a normal answer carries truncated=false, binary=false and its size', json_encode($response['data'] ?? null));
[$code, $response] = $run(['url' => "http://127.0.0.1:{$targetPort}/big"]);
check($code === 200 && ($response['data']['truncated'] ?? null) === true && ($response['data']['size'] ?? 0) === 10 * 1024 * 1024, 'a body over 10 MB is cut and flagged truncated', json_encode(['code' => $code, 'truncated' => $response['data']['truncated'] ?? null, 'size' => $response['data']['size'] ?? null]));
[$code, $response] = $run(['url' => "http://127.0.0.1:{$targetPort}/exact"]);
check($code === 200 && ($response['data']['truncated'] ?? null) === false && ($response['data']['size'] ?? 0) === 10 * 1024 * 1024, 'a body of exactly 10 MB is not reported as cut');
[$code, $response] = $run(['url' => 'nope']);
check($code === 400 && ($response['message'] ?? '') === 'Invalid target URL', 'an invalid URL is a 400', json_encode($response));
[$code, $response] = $run(['url' => "http://127.0.0.1:{$targetPort}/ok", 'method' => 'TRACE']);
check($code === 400 && str_contains((string) $response['message'], 'Unsupported HTTP method'), 'an unsupported method is a 400', json_encode($response));
$started = microtime(true);
[$code, $response] = $run(['url' => "http://127.0.0.1:{$port}/api/local/status"]);
check($code === 400 && str_contains((string) $response['message'], "own address") && microtime(true) - $started < 5, "the tester refuses this server's own address at once (no self-deadlock)", json_encode($response));
[$code, $response] = $run(['url' => "http://localhost:{$port}/x"]);
check($code === 400 && str_contains((string) $response['message'], 'own address'), 'localhost on the same port counts as the own address too');
[$code, $response] = $run(['url' => "http://127.0.0.1:{$targetPort}/ok"]);
check($code === 200, 'another local port is still allowed');
[$code, $response] = $run(['url' => 'http://127.0.0.1:1/']);
check($code === 422 && str_contains((string) $response['message'], 'refused'), 'a refused connection is a 422');
api($port, 'POST', '/runner/execute.php', ['method' => 'GET', 'url' => "http://127.0.0.1:{$targetPort}/ok"]); // no save_history: must not be recorded

[$code, $response] = api($port, 'GET', '/request_history/fetch/getHistory.php');
$rows = $response['data'] ?? [];
check($code === 200 && count($rows) === 9, 'History holds every attempt that asked for it (9), and not the one that did not', json_encode(array_column($rows, 'url')));
$byUrl = [];
foreach ($rows as $row) $byUrl[$row['url'] . '|' . $row['method']] = $row;
check(isset($byUrl['nope|GET']) && $byUrl['nope|GET']['response_status'] === null && $byUrl['nope|GET']['error'] === 'Invalid target URL', 'a refused URL is in the History with its reason and no status', json_encode($byUrl['nope|GET'] ?? null));
check(isset($byUrl["http://127.0.0.1:{$targetPort}/ok|TRACE"]) && str_contains((string) $byUrl["http://127.0.0.1:{$targetPort}/ok|TRACE"]['error'], 'Unsupported HTTP method'), 'a refused method is in the History');
check(isset($byUrl["http://127.0.0.1:{$port}/api/local/status|GET"]) && str_contains((string) $byUrl["http://127.0.0.1:{$port}/api/local/status|GET"]['error'], 'own address'), 'the own-address refusal is in the History');
$refused = $byUrl['http://127.0.0.1:1/|GET'] ?? [];
check(array_key_exists('response_status', $refused) && $refused['response_status'] === null && str_contains((string) ($refused['error'] ?? ''), 'refused'), 'a connection failure is in the History with the reason', json_encode($refused));
$okRow = $byUrl["http://127.0.0.1:{$targetPort}/ok?token=[REDACTED]&a=1|GET"] ?? null;
check($okRow !== null && $okRow['response_status'] === 200 && $okRow['error'] === null && $okRow['has_detail'] === true, 'a successful call has error null, has_detail true, and its secret query value is redacted', json_encode(array_column($rows, 'url')));
check(!str_contains((string) json_encode($rows), 'SECRET'), 'no secret value reaches the History');
[$code, $response] = api($port, 'GET', '/request_history/fetch/getHistory.php?id=' . (int) $okRow['id']);
check($code === 200 && ($response['data']['id'] ?? 0) === $okRow['id'] && ($response['data']['environment'] ?? '') === 'Local' && array_key_exists('error', $response['data']) && ($response['data']['duration_ms'] ?? -1) >= 0, 'getHistory?id= returns one entry with every stored field', json_encode($response['data'] ?? null));
[$code] = api($port, 'GET', '/request_history/fetch/getHistory.php?id=99999');
check($code === 404, 'an unknown history id is a 404');
[$code] = api($port, 'GET', '/request_history/fetch/getHistory.php?id=abc');
check($code === 404, 'a non-numeric history id is a 404');

if (PHP_OS_FAMILY === 'Windows') {
    // PHP's built-in server runs one request at a time on Windows, so a slow API may hold the UI for 15 seconds, not 30.
    $started = microtime(true);
    [$code, $response] = $run(['url' => "http://127.0.0.1:{$targetPort}/slow"]);
    $took = microtime(true) - $started;
    check($code === 422 && $took >= 13 && $took < 22 && str_contains((string) $response['message'], 'did not answer within 15 seconds') && str_contains((string) $response['message'], 'one request at a time'), 'on Windows a slow API gives up after 15 seconds with a clear message', sprintf('%.1fs %s', $took, json_encode($response)));
    [$code, $response] = api($port, 'GET', '/request_history/fetch/getHistory.php');
    check(str_contains((string) ($response['data'][0]['error'] ?? ''), 'did not answer'), 'the timeout is in the History with its reason', json_encode($response['data'][0] ?? null));
}

[$code, $response] = api($port, 'POST', '/local/readme/parse', ['markdown' => str_repeat('a', 5 * 1024 * 1024 + 1)]);
check($code === 413, 'a README over 5 MB is a 413', json_encode(['code' => $code, 'message' => $response['message'] ?? null]));

// the router: NUL byte, deep-link <base>, security headers
[$status, $headers, $body] = httpRaw($port, '/%00');
check($status === 404 && $body === 'Not found.', 'a NUL byte in the path is a 404, not a 500', (string) $status);
[$status, $headers, $body] = httpRaw($port, '/a%00.js');
check($status === 404, 'a NUL byte inside a file name is a 404 too', (string) $status);
[$status, $headers, $body] = httpRaw($port, '/projects/1');
check($status === 200 && preg_match('/<head[^>]*><base href="\/">/i', $body) === 1 && substr_count($body, './assets/') >= 1, 'the app page has a static <base href="/"> first in <head>, so ./assets/ resolves from the root', (string) $status);
check(($headers['x-content-type-options'] ?? '') === 'nosniff' && ($headers['x-frame-options'] ?? '') === 'DENY' && ($headers['content-security-policy'] ?? '') === "frame-ancestors 'none'" && ($headers['referrer-policy'] ?? '') === 'no-referrer' && !isset($headers['x-powered-by']), 'the app page carries the security headers and no X-Powered-By', json_encode($headers));
[$status, $headers] = httpRaw($port, '/api/local/status');
check($status === 200 && ($headers['x-frame-options'] ?? '') === 'DENY' && ($headers['x-content-type-options'] ?? '') === 'nosniff' && !isset($headers['x-powered-by']), 'API answers carry the security headers too', json_encode($headers));
[$status] = httpRaw($port, '/assets/does-not-exist.js');
check($status === 404, 'a missing asset is still a 404');

stopServe($serve);
stopServe($target);
removeTree($project);
removeTree($targetDir);

// ------------------------------------------------------------------ Cloud tab: what the cloud would reject is refused up front

$stateDir2 = tempDir();
$mockPort2 = freePort();
$mock2 = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$mockPort2}", $root . '/tests/fixtures/mock_cloud.php'], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/avp-mock.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/avp-mock.log', 'a']], $mockPipes2, $root, ['AVP_MOCK_STATE' => $stateDir2 . '/state.json'] + getenv());
waitForPort($mockPort2);
[$serve, $port, $project] = startServe(['AVP_CLOUD_URL' => "http://127.0.0.1:{$mockPort2}/"]);
api($port, 'POST', '/cloud/login', ['email' => 'test@test.com', 'password' => 'test12345']);
[$code, $response] = api($port, 'POST', '/versions/modify/insertUpdateVersion.php', ['version' => 'v1', 'openapi' => ['openapi' => '3.0.3', 'paths' => ['/a' => ['get' => ['responses' => new stdClass()]]]]]);
$vid = (int) ($response['data']['id'] ?? 0);
[$code, $response] = api($port, 'POST', '/cloud/publish', ['version_id' => $vid, 'note' => str_repeat('n', 256)]);
check($code === 400 && str_contains((string) $response['message'], '255 characters'), 'the Cloud tab refuses a note over 255 characters with a 400', json_encode($response));
[$code, $response] = api($port, 'POST', '/cloud/publish', ['version_id' => $vid, 'note' => str_repeat('n', 255)]);
check($code === 200, 'a 255-character note is accepted', json_encode($response));
[$code, $response] = api($port, 'POST', '/versions/modify/insertUpdateVersion.php', ['version' => "bad\nlabel", 'openapi' => ['openapi' => '3.0.3', 'paths' => ['/a' => ['get' => ['responses' => new stdClass()]]]]]);
check($code === 400 && str_contains((string) $response['message'], 'invalid characters'), 'a label with a newline is a 400 through the API', json_encode($response));
[$code, $response] = api($port, 'POST', '/versions/modify/insertUpdateVersion.php', ['version' => 'V1', 'openapi' => ['openapi' => '3.0.3', 'paths' => ['/a' => ['get' => ['responses' => new stdClass()]]]]]);
check($code === 409 && ($response['message'] ?? '') === "Version 'V1' already exists.", 'the duplicate-label answer uses the same sentence as the CLI', json_encode($response));
stopServe($serve);
stopServe($mock2);
removeTree($project);
removeTree($stateDir2);

// ------------------------------------------------------------------ the tester and a self-signed HTTPS server

$openssl = trim((string) @shell_exec('openssl version 2>&1'));
if (!str_starts_with($openssl, 'OpenSSL') && !str_starts_with($openssl, 'LibreSSL')) {
    $skipped[] = 'TLS checks (the openssl command line tool is not available)';
} else {
    $tls = tempDir();
    $made = shell_exec('openssl req -x509 -newkey rsa:2048 -nodes -keyout ' . escapeshellarg($tls . '/key.pem') . ' -out ' . escapeshellarg($tls . '/cert.pem') . ' -days 2 -subj "/CN=localhost" -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" 2>&1');
    if (!is_file($tls . '/cert.pem')) {
        $skipped[] = 'TLS checks (could not create a certificate: ' . trim((string) $made) . ')';
    } else {
        $tlsPort = freePort();
        $server = proc_open(['openssl', 's_server', '-accept', (string) $tlsPort, '-cert', $tls . '/cert.pem', '-key', $tls . '/key.pem', '-www'], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/avp-s_server.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/avp-s_server.log', 'a']], $serverPipes);
        waitForPort($tlsPort);
        [$serve, $port, $project] = startServe();
        $target = "https://127.0.0.1:{$tlsPort}/";

        [$code, $response] = api($port, 'POST', '/runner/execute.php', ['method' => 'GET', 'url' => $target]);
        $message = (string) ($response['message'] ?? '');
        check($code === 422 && str_contains($message, 'could not be verified') && str_contains($message, '127.0.0.1'), 'a self-signed certificate is reported by name, not as "operation failed"', $message);
        check(str_contains($message, 'Skip TLS certificate check'), 'the error tells the user how to fix it', $message);
        check(!str_contains($message, 'Failed to open stream: operation failed'), 'the useless generic wording is gone', $message);

        [$code] = api($port, 'POST', '/projects/modify/updateEnvironments.php', ['environments' => [['id' => 'qa', 'name' => 'QA', 'baseUrl' => "https://127.0.0.1:{$tlsPort}", 'insecure' => true]]]);
        check($code === 200, 'an environment can be saved with the TLS check skipped');
        [$code, $response] = api($port, 'GET', '/projects/fetch/getProject.php');
        check(($response['data']['environments'][0]['insecure'] ?? null) === true, 'the flag is returned to the UI', json_encode($response['data']['environments'] ?? null));
        [$code, $response] = api($port, 'POST', '/runner/execute.php', ['method' => 'GET', 'url' => $target]);
        check($code === 200 && ($response['data']['status'] ?? 0) === 200, 'with the check skipped the request goes through', json_encode($response));
        [$code, $response] = api($port, 'POST', '/runner/execute.php', ['method' => 'GET', 'url' => "https://localhost:{$tlsPort}/"]);
        check($code === 422 && str_contains((string) $response['message'], 'could not be verified'), 'the opt-in does not extend to another host', json_encode($response));

        api($port, 'POST', '/projects/modify/updateEnvironments.php', ['environments' => [['id' => 'qa', 'name' => 'QA', 'baseUrl' => "https://127.0.0.1:{$tlsPort}", 'insecure' => 'yes']]]);
        [$code, $response] = api($port, 'GET', '/projects/fetch/getProject.php');
        check(!isset($response['data']['environments'][0]['insecure']), 'only a real boolean true switches the check off');

        [$code, $response] = api($port, 'POST', '/runner/execute.php', ['method' => 'GET', 'url' => 'http://127.0.0.1:1/']);
        check($code === 422 && str_contains((string) $response['message'], 'refused'), 'other failures keep their own reason (connection refused)', (string) ($response['message'] ?? ''));

        stopServe($serve);
        stopServe($server);
        removeTree($project);
    }
    removeTree($tls);
}

foreach ($skipped as $note) {
    fwrite(STDOUT, "SKIPPED: {$note}\n");
}
echo $failures === 0 ? "PASS: {$checks} checks, 0 failures\n" : "FAILED: {$failures} of {$checks} checks\n";
exit($failures === 0 ? 0 : 1);
