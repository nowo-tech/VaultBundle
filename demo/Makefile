.PHONY: help up-symfony8 down-symfony8 install-symfony8 database-symfony8 database-empty-symfony8 shell-symfony8 test-symfony8 release-check update-bundle-symfony8

help:
	@echo "Vault Bundle — Demos"
	@echo ""
	@echo "Symfony 8.1 (port 8023):"
	@echo "  up-symfony8 down-symfony8 install-symfony8 database-symfony8 database-empty-symfony8 shell-symfony8 test-symfony8"
	@echo ""
	@echo "  release-check          Verify demo health"
	@echo "  update-bundle-symfony8   Sync bundle autoload in demo"

up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony8:
	@$(MAKE) -C symfony8 down

install-symfony8:
	@$(MAKE) -C symfony8 install

database-symfony8:
	@$(MAKE) -C symfony8 database

database-empty-symfony8:
	@$(MAKE) -C symfony8 database-empty

shell-symfony8:
	@$(MAKE) -C symfony8 shell

test-symfony8:
	@$(MAKE) -C symfony8 test

update-bundle-symfony8:
	@$(MAKE) -C symfony8 update-bundle

release-check:
	@$(MAKE) up-symfony8
	@$(MAKE) update-bundle-symfony8
	@curl -sf http://localhost:8023/tools/vault >/dev/null && echo "Symfony 8 demo healthcheck OK" || (echo "Symfony 8 demo healthcheck failed" && exit 1)
