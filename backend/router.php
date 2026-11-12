<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api')) {
    require __DIR__ . '/src/Database.php';
    require __DIR__ . '/src/AttentionInbox.php';
    require __DIR__ . '/src/Application.php';
    (new Application(Database::connect()))->run();
    return;
}
$root = realpath(__DIR__ . '/../public');
$file = $root ? realpath($root . $path) : false;
if ($file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
if ($root && is_file($root . '/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/index.html');
    return;
}
http_response_code(404);
echo 'Frontend is not built. See docs/development.md.';
