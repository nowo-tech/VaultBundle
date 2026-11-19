#!/usr/bin/env bash
# CI helper (since 1.21.0): SearchKit (nowo-tech/search-kit-bundle >= 1.9, PHP >= 8.3) is an optional dependency of the
# SearchKit group `vault_items` (src/Bridge/SearchKit). It is not in composer.json nor in composer.lock: the bundle
# supports PHP 8.2 (composer.json's platform is 8.2.0), and the PHP 8.2 jobs and the dependency-floor job install from
# the lock. On PHP >= 8.3 this sets the platform to the running PHP and requires SearchKit for require-dev (a targeted
# update of SearchKit and what it needs), so those jobs test, analyse and cover the bridge. On PHP 8.2 it does nothing:
# the group is not registered, its tests skip and PHPStan leaves it out (phpstan-search-kit.php).
set -euo pipefail

CONSTRAINT="${SEARCH_KIT_CONSTRAINT:-dev-feat/provider-base-1.9 as 1.9.0}"

if php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'; then
  echo "Requiring nowo-tech/search-kit-bundle ${CONSTRAINT} for require-dev (PHP $(php -r 'echo PHP_VERSION;'))"
  composer config platform.php "$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . ".99";')"
  composer require --dev "nowo-tech/search-kit-bundle:${CONSTRAINT}" --with-all-dependencies --no-interaction --no-progress --prefer-dist
else
  echo "PHP < 8.3: SearchKit is not installed; the SearchKit group vault_items is skipped"
fi
