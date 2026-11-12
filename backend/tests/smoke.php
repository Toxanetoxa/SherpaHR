<?php
declare(strict_types=1);

// Black-box API regression checks against a disposable server and database.
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "This PHP interpreter needs pdo_sqlite. Use the Compose test command from README.\n");
    exit(1);
}
$directory = sys_get_temp_dir() . '/project-desk-test-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$socket) {
    throw new RuntimeException($errstr);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$environment = getenv();
$environment['DB_PATH'] = $directory . '/test.sqlite';
$environment['SEED_NOW'] = '2026-11-11T08:00:00Z';
$process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/../router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']],
    $pipes, dirname(__DIR__, 2), $environment);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start test server');
}
fclose($pipes[0]);
$count = 0;
$exitStatus = 0;

function request(string $method, string $path, mixed $body = null, int $user = 1): array
{
    global $base;
    $options = ['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 5,
        'header' => "Content-Type: application/json\r\nX-Demo-User: $user\r\n",
    ]];
    if ($body !== null) {
        $options['http']['content'] = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
    }
    $raw = file_get_contents($base . $path, false, stream_context_create($options));
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return [(int) $match[1], json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}

function check(bool $condition, string $message): void
{
    global $count;
    ++$count;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

function attention(int $user = 1): array
{
    [$status, $response] = request('GET', '/api/attention', null, $user);
    if ($status !== 200 || !isset($response['data']) || !array_is_list($response['data'])) {
        throw new RuntimeException('Expected a 200 attention list, got ' . $status);
    }
    $tasks = array_column($response['data'], 'task');
    $items = array_combine(array_column($tasks, 'id'), $response['data']);
    if (count($items) !== count($response['data'])) {
        throw new RuntimeException('Attention contains duplicate tasks');
    }
    return $items;
}

try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        if (@file_get_contents($base . '/api/health') !== false) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    check($ready, 'server starts with fresh SQLite');
    [$status, $response] = request('GET', '/api/context');
    check($status === 200 && $response['data']['user']['id'] === 1, 'default persona is Anna');
    check($response['data']['seeded_at'] === '2026-11-11T08:00:00Z', 'seed clock can be reproduced');
    [$status, $response] = request('GET', '/api/projects');
    check($status === 200 && array_column($response['data'], 'id') === [1, 2, 3, 4], 'Anna sees only her projects');
    check($response['data'][3]['archived'] === true, 'archive marker is boolean');
    [$status, $response] = request('GET', '/api/projects', null, 2);
    check(array_column($response['data'], 'id') === [1, 2, 4], 'Boris has different membership');
    [$status, $response] = request('GET', '/api/projects', null, 3);
    check(array_column($response['data'], 'id') === [1, 3, 5], 'Vera can see her separate project');
    check(request('GET', '/api/projects', null, 999)[0] === 401, 'unknown persona rejected');
    check(request('GET', '/api/projects/5/tasks')[0] === 404, 'hidden project is not disclosed');
    check(request('GET', '/api/tasks/11')[0] === 404, 'hidden task is not disclosed');
    check(request('PATCH', '/api/tasks/11', ['title' => 'Forbidden', 'version' => 1])[0] === 404, 'hidden task cannot be edited');
    check(request('POST', '/api/tasks/11/comments', ['body' => 'Forbidden'])[0] === 404, 'hidden task cannot receive comments');
    check(request('POST', '/api/projects/5/tasks', ['title' => 'Forbidden'])[0] === 404, 'hidden project cannot receive tasks');
    [$status, $response] = request('GET', '/api/tasks/1');
    check($status === 200 && count($response['data']['comments']) === 2, 'task detail includes discussion');
    check($response['data']['due_at'] === '2026-11-12T08:00:00Z', 'deadline relative to initial seed');
    check(request('POST', '/api/projects/4/tasks', ['title' => 'No'])[0] === 409, 'archive rejects task creation');
    check(request('PATCH', '/api/tasks/10', ['title' => 'No', 'version' => 1])[0] === 409, 'archive rejects task edits');
    check(request('POST', '/api/tasks/10/comments', ['body' => 'No'])[0] === 409, 'archive rejects comments');
    check(request('POST', '/api/projects/1/tasks', ['title' => '  '])[0] === 422, 'empty title rejected');
    check(request('POST', '/api/projects/2/tasks', ['title' => 'Bad member', 'assignee_id' => 3])[0] === 422, 'assignee must be a project member');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Bad date', 'due_at' => '2026-02-31T00:00:00Z'])[0] === 422, 'impossible date rejected');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Bad status', 'status' => 'unknown'])[0] === 422, 'invalid status rejected');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Extra field', 'project_id' => 5])[0] === 422, 'cannot move task through extra field');
    check(request('POST', '/api/projects/1/tasks', '{broken')[0] === 400, 'malformed JSON returns 400');
    check(request('POST', '/api/projects/1/tasks', '[]')[0] === 400, 'body must be an object');
    check(request('POST', '/api/projects/1/tasks', str_repeat('x', 33000))[0] === 413, 'oversized body rejected');
    [$status, $response] = request('POST', '/api/projects/1/tasks', [
        'title' => 'Проверка сохранения', 'description' => 'Текст с <b>разметкой</b>',
        'assignee_id' => 2, 'due_at' => '2026-12-01T09:30:00Z',
    ]);
    $task = $response['data'];
    check($status === 201 && $task['id'] > 12 && $task['status'] === 'open', 'create task with defaults');
    check(request('PATCH', '/api/tasks/' . $task['id'], ['title' => 'New'])[0] === 422, 'edit requires version');
    [$status, $response] = request('PATCH', '/api/tasks/' . $task['id'], ['status' => 'done', 'assignee_id' => null, 'due_at' => null, 'version' => 1]);
    check($status === 200 && $response['data']['version'] === 2 && $response['data']['due_at'] === null, 'edit fields and increment version');
    check(request('PATCH', '/api/tasks/' . $task['id'], ['title' => 'Stale', 'version' => 1])[0] === 409, 'stale write does not overwrite newer data');
    check(request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => ' '])[0] === 422, 'empty comment rejected');
    [$status, $response] = request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => '<script>window.pwned=1</script>'], 2);
    check($status === 201 && $response['data']['author'] === 'Борис · разработчик', 'comment records acting user');
    [$status, $response] = request('GET', '/api/tasks/' . $task['id']);
    check($response['data']['status'] === 'done' && count($response['data']['comments']) === 1, 'changes persist across requests');
    check($response['data']['comments'][0]['body'] === '<script>window.pwned=1</script>', 'comment content retained as plain data');
    [$status, $response] = request('GET', '/api/projects/1/tasks');
    check(count($response['data']) === 6, 'initialization does not duplicate or reset data');
    check(request('GET', '/api/tasks/9999')[0] === 404, 'missing task returns 404');
    check(request('GET', '/api/unknown')[0] === 404, 'unknown route returns 404');

    $inbox = attention();
    check(isset($inbox[1], $inbox[5]) && $inbox[1]['task']['assignee_id'] === 2
        && $inbox[5]['project_id'] === 2, 'attention spans led projects and other assignees');
    check(!array_intersect(array_keys($inbox), [3, 9, 10, 11]), 'attention excludes done, archived and foreign tasks');
    check(attention(2) === [], 'membership alone does not grant a project in attention');
    check(array_keys(attention(3)) === [11], 'a leader sees attention only for their own active projects');
    check(request('GET', '/api/attention', null, 999)[0] === 401, 'attention rejects an unknown persona');

    // Use the running server clock rather than assuming the fixed demo seed is recent.
    [, $response] = request('GET', '/api/context');
    $now = new DateTimeImmutable($response['data']['server_now']);
    $deadline = fn(string $offset): string => $now->modify($offset)->format('Y-m-d\TH:i:s\Z');
    $makeTask = function (string $title, array $fields = [], int $project = 1): array {
        [$status, $response] = request('POST', "/api/projects/$project/tasks", ['title' => $title] + $fields);
        if ($status !== 201) {
            throw new RuntimeException('Could not prepare attention test task: ' . $title);
        }
        return $response['data'];
    };
    $blockedOverdue = $makeTask('Заблокирована и просрочена', ['status' => 'blocked', 'due_at' => $deadline('-2 hours')]);
    $blocked = $makeTask('Заблокирована', ['status' => 'blocked']);
    $overdue = $makeTask('Просрочена', ['due_at' => $deadline('-1 hour')]);
    $help = $makeTask('Запрос помощи', [], 3);
    $dueSoon = $makeTask('Близкий срок', ['due_at' => $deadline('+23 hours 55 minutes')]);
    $later = $makeTask('Срок за пределами окна', ['due_at' => $deadline('+24 hours 5 minutes')]);
    $neutral = $makeTask('Без препятствий');
    $stale = $makeTask('Устаревший комментарий');

    [$status, $response] = request('POST', '/api/tasks/' . $help['id'] . '/comments', ['body' => 'Нужна помощь руководителя с выбором.'], 3);
    $evidence = $response['data'];
    check($status === 201, 'teammate can write a request for help');
    request('POST', '/api/tasks/' . $help['id'] . '/comments', ['body' => 'Клиент пришлёт пример завтра.'], 3);
    request('POST', '/api/tasks/' . $neutral['id'] . '/comments', ['body' => 'Препятствий нет. Помощь не нужна.'], 2);
    [$status, $response] = request('POST', '/api/tasks/' . $stale['id'] . '/comments', ['body' => 'Нужна помощь руководителя с выбором.'], 2);
    check($status === 201, 'request for help can be aged in the disposable fixture');
    $fixtureDb = new PDO('sqlite:' . $environment['DB_PATH'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $fixtureDb->prepare('UPDATE comments SET created_at = ? WHERE id = ?')
        ->execute([$deadline('-7 days -5 minutes'), $response['data']['id']]);

    $inbox = attention();
    $ranked = array_column([$blockedOverdue, $blocked, $overdue, $help, $dueSoon], 'id');
    check(array_values(array_intersect(array_keys($inbox), $ranked)) === $ranked, 'attention orders stronger signals before weaker ones');
    check(in_array('blocked', $inbox[$blockedOverdue['id']]['signals'], true)
        && in_array('overdue', $inbox[$blockedOverdue['id']]['signals'], true), 'blocked and overdue signals coexist');
    check($inbox[$dueSoon['id']]['signals'] === ['due_soon'] && !isset($inbox[$later['id']]), 'due-soon window ends after 24 hours');
    check(!isset($inbox[$neutral['id']]) && !isset($inbox[$stale['id']]), 'neutral and stale comments do not request attention');
    $helpItem = $inbox[$help['id']];
    check($helpItem['signals'] === ['needs_help'] && $helpItem['task']['assignee_id'] === null
        && $helpItem['evidence']['id'] === $evidence['id'] && $helpItem['evidence']['body'] === $evidence['body']
        && $helpItem['evidence']['author'] === $evidence['author'], 'unassigned task retains the matching discussion evidence');

    $detail = request('GET', '/api/tasks/' . $help['id'])[1]['data'];
    check(count($detail['comments']) === 2, 'attention item still opens the full task discussion');
    request('POST', '/api/tasks/' . $blocked['id'] . '/comments', ['body' => 'Без доступа не продолжить.'], 2);
    check(in_array('needs_help', attention()[$blocked['id']]['signals'], true), 'new blocker comment appears without editing the task');
    request('POST', '/api/tasks/' . $blocked['id'] . '/comments', ['body' => 'Доступ получен.'], 2);
    $blockedItem = attention()[$blocked['id']];
    check($blockedItem['signals'] === ['blocked'] && $blockedItem['evidence'] === null, 'recovery comment clears evidence while the blocked status remains');
    check(request('PATCH', '/api/tasks/' . $help['id'], ['status' => 'done', 'version' => $detail['version']])[0] === 200
        && !isset(attention()[$help['id']]), 'resolving the task removes it from attention on the next read');

    $membershipTask = $makeTask('Проект с изменённым доступом', ['status' => 'blocked'], 3);
    check(isset(attention()[$membershipTask['id']]), 'leader sees the item while a member');
    $fixtureDb->exec('DELETE FROM project_members WHERE project_id = 3 AND user_id = 1');
    check(!isset(attention()[$membershipTask['id']]), 'ownership without membership does not grant access to attention');

    echo "PASS: $count API checks\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL . file_get_contents($directory . '/server.log'));
    $exitStatus = 1;
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}

exit($exitStatus);
