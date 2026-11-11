.DEFAULT_GOAL := help

COMPOSE := docker compose
# -T: no pseudo-TTY, so targets also work from scripts and CI.
EXEC := $(COMPOSE) exec -T php
NODE := $(COMPOSE) run --rm -T node

.PHONY: help init up down install build migrate php-test js-test check watch e2e demo-reset db-shell

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

build: ## Production build of the frontend into frontend/dist
	$(NODE) npm run build

watch: ## Rebuild frontend/dist on change (webpack --watch, no dev server)
	$(COMPOSE) run --rm node npm run watch

# The command whose stdout is the new credentials. Override it ONLY to test the failure paths of
# `make e2e`, e.g. `make e2e CONFIRM=yes E2E_ROTATE=true` (a rotation that prints nothing).
E2E_ROTATE = $(EXEC) composer demo:credentials:rotate
# The Playwright container, by name, so an interrupted run can stop it (the docker CLI does not
# pass Ctrl-C on to it, and it would go on testing against the data being reset).
E2E_CONTAINER = creative-e2e

# DESTRUCTIVE, never part of `check`: it resets the demo data and gives every account a new
# password (any credentials handed to reviewers stop working). It refuses to start (exit 2, nothing
# touched, no build) unless called as `make e2e CONFIRM=yes`.
# - The passwords live only in a shell variable and in the environment of the docker CLI (`-e NAME`
#   without a value, so they are in no process's arguments; grep reads them from stdin).
# - What keeps them out of the results is the suite itself: no trace, video or HTML report, a
#   password field is emptied before any screenshot and when a sign-in fails. The search of
#   e2e/.results afterwards is a backstop for the plain-text files there (screenshots are images
#   and cannot be searched); it fails closed: a match, or a file it cannot read, fails the run.
# - From the first reset on, an EXIT trap always resets the data again and prints the reminder,
#   also when something fails or the run is interrupted; the exit status stays the failure's.
e2e: ## DESTRUCTIVE, needs CONFIRM=yes: reset data, rotate all passwords, run Playwright; ARGS="story.spec.js"
	@if [ "$(CONFIRM)" != "yes" ]; then \
		echo "make e2e is DESTRUCTIVE: it replaces all demo task data and uploads (demo:reset)," >&2; \
		echo "gives EVERY account a new password that is never shown (credentials handed to reviewers" >&2; \
		echo "stop working) and resets the interface language of every account to English." >&2; \
		echo "Nothing was done. To run it anyway: make e2e CONFIRM=yes [ARGS=story.spec.js]" >&2; \
		exit 2; \
	fi
	@test -n "$$($(COMPOSE) ps -q nginx)" || { echo "error: the stack is not running, run make up" >&2; exit 1; }
	$(NODE) npm run build
	@set -u; \
	creds=; status=0; \
	finish() { \
		code=$$?; creds=; trap - EXIT INT TERM HUP; \
		echo; \
		docker rm -f $(E2E_CONTAINER) >/dev/null 2>&1 || true; \
		if $(EXEC) php bin/console demo:reset >/dev/null; then echo "e2e: the demo data was reset again."; \
		else echo "error: the final demo:reset failed" >&2; code=1; fi; \
		echo "!!! make e2e reset the demo data and may have ROTATED THE PASSWORD OF EVERY ACCOUNT."; \
		echo "!!! Credentials given to reviewers may no longer work; the new ones were never shown."; \
		echo "!!! A run that completes sets every account's interface language back to English."; \
		echo "!!! Before an evaluation: docker compose exec -T php composer demo:credentials:rotate"; \
		echo "!!! and deliver the new credentials. Never run e2e on a demo that reviewers are using."; \
		exit $$code; \
	}; \
	trap finish EXIT; trap 'exit 130' INT; trap 'exit 143' TERM; trap 'exit 129' HUP; \
	docker rm -f $(E2E_CONTAINER) >/dev/null 2>&1 || true; \
	$(EXEC) php bin/console demo:reset || exit 1; \
	creds="$$($(E2E_ROTATE))" || { echo "error: credential rotation failed" >&2; exit 1; }; \
	[ -n "$$creds" ] || { echo "error: credential rotation printed no credentials" >&2; exit 1; }; \
	E2E_CREDENTIALS="$$creds" $(COMPOSE) run --rm -T --name $(E2E_CONTAINER) -e E2E_CREDENTIALS playwright npm run --silent e2e -- $(ARGS) || status=$$?; \
	if [ -d frontend/e2e/.results ]; then \
		leak=0; \
		printf '%s\n' "$$creds" | cut -f2 | grep -rqFf - frontend/e2e/.results || leak=$$?; \
		case $$leak in \
			0) echo "error: a password was found under frontend/e2e/.results; that directory was deleted" >&2; \
				rm -rf frontend/e2e/.results; status=1 ;; \
			1) ;; \
			*) echo "error: could not search frontend/e2e/.results for the passwords (grep exit $$leak)" >&2; \
				status=1 ;; \
		esac; \
	fi; \
	exit $$status

demo-reset: ## Replace all task data with the demo scenario (keeps users and sessions)
	$(EXEC) php bin/console demo:reset

db-shell: ## Open a mariadb shell on the creative database as root
	$(COMPOSE) exec db sh -c 'exec mariadb "$$MARIADB_DATABASE"'
