SHELL := /bin/bash

# Vault Bundle - Development
.PHONY: help update-deps up down down-dev build shell install test test-coverage test-coverage-100 coverage-check coverage-php-percent cs-check cs-fix qa clean assets assets-build assets-watch assets-test test-ts test-e2e ensure-up rector rector-dry phpstan igor release-check release-check-demos demo-smoke composer-sync update validate validate-translations extension-sync extension-build vault-purge-tokens check-no-cursor-coauthor check-open-prs strip-cursor-coauthor-from-history setup-hooks check-twig-extra check-no-raw-html-form

COMPOSE_FILE ?= docker-compose.yml
# Prefer Compose V2 plugin (GitHub Actions / modern Docker Desktop); fall back to docker-compose V1 (REQ-MAKE-010).
COMPOSE_BIN ?= $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
COMPOSE     ?= $(COMPOSE_BIN) -f $(COMPOSE_FILE)
SERVICE_PHP ?= php

help:
	@echo "Vault Bundle - Development Commands"
	@echo ""
	@echo "Container:"
	@echo "  up                 Build and start the PHP container (composer + pnpm install)"
	@echo "  down               Stop the container"
	@echo "  build              Rebuild the image without cache"
	@echo "  shell              Open a shell in the PHP container"
	@echo "Dependencies:"
	@echo "  install            composer install + pnpm install in the container"
	@echo "Assets:"
	@echo "  assets             Build Vite assets into src/Resources/public"
	@echo "Tests:"
	@echo "  test               PHPUnit (unit + integration + e2e suites)"
	@echo "  test-coverage      PHPUnit with coverage (text + clover + HTML)"
	@echo "Quality:"
	@echo "  cs-check           PHP-CS-Fixer dry-run"
	@echo "  cs-fix             PHP-CS-Fixer apply"
	@echo "  rector             Rector apply"
	@echo "  rector-dry         Rector dry-run"
	@echo "  phpstan            PHPStan (level 8)"
	@echo "  igor               Igor FrankenPHP worker-state audit"
	@echo "  qa                 cs-check + tests"
	@echo "Release:"
	@echo "  release-check      Full pre-release pipeline (REQ-MAKE-002)"
	@echo "  composer-sync      composer validate --strict + install"
	@echo "Cleanup:"
	@echo "  clean              Remove vendor, node_modules, caches and coverage"
	@echo "Composer:"
	@echo "  update             composer update in the bundle container"
	@echo "  update-deps        composer update in the bundle and every demo"
	@echo "  validate           composer validate --strict"
	@echo ""
	@echo "Bundle-specific:"
	@echo "  down-dev           Alias of down"
	@echo "  test-coverage-100  Coverage + fail below 100% lines (alias: coverage-check)"
	@echo "  test-ts            Vitest with coverage (TypeScript)"
	@echo "  test-e2e           PHPUnit e2e suite only"
	@echo "  validate-translations  Parse every translation YAML"
	@echo "  check-twig-extra   Twig Extra requirement check (REQ-TWIG-004)"
	@echo "  check-no-raw-html-form  Fail on raw <form>/<input>/<textarea>/<select> in Twig (REQ-TWIG-005)"
	@echo "  check-no-cursor-coauthor / check-open-prs / setup-hooks"
	@echo "  extension-build / extension-sync / vault-purge-tokens"
	@echo ""
	@echo "Demos:"
	@echo "  demo-smoke / release-check-demos   Demo healthcheck (make -C demo release-check)"
	@echo "  make -C demo up-symfony8 | down-symfony8 | test-symfony8   (see make -C demo help)"

build:
	$(COMPOSE) build --no-cache

up:
	$(COMPOSE) build
	$(COMPOSE) up -d
	@sleep 3
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm install
	@echo "Container ready."

down:
	$(COMPOSE) down

down-dev: down
	@echo "Dev container stopped."

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		$(COMPOSE) up -d; sleep 3; \
		$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction; \
		$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm install; \
	fi

shell:
	$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install
	$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm install

