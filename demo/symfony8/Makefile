# Prefer Compose V2; absolute docker path avoids shadowing by local docker/ when PATH has "." (REQ-MAKE-010).
DOCKER_BIN := $(shell command -v docker 2>/dev/null)
ifeq ($(DOCKER_BIN),)
COMPOSE ?= docker-compose
else
COMPOSE ?= $(shell $(DOCKER_BIN) compose version >/dev/null 2>&1 && echo "$(DOCKER_BIN) compose" || echo "docker-compose")
endif
DOCKER_COMPOSE = $(COMPOSE)
SERVICE_PHP = php

.PHONY: help ensure-env up down restart build install setup database database-empty shell test test-coverage test-http-e2e verify update-bundle fixtures update-deps vault-purge-tokens vault-reencrypt-dry-run vault-reencrypt vault-reencrypt-persist vault-rotation-demo

help:
	@echo "Vault Bundle — Symfony 8.1 Demo"
	@echo ""
	@echo "  up             Start demo (FrankenPHP, port from .env)"
	@echo "  down           Stop containers"
	@echo "  restart        Stop and start the demo again"
	@echo "  build          Rebuild demo images"
	@echo "  ensure-up      Start the demo only when it is not running (and seed demo items)"
	@echo "  install        Install Composer dependencies"
	@echo "  setup          Create DB, run migrations and load fixtures"
	@echo "  database       Create DB and run migrations"
	@echo "  database-empty Drop DB, migrate, reload fixtures (wipe shares)"
	@echo "  fixtures       Load demo user (auto-login, no password needed)"
	@echo "  shell          Open shell in PHP container"
	@echo "  test           Run demo PHPUnit tests (kernel E2E)"
	@echo "  test-coverage  Demo PHPUnit tests with coverage (needs pcov/xdebug in the image)"
	@echo "  verify         HTTP healthcheck of the running demo (expects 200/302 on /tools/vault)"
	@echo "  update-deps    composer update inside the demo"
	@echo "  test-http-e2e  Run HTTP E2E against running FrankenPHP (requires make up)"
	@echo "  test-e2e       Playwright e2e against the demo (REQ-DEMO-013)"
	@echo "  demo-screenshots Recapture README WebP screenshots (docs/images/demo)"
	@echo "  update-bundle  Sync bundle autoload and clear cache"
	@echo "  vault-purge-tokens        Remove expired browser extension Bearer tokens"
	@echo "  vault-reencrypt-dry-run   Verify payloads decrypt with VAULT_OLD_KEY (no writes)"
	@echo "  vault-reencrypt           Re-encrypt payloads (requires VAULT_OLD_KEY, VAULT_NEW_KEY, --force)"
	@echo "  vault-reencrypt-persist   Same as vault-reencrypt and store key in vault_settings"
	@echo "  vault-rotation-demo       Full E2E rotation script (see scripts/vault-key-rotation-demo.sh)"

ensure-env:
	@test -f .env || (cp .env.example .env && echo "Created .env from .env.example")
	@rm -f .env.dev

