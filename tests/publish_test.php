<?php

/**
 * End-to-end tests for `avp login`, `avp logout` and `avp publish` against a mock cloud server.
 * Run: `php tests/publish_test.php`
 */

declare(strict_types=1);

error_reporting(E_ALL);

$root = dirname(__DIR__);
$failures = 0;
$checks = 0;
function check(bool $ok, string $message, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}" . ($detail !== '' ? "\n      " . str_replace("\n", "\n      ", trim($detail)) : '') . "\n");
    }
}
function tempDir(string $suffix = ''): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'avp-pub-' . bin2hex(random_bytes(4)) . $suffix;
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

/** @return array{0: int, 1: string} exit code and stdout+stderr */
function avp(string $cwd, array $args, array $env = [], string $stdin = ''): array
{
    global $root, $cloudUrl;
    $base = array_filter(getenv(), fn ($k) => !str_starts_with((string) $k, 'AVP_'), ARRAY_FILTER_USE_KEY);
    $process = proc_open(
        array_merge([PHP_BINARY, $root . '/bin/avp'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        $env + ['AVP_CLOUD_URL' => $cloudUrl] + $base,
    );
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $code = proc_close($process);
    // The child may have created or deleted files this process stat()ed before; PHP would answer is_file() from its cache.
    clearstatcache();
    return [$code, $out];
}

// A free port for the mock cloud.
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
fclose($probe);
$cloudUrl = "http://127.0.0.1:{$port}/";
$stateFile = tempDir() . '/state.json';
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $root . '/tests/fixtures/mock_cloud.php'], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/avp-mock.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/avp-mock.log', 'a']], $serverPipes, $root, ['AVP_MOCK_STATE' => $stateFile] + getenv());
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100_000);

$work = tempDir('-notes-api');
$project = $work; // the folder name is the cloud project name
copy($root . '/resources/templates/api-readme.md', $project . '/README.md');
$name = basename($project);
$good = ['AVP_CLOUD_EMAIL' => 'test@test.com', 'AVP_CLOUD_PASSWORD' => 'test12345'];

// Not signed in and nothing to read from: fails cleanly (no hang)
[$code, $out] = avp($project, ['publish']);
check($code === 1 && str_contains($out, 'there is no terminal to type in') && str_contains($out, 'vendor/bin/avp login'), 'publish without sign-in or input says what to do', $out);

// login: wrong then right password through piped input
[$code, $out] = avp($project, ['login'], [], "test@test.com\nwrong-password\n");
check($code === 1 && str_contains($out, 'Invalid email or password'), 'login with a wrong password fails', $out);
[$code, $out] = avp($project, ['login'], [], "test@test.com\ntest12345\n");
check($code === 0 && str_contains($out, 'Signed in as') && str_contains($out, 'Tester'), 'login with piped email and password works', $out);
check(is_file($project . '/.avp/cloud.json'), 'the sign-in is saved in .avp/cloud.json');
[$code, $out] = avp($project, ['login']);
check($code === 0 && str_contains($out, 'Already signed in'), 'login when already signed in says so', $out);

// first publish: local version made from the README, cloud project named after the folder
[$code, $out] = avp($project, ['publish']);
check($code === 0 && str_contains($out, 'Created local version v1.0.0') && str_contains($out, "Created cloud project \"{$name}\"") && str_contains($out, 'Published v1.0.0'), 'first publish creates the local version and the cloud project', $out);
$state = json_decode((string) file_get_contents($stateFile), true);
check(count($state['projects']) === 1 && $state['projects'][0]['name'] === $name && count($state['versions']) === 1, 'the cloud project is named after the project folder');
check(json_decode($state['versions'][0]['openapi'], true)['openapi'] === '3.0.3', 'the OpenAPI document reaches the cloud');

// nothing changed
[$code, $out] = avp($project, ['publish']);
check($code === 0 && str_contains($out, 'Nothing new to publish'), 'publishing an unchanged document is a no-op', $out);

// changed README -> next label
file_put_contents($project . '/README.md', "\n| GET | `fetch/getExtra.php` | true | Extra. |\n", FILE_APPEND);
[$code, $out] = avp($project, ['publish', '--note', 'added extra']);
check($code === 0 && str_contains($out, 'Created local version v1.0.1') && str_contains($out, 'Published v1.0.1'), 'an edited document is published as the next label', $out);

