<?php

declare(strict_types=1);

use App\Shared\Http\HealthController;
use App\Shared\Http\PublicRoute;
use Slim\App;

/*
 * Every route of the API, in one place. Later modules append their routes here.
 *
 * Routes need a session unless they are wrapped in PublicRoute::mark(); that is the
 * allow-list the authentication middleware reads. Keep it as short as it is today.
 *
 * Handlers are invokable classes (resolved and autowired by the container) or plain closures.
 * Never `static function`: Slim binds closures to the container and fails on static ones.
 */
return static function (App $app): void {
    PublicRoute::mark($app->get('/api/health', HealthController::class));
};
