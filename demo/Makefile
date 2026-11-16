.PHONY: help demo-smoke up-symfony8 down-symfony8 install-symfony8 database-symfony8 database-empty-symfony8 shell-symfony8 test-symfony8 test-http-e2e-symfony8 release-check update-bundle-symfony8

help:
	@echo "Vault Bundle — Demos"
	@echo ""
	@echo "Symfony 8.1 (port 8023):"
	@echo "  up-symfony8 down-symfony8 install-symfony8 database-symfony8 database-empty-symfony8 shell-symfony8 test-symfony8"
	@echo ""
	@echo "  demo-smoke             REQ-TEST-011: start symfony8, one HTTP 200 on /tools/vault (redirects followed), stop it"
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

test-http-e2e-symfony8:
	@$(MAKE) -C symfony8 test-http-e2e

update-bundle-symfony8:
	@$(MAKE) -C symfony8 update-bundle

release-check:
	@$(MAKE) up-symfony8
	@$(MAKE) update-bundle-symfony8
	@curl -sf http://localhost:8023/tools/vault >/dev/null && echo "Symfony 8 demo healthcheck OK" || (echo "Symfony 8 demo healthcheck failed" && exit 1)

# REQ-TEST-011: boot the primary demo, expect HTTP 200 after redirects, always stop the stack.
demo-smoke:
	@PORT=$$(grep "^PORT=" symfony8/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" symfony8/.env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=8023; \
	echo "Demo smoke: symfony8 (http://127.0.0.1:$$PORT/tools/vault)"; \
	$(MAKE) -C symfony8 up || { $(MAKE) -C symfony8 down; exit 1; }; \
	code=000; i=0; while [ $$i -lt 60 ]; do \
		code=$$(curl -sL -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$PORT/tools/vault" || true); \
		[ "$$code" = "200" ] && break; i=$$((i+1)); sleep 2; \
	done; \
	$(MAKE) -C symfony8 down || true; \
	if [ "$$code" != "200" ]; then echo "symfony8 failed with HTTP $$code"; exit 1; fi; \
	echo "symfony8 OK (HTTP $$code)"
