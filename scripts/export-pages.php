<?php
declare(strict_types=1);

require __DIR__ . '/../backend/src/Database.php';
require __DIR__ . '/../backend/src/AttentionInbox.php';
require __DIR__ . '/../backend/src/Application.php';

function readDemoResponse(Application $app, int $userId, string $path): mixed
{
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/api' . $path;
    $_SERVER['HTTP_X_DEMO_USER'] = (string) $userId;

    ob_start();
    try {
        $app->run();
    } finally {
        $body = ob_get_clean();
    }

    $status = http_response_code();
    if ($status !== 200) {
        throw new RuntimeException("GET /api{$path} for demo user {$userId} returned HTTP {$status}");
    }

    $response = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
    if (!$response instanceof stdClass || !property_exists($response, 'data')) {
        throw new RuntimeException("GET /api{$path} for demo user {$userId} has no data");
    }
    return $response->data;
}

if ($argc !== 2 || $argv[1] === '') {
    fwrite(STDERR, "Usage: php scripts/export-pages.php <output.json>\n");
    exit(2);
}

try {
    // Always use a fresh demo fixture, even if the environment points to another DB.
    putenv('DB_PATH=:memory:');
    putenv('SEED_NOW=' . gmdate('Y-m-d\TH:i:s\Z'));
    $app = new Application(Database::connect());
    $snapshot = [];

    foreach ([1, 2, 3] as $userId) {
        $routes = [];
        foreach (['/context', '/projects', '/attention'] as $path) {
            $routes[$path] = readDemoResponse($app, $userId, $path);
        }
        foreach ($routes['/projects'] as $project) {
            $path = "/projects/{$project->id}/tasks";
            $routes[$path] = readDemoResponse($app, $userId, $path);
            foreach ($routes[$path] as $task) {
                $pathToTask = "/tasks/{$task->id}";
                $routes[$pathToTask] = readDemoResponse($app, $userId, $pathToTask);
            }
        }
        $snapshot[$userId] = $routes;
    }

    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (@file_put_contents($argv[1], $json, LOCK_EX) !== strlen($json)) {
        throw new RuntimeException("Could not write the snapshot to {$argv[1]}");
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Pages snapshot failed: ' . $error->getMessage() . "\n");
    exit(1);
}
