COMPOSE = /usr/bin/docker compose
DOCKER_COMPOSE = $(COMPOSE)
SERVICE_PHP = php

.PHONY: help ensure-env up down build install database database-empty shell test test-http-e2e update-bundle fixtures update-deps vault-purge-tokens vault-reencrypt-dry-run vault-reencrypt vault-reencrypt-persist vault-rotation-demo

help:
	@echo "Vault Bundle — Symfony 8.1 Demo"
	@echo ""
	@echo "  up             Start demo (FrankenPHP, port from .env)"
	@echo "  down           Stop containers"
	@echo "  install        Install Composer dependencies"
	@echo "  database       Create DB and run migrations"
	@echo "  database-empty Drop DB, migrate, reload fixtures (wipe shares)"
	@echo "  fixtures       Load demo user (auto-login, no password needed)"
	@echo "  shell          Open shell in PHP container"
	@echo "  test           Run demo PHPUnit tests (kernel E2E)"
	@echo "  test-http-e2e  Run HTTP E2E against running FrankenPHP (requires make up)"
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

build:
	@$(COMPOSE) build

install:
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer install

database:
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer database

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

# REQ-MAKE-008: update-deps (REQ-MAKE-008)
include $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../../../.scripts/Makefile.demo-update-deps.mk)
