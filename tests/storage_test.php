<?php

/**
 * Tests for the JSON-file store, the version service on top of it, and the cloud URL rules.
 * Run: `php tests/storage_test.php`
 */

declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    fwrite(STDERR, "$file:$line: $message\n");
    exit(1);
});

require_once dirname(__DIR__) . '/src/autoload.php';

use Mohammad\ApiVersionPlatform\Cloud;
use Mohammad\ApiVersionPlatform\Storage;
use Mohammad\ApiVersionPlatform\VersionService;

$failures = 0;
$checks = 0;
function check(bool $condition, string $message): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}
function throwsException(callable $fn, string $class): bool
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e instanceof $class;
    }
    return false;
}
function tempDir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'avp-test-' . bin2hex(random_bytes(4));
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

$root = tempDir();
$state = $root . '/.avp';

// Storage: versions
$storage = new Storage($state);
check(is_dir($state . '/versions'), 'state folders are created');
check(is_file($state . '/.gitignore'), 'the new state folder ignores itself in git');
check($storage->versions() === [], 'a new store has no versions');
$a = $storage->addVersion(['label' => 'v1', 'note' => null, 'source_hash' => 'h1', 'openapi_hash' => 'o1', 'warnings' => [], 'statistics' => [], 'created_at' => 'now'], '# md', '{"openapi":"3.0.3","paths":{},"x":{}}');
$b = $storage->addVersion(['label' => 'v2', 'note' => 'n', 'source_hash' => null, 'openapi_hash' => 'o2', 'warnings' => [], 'statistics' => [], 'created_at' => 'now'], null, '{}');
check($a === 1 && $b === 2, 'ids count up from 1');
check(count($storage->versions()) === 2 && !isset($storage->versions()[0]['markdown']), 'the index holds no payload');
check($storage->version(1)['markdown'] === '# md' && str_contains($storage->version(1)['openapi_json'], '"x":{}'), 'the payload comes back, empty objects intact');
check($storage->updateVersion(2, ['note' => 'changed'], '{"a":1}') && $storage->version(2)['note'] === 'changed' && $storage->openapiJson(2) === '{"a":1}', 'update changes fields and payload');
check(!$storage->updateVersion(99, ['note' => 'x']), 'update of a missing version reports false');
check($storage->deleteVersion(1) && $storage->version(1) === null && !is_file($state . '/versions/1.json'), 'delete removes the row and its payload');
check($storage->addVersion(['label' => 'v3', 'source_hash' => null, 'openapi_hash' => 'o', 'warnings' => [], 'statistics' => [], 'created_at' => 'now'], null, '{}') === 3, 'ids are never reused after a delete');
check((new Storage($state))->version(2) !== null, 'a second Storage instance reads the same data');

// Storage: history and meta
for ($i = 1; $i <= 205; $i++) $storage->addHistory(['method' => 'GET', 'url' => "http://x/{$i}"]);
$history = $storage->history(1000);
check(count($history) === 200 && $history[0]['url'] === 'http://x/205', 'history keeps the newest 200, newest first');
check(count($storage->history(5)) === 5, 'history honours the limit');
$storage->clearHistory();
check($storage->history(10) === [], 'clearHistory empties it');
$storage->addHistory(['method' => 'GET', 'url' => 'u']);
check($storage->history(1)[0]['id'] > 205, 'history ids keep counting after a clear');
check($storage->meta('project.name') === null, 'missing meta is null');
$storage->setMeta('project.name', 'Demo');
check($storage->meta('project.name') === 'Demo', 'meta round-trips');

// Storage: corrupt file is reported, not silently emptied
file_put_contents($state . '/versions.json', '{broken');
check(throwsException(fn () => $storage->versions(), RuntimeException::class), 'a corrupt index raises an error instead of losing data');
file_put_contents($state . '/versions.json', json_encode(['next_id' => 4, 'versions' => []]));

// Storage: legacy path argument (".avp/storage.sqlite" from old configs) maps to the folder
check((new Storage($state . '/storage.sqlite'))->directory() === $state, 'an old *.sqlite path maps to its folder');
check((new Storage($root . '/data/my.sqlite'))->directory() === $root . '/data/my', 'a custom *.sqlite path gets its own folder, never its parent');

