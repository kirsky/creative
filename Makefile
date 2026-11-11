.DEFAULT_GOAL := help

COMPOSE := docker compose
# -T: no pseudo-TTY, so targets also work from scripts and CI.
EXEC := $(COMPOSE) exec -T php
NODE := $(COMPOSE) run --rm -T node

.PHONY: help init up down install migrate php-test js-test check watch e2e demo-reset db-shell

help: ## List the targets
	@grep -E '^[a-z0-9-]+:.*## ' $(MAKEFILE_LIST) | awk -F ':.*## ' '{printf "  %-11s %s\n", $$1, $$2}'

init: ## Write .env from .env.example with freshly generated passwords
	@test ! -e .env || { echo "error: .env already exists, refusing to overwrite it" >&2; exit 1; }
	@umask 077 && sed \
		-e "s|^HOST_UID=.*|HOST_UID=$$(id -u)|" \
		-e "s|^HOST_GID=.*|HOST_GID=$$(id -g)|" \
		-e "s|^DB_PASSWORD=.*|DB_PASSWORD=$$(openssl rand -hex 24)|" \
		-e "s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=$$(openssl rand -hex 24)|" \
		.env.example > .env
	@echo "Wrote .env (generated passwords, mode 600). Next: make up"

up: ## Build and start the stack (php, nginx, db) on 127.0.0.1:3000
	$(COMPOSE) up -d --build --wait --wait-timeout 180

down: ## Stop the stack; ARGS=-v also deletes the database and uploads volumes
	$(COMPOSE) down $(ARGS)

install: ## Install the PHP (composer) and JS (npm ci) dependencies in their containers
	$(EXEC) composer install --no-interaction
	$(NODE) npm ci

migrate: ## Run Phinx migrations on the creative database
	$(EXEC) vendor/bin/phinx migrate -e creative

php-test: ## Run PHPUnit (test database only), e.g. make php-test ARGS="--testsuite Identity"
	$(EXEC) composer test -- $(ARGS)

js-test: ## Run Jest, e.g. make js-test ARGS="client"
	$(NODE) npm test -- $(ARGS)

# Every quality gate lives here; later tasks add theirs.
check: ## Run all quality gates
	$(COMPOSE) config --quiet
	@test "$$(sed -n 's/^ARG NODE_VERSION=//p' docker/node/Dockerfile)" = "$$(cat .nvmrc)" \
		|| { echo "error: docker/node/Dockerfile NODE_VERSION differs from .nvmrc" >&2; exit 1; }
	$(COMPOSE) run --rm -T --no-deps nginx nginx -t
	$(COMPOSE) run --rm -T --no-deps php php -l public/index.php
	$(EXEC) composer check
	$(NODE) npm run check

watch: ## Rebuild frontend/dist on change (webpack --watch, no dev server)
	$(COMPOSE) run --rm node npm run watch

e2e: ## Run the Playwright end-to-end suite
	$(COMPOSE) run --rm playwright npx playwright test $(ARGS)

demo-reset: ## Replace all task data with the demo scenario (keeps users and sessions)
	$(EXEC) php bin/console demo:reset

db-shell: ## Open a mariadb shell on the creative database as root
	$(COMPOSE) exec db sh -c 'exec mariadb "$$MARIADB_DATABASE"'
