#!/bin/sh
# Entrypoint of the php container: bring the database schema up to date, seed the demo when this is
# the demo and nothing is seeded yet, then hand over to php-fpm (or whatever command was given).
#
# Runs as the unprivileged "app" user. Prints no secrets: the database password is read by PHP from the
# environment and never echoed here.
set -eu

cd /var/www/backend

# Only the service itself prepares the database. `docker compose run php <command>` (the quality
# gates, for instance, with --no-deps and no database) runs its command as it is.
if [ "${1:-}" != "php-fpm" ]; then
    exec docker-php-entrypoint "$@"
fi

if [ ! -f vendor/autoload.php ]; then
    # A fresh checkout has no vendor/ until `make install`. Starting php-fpm anyway keeps that
    # workflow working; run `make install migrate` afterwards (or restart the container).
    echo "entrypoint: vendor/ is missing; skipping migrations and demo seed (run: make install migrate)" >&2
    exec docker-php-entrypoint "$@"
fi

# The compose healthcheck normally has the database ready already; the retries cover a slow start.
attempt=1
until php vendor/bin/phinx migrate -e creative; do
    if [ "$attempt" -ge 30 ]; then
        echo "entrypoint: the migrations did not succeed after $attempt attempts; giving up" >&2
        exit 1
    fi
    attempt=$((attempt + 1))
    echo "entrypoint: waiting for the database (attempt $attempt of 30)" >&2
    sleep 2
done

if [ "${APP_ENV:-}" = "demo" ]; then
    # Does nothing when any task exists, so a restart never replaces what an evaluator is working on.
    # A failed seed leaves the database as it was (see DemoSeeder): serve anyway, loudly.
    php bin/console demo:seed-if-empty || echo "entrypoint: demo:seed-if-empty failed; the demo has no data (run: make demo-reset)" >&2
fi

exec docker-php-entrypoint "$@"
