<?php

declare(strict_types=1);

// PSR-4 loader for runs without Composer's autoloader (the built-in server's router, a git checkout).
spl_autoload_register(static function (string $class): void {
    $prefix = 'Mohammad\\ApiVersionPlatform\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});