up: ensure-env
	@if [ ! -f .env ]; then cp .env.example .env; fi
	@rm -f .env.dev
	@mkdir -p var/vault-storage
	@chmod 700 var/vault-storage 2>/dev/null || true
	@$(COMPOSE) up -d
	@echo "Waiting for container to be ready..."
	@sleep 5
	@echo "Installing dependencies..."
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction --no-scripts
	@$(COMPOSE) exec -T $(SERVICE_PHP) rm -rf var/cache/* 2>/dev/null || true
	@rm -f .env.dev
	@$(COMPOSE) exec -T $(SERVICE_PHP) test -f bin/console && $(COMPOSE) exec -T $(SERVICE_PHP) composer run-script post-install-cmd || true
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:database:create --if-not-exists
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:migrations:migrate --no-interaction
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:fixtures:load --no-interaction --append || true
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

down:
	@$(COMPOSE) down

restart:
	@$(MAKE) --no-print-directory down
	@$(MAKE) --no-print-directory up

build:
	@$(COMPOSE) build

install:
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer install

database:
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer database

# Schema + demo fixtures on the running demo (idempotent; fixtures are appended).
setup:
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:database:create --if-not-exists
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:migrations:migrate --no-interaction
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:fixtures:load --no-interaction --append

database-empty:
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:database:drop --force --if-exists
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:database:create --if-not-exists
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:migrations:migrate --no-interaction
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:fixtures:load --no-interaction
	@find var/vault-storage -type f -delete 2>/dev/null || true
	@echo "Database emptied, fixtures reloaded, local ciphertext files removed."

fixtures:
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:fixtures:load --no-interaction --append

shell:
	@$(COMPOSE) exec $(SERVICE_PHP) sh

test: update-bundle
	@$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/phpunit --exclude-group http-e2e --testsuite unit,integration

# The demo image ships no coverage driver by default; without pcov/xdebug the tests still run, without a report.
test-coverage: update-bundle
	@if $(COMPOSE) exec -T $(SERVICE_PHP) php -m | grep -qiE '^(pcov|xdebug)$$'; then \
		$(COMPOSE) exec -T -e XDEBUG_MODE=coverage $(SERVICE_PHP) vendor/bin/phpunit --exclude-group http-e2e --testsuite unit,integration --coverage-text; \
	else \
		echo "No pcov/xdebug in the demo image: running the tests without coverage (bundle coverage: make -C ../.. test-coverage)."; \
		$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/phpunit --exclude-group http-e2e --testsuite unit,integration; \
	fi

# Healthcheck: the manage UI answers 200 (auto-login) or 302 (login redirect) on the configured PORT.
verify:
	@PORT=$$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep -E '^PORT=' .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	CODE=$$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$${PORT:-8023}/tools/vault"); \
	case "$$CODE" in 200|302) echo "Demo healthcheck OK (HTTP $$CODE)";; *) echo "Demo healthcheck failed (HTTP $$CODE)"; exit 1;; esac

test-http-e2e: update-bundle
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:database:drop --force --if-exists
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:database:create --if-not-exists
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:migrations:migrate --no-interaction
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console doctrine:fixtures:load --no-interaction
	@$(COMPOSE) exec -T -e DEMO_HTTP_E2E=1 -e DEMO_HTTP_BASE_URL=http://127.0.0.1 $(SERVICE_PHP) vendor/bin/phpunit --group http-e2e

update-bundle:
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer dump-autoload
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console assets:install public --symlink
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console cache:clear --env=dev --no-warmup || true

vault-purge-tokens:
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console nowo:vault:extension-tokens:purge --no-interaction

vault-reencrypt-dry-run:
	@test -n "$$VAULT_OLD_KEY" || (echo "Set VAULT_OLD_KEY to the previous base64 encryption key." && exit 1)
	@test -n "$$VAULT_NEW_KEY" || (echo "Set VAULT_NEW_KEY to the target base64 encryption key." && exit 1)
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console nowo:vault:reencrypt \
		--old-key="$$VAULT_OLD_KEY" --new-key="$$VAULT_NEW_KEY" --dry-run --no-interaction

vault-reencrypt:
	@test -n "$$VAULT_OLD_KEY" || (echo "Set VAULT_OLD_KEY to the previous base64 encryption key." && exit 1)
	@test -n "$$VAULT_NEW_KEY" || (echo "Set VAULT_NEW_KEY to the target base64 encryption key." && exit 1)
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console nowo:vault:reencrypt \
		--old-key="$$VAULT_OLD_KEY" --new-key="$$VAULT_NEW_KEY" --force --no-interaction

vault-reencrypt-persist:
	@test -n "$$VAULT_OLD_KEY" || (echo "Set VAULT_OLD_KEY to the previous base64 encryption key." && exit 1)
	@test -n "$$VAULT_NEW_KEY" || (echo "Set VAULT_NEW_KEY to the target base64 encryption key." && exit 1)
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console nowo:vault:reencrypt \
		--old-key="$$VAULT_OLD_KEY" --new-key="$$VAULT_NEW_KEY" --persist-new-key --force --no-interaction

vault-rotation-demo:
	@chmod +x scripts/vault-key-rotation-demo.sh
	@./scripts/vault-key-rotation-demo.sh $(ARGS)

# REQ-DEMO-013: Playwright e2e + README screenshots (official Playwright image, host network).
# Separate from the PHPUnit HTTP tests (test-http-e2e / tests/E2E).
PLAYWRIGHT_IMAGE ?= mcr.microsoft.com/playwright:v1.63.0-jammy
E2E_PORT = PORT=$$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep -E '^PORT=' .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); PORT=$${PORT:-8023}

.PHONY: ensure-up test-e2e demo-screenshots

# Start the demo (migrations + fixtures) only when it is not running; make sure demo items exist.
ensure-up: ensure-env
	@if ! $(COMPOSE) ps --status running --services 2>/dev/null | grep -qx $(SERVICE_PHP); then $(MAKE) up; fi
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console app:vault-demo:seed --no-interaction >/dev/null || true

test-e2e: ensure-up
	@mkdir -p e2e/node_modules
	@$(E2E_PORT); \
	docker run --rm --network host \
		-v "$(CURDIR)/e2e:/e2e" -w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm install --no-fund --no-audit && npx playwright test --project=chromium'

# Screenshots start from a clean demo DB (database-empty reloads the fixtures: demo user + fake logins).
demo-screenshots: ensure-up
	@$(MAKE) --no-print-directory database-empty
	@mkdir -p ../../docs/images/demo e2e/node_modules
	@$(E2E_PORT); \
	docker run --rm --network host \
		-v "$(CURDIR)/e2e:/e2e" \
		-v "$(CURDIR)/../../docs/images/demo:/screenshots" \
		-w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		-e SCREENSHOT_DIR=/screenshots \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm install --no-fund --no-audit && npx playwright test --project=screenshots && node scripts/to-webp.mjs /screenshots'
	@echo "Screenshots written under docs/images/demo/ (WebP)"

# REQ-MAKE-008: update-deps (REQ-MAKE-008)
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
DEMO_UPDATE_DEPS_MK := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../../../.scripts/Makefile.demo-update-deps.mk)
-include $(DEMO_UPDATE_DEPS_MK)
ifeq ($(wildcard $(DEMO_UPDATE_DEPS_MK)),)
# Standalone checkout: same contract (start the demo, composer update inside it).
update-deps: ensure-up
	@$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 $(SERVICE_PHP) composer update --no-interaction
endif
