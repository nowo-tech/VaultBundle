#!/usr/bin/env bash
#
# End-to-end demo of vault encryption key rotation.
#
# Requires: demo running (make up), vault fixtures loaded or --fresh.
#
# Usage (from demo/symfony8):
#   ./scripts/vault-key-rotation-demo.sh
#   ./scripts/vault-key-rotation-demo.sh --fresh
#   ./scripts/vault-key-rotation-demo.sh --update-env
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

COMPOSE="${COMPOSE:-/usr/bin/docker compose}"
SERVICE_PHP="${SERVICE_PHP:-php}"
FRESH=false
UPDATE_ENV=false
PERSIST=true

usage() {
    cat <<'EOF'
Vault key rotation demo (Symfony 8 demo)

Options:
  --fresh        Reset DB, run migrations, reload fixtures before rotation
  --update-env   Write VAULT_NEW_KEY into .env after successful rotation
  --no-persist   Re-encrypt only (do not store new key in vault_settings)
  -h, --help     Show this help

Environment:
  Reads VAULT_OLD_KEY from .env (VAULT_ENCRYPTION_KEY) unless VAULT_OLD_KEY is set.
  Generates VAULT_NEW_KEY automatically.

Examples:
  make up
  ./scripts/vault-key-rotation-demo.sh
  ./scripts/vault-key-rotation-demo.sh --fresh --update-env
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --fresh) FRESH=true ;;
        --update-env) UPDATE_ENV=true ;;
        --no-persist) PERSIST=false ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage; exit 1 ;;
    esac
    shift
done

if ! $COMPOSE exec -T "$SERVICE_PHP" true 2>/dev/null; then
    echo "Demo container is not running. Start it with: make up" >&2
    exit 1
fi

read_env_key() {
    local file="$1"
    local line
    line="$(grep -E '^VAULT_ENCRYPTION_KEY=' "$file" | tail -1 | cut -d= -f2- | tr -d '\r"'"'"'')"
    echo "$line"
}

if [[ -f .env ]]; then
    ENV_FILE=".env"
elif [[ -f .env.example ]]; then
    ENV_FILE=".env.example"
    echo "Warning: using .env.example (create .env with: cp .env.example .env)" >&2
else
    echo "Missing .env / .env.example" >&2
    exit 1
fi

OLD_KEY="${VAULT_OLD_KEY:-$(read_env_key "$ENV_FILE")}"
if [[ -z "$OLD_KEY" ]]; then
    echo "VAULT_ENCRYPTION_KEY is empty in $ENV_FILE" >&2
    exit 1
fi

NEW_KEY="${VAULT_NEW_KEY:-$($COMPOSE exec -T "$SERVICE_PHP" php -r 'echo base64_encode(random_bytes(32));' | tr -d '\r\n')}"
if [[ -z "$NEW_KEY" ]]; then
    echo "Failed to generate VAULT_NEW_KEY" >&2
    exit 1
fi

if [[ "$OLD_KEY" == "$NEW_KEY" ]]; then
    echo "Old and new keys must differ." >&2
    exit 1
fi

run_console() {
    $COMPOSE exec -T "$SERVICE_PHP" php bin/console "$@"
}

echo "=== Vault key rotation demo ==="
echo "Old key (first 12 chars): ${OLD_KEY:0:12}..."
echo "New key (first 12 chars): ${NEW_KEY:0:12}..."
echo

if $FRESH; then
    echo ">> Resetting database and loading fixtures..."
    run_console doctrine:database:drop --force --if-exists
    run_console doctrine:database:create --if-not-exists
    run_console doctrine:migrations:migrate --no-interaction
    run_console doctrine:fixtures:load --no-interaction
else
    ITEM_COUNT="$(run_console app:vault-demo:count --no-ansi 2>/dev/null | tail -1 | tr -d '\r\n' || echo 0)"
    if [[ "${ITEM_COUNT:-0}" == "0" ]]; then
        echo ">> No vault items found — seeding demo logins..."
        run_console app:vault-demo:seed --no-interaction
    else
        echo ">> Found $ITEM_COUNT vault item(s)."
    fi
fi

echo
echo ">> Verifying payloads decrypt with current key..."
run_console app:vault-demo:verify --no-interaction

echo
echo ">> Dry-run re-encryption (no writes)..."
run_console nowo:vault:reencrypt --old-key="$OLD_KEY" --new-key="$NEW_KEY" --dry-run --no-interaction

echo
echo ">> Re-encrypting payloads..."
REENCRYPT_ARGS=(--old-key="$OLD_KEY" --new-key="$NEW_KEY" --force --no-interaction)
if $PERSIST; then
    REENCRYPT_ARGS+=(--persist-new-key)
fi
run_console nowo:vault:reencrypt "${REENCRYPT_ARGS[@]}"

echo
echo ">> Verifying payloads decrypt with effective key after rotation..."
run_console app:vault-demo:verify --no-interaction

run_console cache:clear --no-warmup --quiet || true

echo
echo "=== Rotation demo completed successfully ==="
if $PERSIST; then
    echo "New key stored in vault_settings (runtime config)."
    echo "YAML/env bootstrap still shows the old key until you update .env."
else
    echo "Remember to update VAULT_ENCRYPTION_KEY in .env to the new key."
fi

if $UPDATE_ENV; then
    if [[ "$ENV_FILE" != ".env" ]]; then
        echo "Skipping --update-env: no .env file." >&2
    elif grep -q '^VAULT_ENCRYPTION_KEY=' .env; then
        sed -i "s|^VAULT_ENCRYPTION_KEY=.*|VAULT_ENCRYPTION_KEY=$NEW_KEY|" .env
        echo "Updated .env VAULT_ENCRYPTION_KEY with the new key."
        echo "Restart the demo container if the app cached the previous bootstrap key."
    fi
fi

echo
echo "New key (save securely, demo only):"
echo "$NEW_KEY"