// VersionService on the JSON store
$projectDir = tempDir();
$readme = $projectDir . '/API.md';
copy(dirname(__DIR__) . '/resources/templates/api-readme.md', $readme);
$service = new VersionService($readme, new Storage($projectDir . '/.avp'));
check($service->status()['state'] === 'uninitialized', 'a fresh project is uninitialized');
$first = $service->create('v1.0.0', 'first');
check($first['id'] === 1 && $service->status()['state'] === 'clean', 'create makes the working copy clean');
check(throwsException(fn () => $service->create('v1.0.0', '', true), OverflowException::class), 'labels are unique');
check(throwsException(fn () => $service->create('V1.0.0', '', true), OverflowException::class), 'labels are unique ignoring case');
check($service->nextLabel() === 'v1.0.1', 'next label bumps the patch number');
// Labels: control characters are refused, ordinary punctuation, unicode and spaces are fine, and the duplicate message is one sentence everywhere.
foreach (["v3\nX", "v\x07", "a\tb", "\u{FEFF}v1", "bad\xC3"] as $badLabel) {
    check(throwsException(fn () => VersionService::label($badLabel), InvalidArgumentException::class), 'a label with control or invalid characters is refused: ' . json_encode($badLabel, JSON_INVALID_UTF8_SUBSTITUTE));
}
check(VersionService::label("  v1/2 \u{00E9}\u{4E16}  ") === "v1/2 \u{00E9}\u{4E16}", 'slashes, spaces and unicode letters are allowed and the label is trimmed');
check(VersionService::label(str_repeat('a', 50)) === str_repeat('a', 50) && throwsException(fn () => VersionService::label(str_repeat('a', 51)), InvalidArgumentException::class), 'labels are limited to 50 characters');
try {
    $service->create('V1.0.0', '', true);
    check(false, 'duplicate label must throw');
} catch (OverflowException $e) {
    check($e->getMessage() === "Version 'V1.0.0' already exists.", 'the duplicate-label message is "Version \'<label>\' already exists."');
}
file_put_contents($readme, "\n- changed\n", FILE_APPEND);
check($service->status()['state'] === 'modified', 'an edited document is modified');
$summary = $service->workingCopySummary();
check(is_array($summary) && $summary['endpointsAdded'] === 0, 'the working copy summary compares against the baseline');
$imported = $service->createFromOpenApi('v2-import', 'note', ['openapi' => '3.0.3', 'paths' => ['/a' => ['get' => ['responses' => new stdClass()]]]]);
check($imported['source_hash'] === null && $service->status()['latest_version'] === 'v1.0.0', 'imported versions do not move the README baseline');
check($service->newestId() === $imported['id'] && $service->all()[0]['label'] === 'v2-import', 'the list is newest first');
$service->update($imported['id'], 'v2-renamed', 'renamed', null);
check($service->get('v2-renamed')['note'] === 'renamed', 'update renames a version');
check(throwsException(fn () => $service->update($imported['id'], 'v1.0.0', '', null), OverflowException::class), 'rename onto a taken label is refused');
$service->delete($imported['id']);
check($service->get('v2-renamed') === null && throwsException(fn () => $service->delete($imported['id']), OutOfBoundsException::class), 'delete removes a version once');

// avp init is safe to run again and --readme changes only the readme entry
$initDir = tempDir();
$first = Mohammad\ApiVersionPlatform\Server::init($initDir);
check($first['configAction'] === 'created' && is_file($initDir . '/avp.config.php') && is_dir($initDir . '/.avp') && $first['documentCreated'] === true, 'init creates the config, .avp/ and API.md');
$customConfig = str_replace("// 'port' => 8080,", "'port' => 8123,", (string) file_get_contents($initDir . '/avp.config.php')) . "\n// my own note\n";
file_put_contents($initDir . '/avp.config.php', $customConfig);
$again = Mohammad\ApiVersionPlatform\Server::init($initDir);
check($again['configAction'] === 'unchanged' && file_get_contents($initDir . '/avp.config.php') === $customConfig && $again['documentCreated'] === false, 'running init again leaves the config untouched and does not throw');
unlink($initDir . '/API.md');
check(Mohammad\ApiVersionPlatform\Server::init($initDir)['documentCreated'] === true && is_file($initDir . '/API.md'), 'running init again still recreates a missing API.md');
mkdir($initDir . '/docs');
file_put_contents($initDir . '/docs/api.md', "# X\n");
$pinned = Mohammad\ApiVersionPlatform\Server::init($initDir, false, 'docs/api.md');
$pinnedText = (string) file_get_contents($initDir . '/avp.config.php');
check($pinned['configAction'] === 'updated' && str_contains($pinnedText, "'readme' => 'docs/api.md',") && str_contains($pinnedText, "'port' => 8123,") && str_contains($pinnedText, '// my own note'), 'init --readme on an existing config changes only the readme entry');
check(Mohammad\ApiVersionPlatform\Server::init($initDir, false, 'docs/other.md')['warnings'] !== [], 'init --readme on an existing config warns when the file does not exist yet');
check(throwsException(fn () => Mohammad\ApiVersionPlatform\Server::init(tempDir(), false, 'nope.md'), RuntimeException::class), 'a fresh init still refuses a --readme file that does not exist');
file_put_contents($initDir . '/avp.config.php', "<?php\nreturn [];\n");
check(throwsException(fn () => Mohammad\ApiVersionPlatform\Server::init($initDir, false, 'docs/api.md'), RuntimeException::class), 'init --readme explains when the config has no readme entry to update');
check(Mohammad\ApiVersionPlatform\Server::init($initDir, true)['configAction'] === 'overwritten' && !str_contains((string) file_get_contents($initDir . '/avp.config.php'), '8123'), 'init --force still rewrites the whole file');

// Cloud URL rules
check(Cloud::isCleartext('http://192.168.0.5/x') && !Cloud::isCleartext('https://a.b/') && !Cloud::isCleartext('http://localhost:8080/') && !Cloud::isCleartext('http://127.0.0.1/'), 'plain http to another machine is flagged as cleartext');
check(Cloud::apiBase('https://platform.example/app/') === 'https://platform.example/app/backend', 'a site URL gets /backend');
check(Cloud::apiBase('https://x.test/app/backend/') === 'https://x.test/app/backend', 'a backend URL is kept');
check(throwsException(fn () => Cloud::apiBase('ftp://x'), InvalidArgumentException::class), 'only http(s) cloud URLs are accepted');
$cloud = new Cloud($projectDir . '/.avp');
check($cloud->status()['connected'] === false && $cloud->status()['url'] === Cloud::DEFAULT_URL, 'the cloud starts signed out at the default URL');
check(throwsException(fn () => $cloud->projects(), Mohammad\ApiVersionPlatform\CloudException::class), 'cloud calls need a sign-in');

removeTree($root);
removeTree($projectDir);
echo $failures === 0 ? "PASS: {$checks} checks, 0 failures\n" : "FAILED: {$failures} of {$checks} checks\n";
exit($failures === 0 ? 0 : 1);
