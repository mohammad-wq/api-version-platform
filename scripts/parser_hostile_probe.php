<?php

declare(strict_types=1);

// Exploratory probe: hostile inputs through MarkdownOpenApiParser with warnings-as-errors.
// Not part of the CI suite; used to find fault-tolerance gaps before writing assertions.

require __DIR__ . '/../src/autoload.php';

use Mohammad\ApiVersionPlatform\MarkdownOpenApiParser;

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$cases = [
    'empty string' => '',
    'whitespace only' => "   \n\t\n   ",
    'BOM only' => "\xEF\xBB\xBF",
    'binary garbage' => "\x00\x01\x02\xFF\xFE\xFD binary \x93\x94\x00",
    'invalid utf8' => "Valid \xC3 start, then invalid \x80\x81\x82 bytes end",
    'only rules and headings' => "---\n\n# A\n\n---\n\n# B\n\n---\n",
    'heading with no body' => "# API\n\n## GET `really-long-path-" . str_repeat('x', 5000) . ".php`\n",
    'malformed table' => "# API\n\n## Users\n\n| method | endpoint | description\n| a | b | c | d | e |\n| | | |\n| GET | |\n| get | /u/getUsers.php | list |\n",
    'separator row missing' => "# API\n\n| method | endpoint |\n| GET | /u/getUsers.php |\n",
    'pipe inside code span' => "# API\n\n## Users\n\n| method | endpoint | description |\n| --- | --- | --- |\n| GET | `/u/get`| `Users.php` | list `a|b` |\n",
    'html injection' => "# API\n\n## Users\n\n| method | endpoint | description |\n| --- | --- | --- |\n| GET | /u/getUsers.php | <script>alert(1)</script> & \"quotes\" <img src=x> |\n",
    'duplicate endpoint rows' => "# API\n\n## Users\n\n| method | endpoint | description |\n| --- | --- | --- |\n| GET | /u/getUsers.php | a |\n| GET | /u/getUsers.php | b |\n| get | /u/getUsers.php | c |\n",
    'alias self-reference' => "# API\n\n## Users\n\n| method | endpoint | description |\n| --- | --- | --- |\n| GET | /u/getUsers.php | Also reachable at `/u/getUsers.php` (alias) |\n",
    'alias to unknown endpoint' => "# API\n\n## Users\n\n| method | endpoint | description |\n| --- | --- | --- |\n| GET | /u/getUsers.php | Also reachable at `/nowhere/gone.php` (alias) |\n",
    'query mention with spaces' => "# API\n\n## Users\n\n| method | endpoint | description |\n| --- | --- | --- |\n| GET | /u/getUsers.php | Paginated. Filters: `?search=` the comma `?lang=en-US\" `?we%20ird=1` |\n",
    'unclosed code spans' => "# API\n\n## Users\n\n| method | endpoint | description |\n| --- | --- | --- |\n| GET | `/u/getUsers.php | never closed `{{token}} and ``nested`\n",
    'weird auth values' => "# API\n\n## Users\n\n| method | endpoint | description | auth | auth |\n| --- | --- | --- | --- | --- |\n| GET | /u/getUsers.php | list | true | TRUE |\n| POST | /u/makeUser.php | make | false | nonsense |\n",
    'permission garbage' => "# API\n\n## Users\n\n| method | endpoint | description | permission |\n| --- | --- | --- | --- |\n| GET | /u/getUsers.php | list | `` :: `` `admin:` `:view` `users:view/extra/more` |\n",
    '100k-char line' => "# API\n\n## Users\n\n| method | endpoint | description |\n| --- | --- | --- |\n| GET | /u/getUsers.php | " . str_repeat('lorem ', 15000) . " |\n",
    'deeply repeated headings' => str_repeat("# API\n\n## GET `/deep/getPing.php` — ping\n\ndetail\n\n", 300),
    'status codes weird' => "# API\n\n## Users\n\n| method | endpoint | description | responses |\n| --- | --- | --- | --- |\n| GET | /u/getUsers.php | list | `abc`: nope 0: zero 999999: big **bold**: x |\n",
    'mixed languages rtl' => "# واجهة برمجية

## المستخدمون

| طريقة | نقطة | وصف |
| --- | --- | --- |
| GET | /users/getUsers.php | قائمة المستخدمين ‮مقلوب שמות |
",
    'control characters' => "# API\n\n## Users\n\n| method | endpoint | description |\n| --- | --- | --- |\n| GET | /u/getUsers.php | a\x07b\x1Bc\rd\x08e |\n",
    'endpoint with newline injection' => "# API\n\n## Users\n\n| method | endpoint | description |\n| --- | --- | --- |\n| GET | /u/getUsers.php\\nhello | list |\n",
];

$contract = function (array $doc): array {
    // Returns list of contract violations for any parsed result.
    $violations = [];
    if (($doc['openapi']['openapi'] ?? null) !== '3.0.3') $violations[] = 'missing openapi version';
    foreach ($doc['openapi']['paths'] ?? [] as $path => $item) {
        if (!is_string($path) || $path === '') $violations[] = "bad path key: " . var_export($path, true);
        foreach ($item as $method => $op) {
            if (!in_array(strtolower($method), ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'])) continue;
            if (!isset($op['responses'])) $violations[] = "$method $path missing responses";
            $seen = [];
            foreach ($op['parameters'] ?? [] as $p) {
                $key = strtolower($p['name'] ?? '{none}') . ':' . ($p['in'] ?? '{none}');
                if (isset($seen[$key])) $violations[] = "$method $path duplicate parameter $key";
                $seen[$key] = true;
            }
        }
    }
    return $violations;
};

$failures = 0;
foreach ($cases as $name => $markdown) {
    echo "== {$name}\n";
    try {
        $result = MarkdownOpenApiParser::parse($markdown);
        $violations = $contract($result);
        $endpoints = $result['statistics']['endpoints'] ?? -1;
        $warned = count($result['warnings'] ?? []);
        echo "   ok: {$endpoints} endpoints, {$warned} warnings";
        echo $violations ? "\n   VIOLATIONS: " . implode('; ', $violations) : "";
        if ($violations) $failures++;
        echo "\n";
    } catch (Throwable $e) {
        $failures++;
        echo "   ERROR: " . get_class($e) . ": " . substr($e->getMessage(), 0, 160) . "\n";
    }
}
echo $failures === 0 ? "\nALL HOSTILE CASES TOLERATED, CONTRACT HELD\n" : "\n{$failures} CASE(S) BROKE\n";
exit($failures === 0 ? 0 : 1);
