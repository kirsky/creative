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

# DESTRUCTIVE, never part of `check`: it resets the demo data and gives every account a new
# password (any credentials handed to reviewers stop working). The passwords live only in a shell
# variable and in the environment of the docker CLI (`-e NAME` without a value, so they are not in
# any process's arguments); nothing writes them to a file. After the run the result files are
# searched for them, and the data is reset once more.
e2e: ## DESTRUCTIVE: reset data, rotate all passwords, run Playwright; ARGS="story.spec.js"
	@test -n "$$($(COMPOSE) ps -q nginx)" || { echo "error: the stack is not running, run make up" >&2; exit 1; }
	$(NODE) npm run build
	$(EXEC) php bin/console demo:reset
	@set -u; \
	creds="$$($(EXEC) composer demo:credentials:rotate)" && [ -n "$$creds" ] \
		|| { echo "error: credential rotation failed" >&2; exit 1; }; \
	status=0; \
	E2E_CREDENTIALS="$$creds" $(COMPOSE) run --rm -T -e E2E_CREDENTIALS playwright npm run --silent e2e -- $(ARGS) || status=$$?; \
	if [ -d frontend/e2e/.results ] && printf '%s\n' "$$creds" | cut -f2 | grep -rqFf - frontend/e2e/.results; then \
		echo "error: a password was found under frontend/e2e/.results; that directory was deleted" >&2; \
		rm -rf frontend/e2e/.results; status=1; \
	fi; \
	creds=; \
	$(EXEC) php bin/console demo:reset >/dev/null || status=1; \
	echo; \
	echo "!!! make e2e ROTATED THE PASSWORD OF EVERY ACCOUNT and reset the demo data."; \
	echo "!!! Credentials given to reviewers no longer work, and the new ones were never shown."; \
	echo "!!! Before an evaluation: docker compose exec -T php composer demo:credentials:rotate"; \
	echo "!!! and deliver the new credentials. Never run e2e on a demo that reviewers are using."; \
	exit $$status

demo-reset: ## Replace all task data with the demo scenario (keeps users and sessions)
	$(EXEC) php bin/console demo:reset

db-shell: ## Open a mariadb shell on the creative database as root
	$(COMPOSE) exec db sh -c 'exec mariadb "$$MARIADB_DATABASE"'