// explicit label, then the same label again
[$code, $out] = avp($project, ['publish', 'v2.0.0']);
check($code === 0 && str_contains($out, 'Published v2.0.0'), 'an explicit label is created from the document and published', $out);
[$code, $out] = avp($project, ['publish', 'v2.0.0']);
check($code === 1 && str_contains($out, 'already exists in the cloud project'), 'publishing an existing cloud label is refused', $out);

// another project name
[$code, $out] = avp($project, ['publish', 'v1.0.0', '--project', 'Other API']);
check($code === 0 && str_contains($out, 'Created cloud project "Other API"') && str_contains($out, 'Published v1.0.0'), '--project publishes to another cloud project', $out);
$state = json_decode((string) file_get_contents($stateFile), true);
check(count($state['projects']) === 2, 'two cloud projects now exist');

// what the cloud would reject is refused up front: nothing is created locally, nothing is sent
$versionsBefore = (string) @file_get_contents($project . '/.avp/versions.json');
[$code, $out] = avp($project, ['publish', 'v9.9.9', '--note', str_repeat('n', 256)]);
check($code === 1 && str_contains($out, '--note is limited to 255 characters (the cloud limit).') && (string) @file_get_contents($project . '/.avp/versions.json') === $versionsBefore, 'a note over 255 characters is refused before anything is created', $out);
[$code, $out] = avp($project, ['publish', 'v9.9.9', '--project', '   ']);
check($code === 1 && str_contains($out, '--project needs a project name') && (string) @file_get_contents($project . '/.avp/versions.json') === $versionsBefore, 'a blank --project is refused instead of silently using the folder name', $out);
[$code, $out] = avp($project, ['publish', 'v9.9.9', '--project', str_repeat('P', 256)]);
check($code === 1 && str_contains($out, '--project is limited to 255 characters') && (string) @file_get_contents($project . '/.avp/versions.json') === $versionsBefore, 'a project name over 255 characters is refused', $out);
[$code, $out] = avp($project, ['publish', 'v9.9.9', '--note', str_repeat('n', 255)]);
check($code === 0 && str_contains($out, 'Published v9.9.9'), 'a 255-character note is still accepted', $out);

// expired sign-in: asks again
file_put_contents($project . '/.avp/cloud.json', json_encode(['url' => $cloudUrl, 'token' => 'stale', 'user' => null]));
[$code, $out] = avp($project, ['publish'], [], "test@test.com\ntest12345\n");
check($code === 0 && str_contains($out, 'sign-in expired') && str_contains($out, 'Signed in as'), 'an expired sign-in asks to sign in again', $out);

// logout, then environment credentials (no prompt)
[$code, $out] = avp($project, ['logout']);
check($code === 0 && str_contains($out, 'Signed out') && !is_file($project . '/.avp/cloud.json'), 'logout removes the saved sign-in', $out);
[$code, $out] = avp($project, ['logout']);
check($code === 0 && str_contains($out, 'not signed in'), 'logout when signed out is harmless', $out);
[$code, $out] = avp($project, ['publish'], $good);
check($code === 0 && str_contains($out, 'Signed in as'), 'AVP_CLOUD_EMAIL / AVP_CLOUD_PASSWORD sign in without a prompt', $out);

// the automatic label skips names the cloud project already has
foreach (['.avp/versions', '.avp/versions.json'] as $gone) is_dir("{$project}/{$gone}") ? removeTree("{$project}/{$gone}") : @unlink("{$project}/{$gone}");
file_put_contents($project . '/README.md', "\n| GET | `fetch/getMore.php` | true | More. |\n", FILE_APPEND);
[$code, $out] = avp($project, ['publish']);
check($code === 0 && str_contains($out, 'Created local version v1.0.2'), 'the automatic label skips labels the cloud already has', $out);

// a wrong password in the environment is reported, not retried forever
avp($project, ['logout']);
[$code, $out] = avp($project, ['publish'], ['AVP_CLOUD_EMAIL' => 'test@test.com', 'AVP_CLOUD_PASSWORD' => 'nope']);
check($code === 1 && str_contains($out, 'Invalid email or password'), 'bad environment credentials fail with the cloud message', $out);

// unreachable server
[$code, $out] = avp($project, ['publish'], $good + ['AVP_CLOUD_URL' => 'http://127.0.0.1:1/']);
check($code === 1 && str_contains($out, 'Could not reach the cloud server'), 'an unreachable cloud server is reported clearly', $out);

proc_terminate($server);
removeTree($work);
removeTree(dirname($stateFile));
echo $failures === 0 ? "PASS: {$checks} checks, 0 failures\n" : "FAILED: {$failures} of {$checks} checks\n";
exit($failures === 0 ? 0 : 1);
