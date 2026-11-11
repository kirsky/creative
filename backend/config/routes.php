<?php

declare(strict_types=1);

use App\Files\Http\DownloadAction;
use App\Files\Http\UploadAction;
use App\Identity\Http\LoginAction;
use App\Identity\Http\LogoutAction;
use App\Identity\Http\MeAction;
use App\Identity\Http\SetLocaleAction;
use App\Rounds\Http\ApproveAction;
use App\Rounds\Http\RequestRevisionsAction;
use App\Rounds\Http\SubmitRoundAction;
use App\Shared\Http\HealthController;
use App\Shared\Http\PublicRoute;
use App\Tasks\Http\CancelAction;
use App\Tasks\Http\CreateTaskAction;
use App\Tasks\Http\EditTaskAction;
use App\Tasks\Http\GetTaskAction;
use App\Tasks\Http\HistoryAction;
use App\Tasks\Http\ListTasksAction;
use App\Tasks\Http\OptionsAction;
use App\Tasks\Http\PickUpAction;
use App\Tasks\Http\ReleaseAction;
use Slim\App;

/*
 * Every route of the API, in one place. Later modules append their routes here.
 *
 * Routes need a session unless they are wrapped in PublicRoute::mark(); that is the
 * allow-list the authentication middleware reads. Keep it as short as it is today: exactly
 * POST /api/auth/login and GET /api/health. tests/Identity/DefaultDenyTest walks every route
 * registered here and fails if any other one answers without a session. Protected routes with a
 * method other than GET/HEAD/OPTIONS also need the session's X-CSRF-Token header.
 *
 * Handlers are invokable classes (resolved and autowired by the container) or plain closures.
 * Never `static function`: Slim binds closures to the container and fails on static ones.
 */
return static function (App $app): void {
    PublicRoute::mark($app->get('/api/health', HealthController::class));

    PublicRoute::mark($app->post('/api/auth/login', LoginAction::class));
    $app->post('/api/auth/logout', LogoutAction::class);
    $app->get('/api/auth/me', MeAction::class);
    $app->patch('/api/me/locale', SetLocaleAction::class);

    $app->post('/api/files', UploadAction::class);
    $app->get('/api/files/{id:[0-9]+}', DownloadAction::class);

    $app->get('/api/options', OptionsAction::class);
    $app->post('/api/tasks', CreateTaskAction::class);
    $app->get('/api/tasks', ListTasksAction::class);
    $app->get('/api/tasks/{id:[0-9]+}', GetTaskAction::class);
    $app->patch('/api/tasks/{id:[0-9]+}', EditTaskAction::class);
    $app->get('/api/tasks/{id:[0-9]+}/history', HistoryAction::class);
    $app->post('/api/tasks/{id:[0-9]+}/pickup', PickUpAction::class);
    $app->post('/api/tasks/{id:[0-9]+}/release', ReleaseAction::class);
    $app->post('/api/tasks/{id:[0-9]+}/rounds', SubmitRoundAction::class);
    $app->post('/api/tasks/{id:[0-9]+}/approve', ApproveAction::class);
    $app->post('/api/tasks/{id:[0-9]+}/request-revisions', RequestRevisionsAction::class);
    $app->post('/api/tasks/{id:[0-9]+}/cancel', CancelAction::class);
};