assets: ensure-up
	$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm install
	$(COMPOSE) exec -T $(SERVICE_PHP) pnpm run build
	@echo "Assets built (src/Resources/public/Vault.js)."

assets-build: assets

assets-watch: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) pnpm run watch

test-e2e: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) vendor/bin/phpunit --testsuite e2e

test-ts: ensure-up
	$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm install --no-frozen-lockfile 2>/dev/null || true
	$(COMPOSE) exec -T $(SERVICE_PHP) pnpm run test:coverage | tee coverage-ts.txt
	sh .scripts/ts-coverage-percent.sh coverage-ts.txt
assets-test: test-ts

test: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	$(COMPOSE) exec $(SERVICE_PHP) composer test

test-coverage: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	$(COMPOSE) exec $(SERVICE_PHP) composer test-coverage | tee coverage-php.txt
	sh .scripts/php-coverage-percent.sh coverage-php.txt

test-coverage-100: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	$(COMPOSE) exec $(SERVICE_PHP) composer test-coverage-100 | tee coverage-php.txt
	sh .scripts/php-coverage-percent.sh coverage-php.txt

coverage-check: test-coverage-100

cs-check: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-check

cs-fix: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-fix

rector: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector

rector-dry: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector-dry

phpstan: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer phpstan

validate-translations: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) sh -c 'for f in src/Resources/translations/*.yaml; do php -r "require \"vendor/autoload.php\"; Symfony\\Component\\Yaml\\Yaml::parseFile(\$$argv[1]);" "$$f" || exit 1; done'

qa: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer qa

composer-sync: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction --prefer-dist


check-twig-extra:
	@chmod +x .scripts/check-twig-extra.sh
	@./.scripts/check-twig-extra.sh

# Run Igor worker-state audit (REQ-CS-008)
igor: ensure-up
	$(COMPOSE) exec -T php composer igor
check-no-raw-html-form:
	@chmod +x .scripts/check-no-raw-html-form.sh
	@./.scripts/check-no-raw-html-form.sh

release-check: ensure-up check-no-cursor-coauthor check-open-prs check-twig-extra check-no-raw-html-form composer-sync cs-fix cs-check rector-dry phpstan igor validate-translations coverage-check release-check-demos test-ts

release-check-demos:
	@$(MAKE) -C demo release-check

demo-smoke:
	@if [ -f demo/Makefile ]; then $(MAKE) -C demo release-check; else echo "No demo/Makefile — skip demo-smoke"; fi

clean:
	rm -rf vendor node_modules .phpunit.cache coverage .php-cs-fixer.cache

update: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction

validate: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict

extension-build: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) pnpm run build:extension

extension-sync: extension-build
	sh scripts/sync-browser-extension.sh

vault-purge-tokens: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console nowo:vault:extension-tokens:purge --no-interaction 2>/dev/null || \
		echo "Run from demo: make -C demo/symfony8 vault-purge-tokens"

setup-hooks:
	@chmod +x .githooks/pre-commit 2>/dev/null || true
	@chmod +x .githooks/commit-msg 2>/dev/null || true
	@git config core.hooksPath .githooks
	@echo "✅ Git hooks installed (.githooks — includes commit-msg for REQ-GIT-001)."

# REQ-MAKE-008: update-deps (REQ-MAKE-008)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk
ifeq ($(wildcard $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk),)
# Standalone checkout: composer update in the bundle container, then in each demo (REQ-MAKE-008 contract).
update-deps: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction
	@for d in demo/symfony*/; do [ -f "$$d/Makefile" ] && $(MAKE) -C "$$d" update-deps || true; done
endif

check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD

check-open-prs:
	@chmod +x .scripts/check-open-prs.sh
	@GH_REPO=nowo-tech/VaultBundle ./.scripts/check-open-prs.sh

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main

twig-lint: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer twig:lint || $(COMPOSE) exec -T $(SERVICE_PHP) ./vendor/bin/twig-cs-fixer lint --config=.twig-cs-fixer.php
