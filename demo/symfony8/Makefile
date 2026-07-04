COMPOSE = /usr/bin/docker compose
DOCKER_COMPOSE = $(COMPOSE)
SERVICE_PHP = php

.PHONY: help ensure-env up down build install database database-empty shell test update-bundle fixtures update-deps

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
	@echo "  test           Run demo tests"
	@echo "  update-bundle  Sync bundle autoload and clear cache"

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
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer test || echo "No demo tests yet"

update-bundle:
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer dump-autoload
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console assets:install public --symlink
	@$(COMPOSE) exec -T $(SERVICE_PHP) php bin/console cache:clear --env=dev --no-warmup || true

# REQ-MAKE-008: update-deps (REQ-MAKE-008)
include $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../../../.scripts/Makefile.demo-update-deps.mk)
