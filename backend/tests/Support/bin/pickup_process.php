<?php

declare(strict_types=1);

/*
 * One contender of PickUpConcurrencyTest, run as a separate OS process: php pickup_process.php
 * <designer id> <task id> <gate file>.
 *
 * It boots the application with a connection of its own to the test database, signs the designer
 * in, prints "ready", then waits for the gate: a shared flock on <gate file>, which the test holds
 * exclusively until every contender is ready. When the test lets go, all contenders send their
 * POST /api/tasks/{id}/pickup at the same instant. The last line printed is the JSON
 * {"status": <HTTP status>, "body": <response body>}.
 */

use App\Identity\SessionCookie;
use App\Identity\SessionService;
use App\Identity\UserRepository;
use Slim\Psr7\Factory\ServerRequestFactory;

use function App\createApp;

require __DIR__ . '/../../../vendor/autoload.php';

// The same guard as tests/bootstrap.php, which cannot be reused: the test process has already set
// DB_NAME to the test database, and the child inherits that.
$testDatabase = getenv('DB_TEST_NAME');
if ($testDatabase === false || !str_ends_with($testDatabase, '_test')) {
    fwrite(STDERR, "DB_TEST_NAME is not set to a *_test database; refusing to run.\n");
    exit(2);
}
putenv('DB_NAME=' . $testDatabase);
putenv('APP_ENV=test');

$arguments = $_SERVER['argv'] ?? [];
if (!is_array($arguments) || count($arguments) !== 4) {
    fwrite(STDERR, "usage: pickup_process.php <designer id> <task id> <gate file>\n");
    exit(2);
}

[, $designerId, $taskId, $gateFile] = array_map(static fn(mixed $argument): string => is_string($argument) ? $argument : '', $arguments);

$app = createApp();
$container = $app->getContainer();
$sessions = $container->get(SessionService::class);
$users = $container->get(UserRepository::class);
if (!$sessions instanceof SessionService || !$users instanceof UserRepository) {
    fwrite(STDERR, "The container does not provide the session service.\n");
    exit(2);
}

$designer = $users->findById((int) $designerId);
if ($designer === null) {
    fwrite(STDERR, "No such designer.\n");
    exit(2);
}

$token = $sessions->start($designer, '172.18.0.1');
$session = $sessions->resolveSession($token);
if ($session === null) {
    fwrite(STDERR, "The new session does not resolve.\n");
    exit(2);
}

$request = (new ServerRequestFactory())
    ->createServerRequest('POST', '/api/tasks/' . (int) $taskId . '/pickup', ['REMOTE_ADDR' => '172.18.0.1'])
    ->withCookieParams([SessionCookie::NAME => $token])
    ->withHeader('Cookie', SessionCookie::NAME . '=' . $token)
    ->withHeader('X-CSRF-Token', $session->csrfToken);

$gate = fopen($gateFile, 'r');
if ($gate === false) {
    fwrite(STDERR, "Cannot open the gate file.\n");
    exit(2);
}

fwrite(STDOUT, "ready\n");
fflush(STDOUT);

// Blocks until the test releases its exclusive lock.
flock($gate, LOCK_SH);

$response = $app->handle($request);

fwrite(STDOUT, json_encode(['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()], JSON_THROW_ON_ERROR) . "\n");
