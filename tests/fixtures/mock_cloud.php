<?php

/**
 * A tiny stand-in for the hosted platform's API, for tests/publish_test.php.
 * Run as: php -S 127.0.0.1:PORT tests/fixtures/mock_cloud.php   (state in $AVP_MOCK_STATE, a JSON file)
 * Accepts test@test.com / test12345 and issues the token "good-token".
 */

declare(strict_types=1);

$stateFile = getenv('AVP_MOCK_STATE') ?: sys_get_temp_dir() . '/avp-mock-cloud.json';
$db = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : ['projects' => [], 'versions' => [], 'next' => 1];
$save = static function () use (&$db, $stateFile): void {
    file_put_contents($stateFile, json_encode($db));
};
$out = static function (int $code, mixed $data = null, string $message = 'ok'): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['statusCode' => $code, 'message' => $message, 'data' => $data]);
    exit;
};

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$in = json_decode((string) file_get_contents('php://input'), true) ?? [];

if (str_ends_with($path, '/auth/auth/login.php')) {
    ($in['email'] ?? '') === 'test@test.com' && ($in['password'] ?? '') === 'test12345'
        ? $out(200, ['token' => 'good-token', 'user' => ['id' => 3, 'name' => 'Tester', 'email' => 'test@test.com', 'role' => 'developer']], 'Login successful')
        : $out(401, null, 'Invalid email or password');
}
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer good-token') $out(401, null, 'Unauthorized');

if (str_ends_with($path, '/projects/fetch/getProjects.php')) $out(200, $db['projects']);
if (str_ends_with($path, '/projects/modify/insertUpdateProject.php')) {
    $id = $db['next']++;
    $db['projects'][] = ['id' => $id, 'name' => $in['name'], 'description' => $in['description'] ?? null];
    $save();
    $out(201, ['project_id' => $id]);
}
if (str_ends_with($path, '/versions/fetch/getVersions.php')) {
    $out(200, array_values(array_filter($db['versions'], fn (array $v) => $v['project_id'] == ($_GET['project_id'] ?? 0))));
}
if (str_ends_with($path, '/versions/modify/insertUpdateVersion.php')) {
    foreach ($db['versions'] as $v) {
        if ($v['project_id'] == $in['project_id'] && $v['version'] === $in['version']) $out(409, null, "Version '{$in['version']}' already exists in this project");
    }
    $id = $db['next']++;
    $db['versions'][] = ['id' => $id, 'project_id' => $in['project_id'], 'version' => $in['version'], 'note' => $in['note'] ?? null, 'openapi' => json_encode($in['openapi'])];
    $save();
    $out(201, ['id' => $id, 'project_id' => $in['project_id'], 'version' => $in['version']]);
}
$out(404, null, 'nope');
