# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/vault-bundle` (`symfony-bundle`) |
| Audited revision | `v1.4.7` / `0847c41` |
| Audit date | 2026-09-23 |
| Method | Manual review of every service, controller, command, form type, Twig extension, Doctrine listener, repository and DI class under `src/` (entities, DTOs, events and enums skimmed) |
| **Verdict** | ✅ **Viable under scenario B** (after remediation) — no bundle service keeps per-request or DB-derived state across requests; clearing the Doctrine identity map between requests remains the application's responsibility (W-03) |
| Remediation (2026-09-23) | W-01, W-02, W-03 (bundle side), W-04 resolved; W-05 accepted. Regression tests simulate consecutive requests on the same service instances without `reset()` |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ (resolved) | `VaultRuntimeConfigProvider` only keeps the validated YAML baseline (immutable); `VaultAccessGuard::$readOnlyCache` and `VaultRouteLoader::$loaded` removed; `RuntimeKeyVaultPayloadCryptographer` rebuilds its delegate whenever the resolved key changes (checked on every call) |
| Static properties / `static` locals | ✅ | Only pure static helpers (`UserIdResolver`, `VaultLoginDomainMatcher`, `*Integration::isAvailable()`, `VaultRuntimeConfigSchema::filter()`); no static properties |
| `ResetInterface` / `kernel.reset` coverage | ✅ N/A | No bundle service needs a reset any more (state removed instead of reset) |
| Request / user / locale captured in services | ✅ | User, request and token are passed as method arguments; nothing is captured in constructors |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used; config is compiled into container parameters |
| Doctrine / EntityManager | ✅ (resolved, bundle side) | Repositories resolve the manager from `ManagerRegistry` per call and reset it when closed; settings row read with `HINT_REFRESH`. Identity-map clearing between requests stays the application's job |
| Output, headers, `exit`, shutdown functions | ✅ | None; responses are built with HttpFoundation; session is used through `Request::getSession()` |
| Resources (files, sockets, cURL) held open | ✅ | None in the HTTP path (`file_get_contents()` only in the CLI re-encrypt command) |
| Memory growth across requests | ✅ (resolved) | `VaultAccessGuard::$readOnlyCache` removed |
| Blocking I/O and timeouts | ✅ | Only database and cache calls; no HTTP clients, processes or `sleep` |
| Third-party static state | ✅ | libsodium has no global state; FormKit `FormOptionsTrait` restores its bound builder in a `finally` block |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Config\VaultRuntimeConfigProvider` | yes | `?array $validatedYamlBaseline` (YAML only, immutable); DB-merged config read from the shared pool on every call | ✅ | ✅ |
| `Security\RuntimeKeyVaultPayloadCryptographer` | yes (alias of `VaultPayloadCryptographerInterface`) | `$delegate`, `$activeKeyBase64` (rebuilt when the resolved key changes, checked on every call) | ✅ | ✅ |
| `Service\VaultAccessGuard` | yes | none (cache removed) | ✅ | ✅ |
| `Routing\VaultRouteLoader` | yes (`routing.loader`) | none (`$loaded` flag removed) | ✅ | ✅ |
| `Config\VaultRuntimeConfigWriter`, `Security\VaultRuntimeConfigResolver`, `Security\ConfigurableVaultAccessChecker`, `Security\AllowAllVaultAccessChecker`, `Security\NullVaultTeamMembershipResolver` | yes | none (`readonly`); read config through the provider | ✅ | ✅ |
| `BrowserExtension\VaultBrowserExtensionLoginRateLimiter` | yes | none; counters live in the PSR-6 cache pool | ✅ | ✅ |
| `BrowserExtension\VaultBrowserExtensionResponseFactory`, `DefaultVaultBrowserExtensionAuthenticator`, `VaultLoginDomainMatcher` | yes | none | ✅ | ✅ |
| 12 services in `Service/` (`VaultItemLister`, `VaultSharedItemResolver`, `VaultBrowserExtensionLoginResolver`, `VaultBrowserExtensionAuthService`, `VaultItemCreator`, `VaultItemUpdater`, `VaultTagService`, `VaultFolderService`, `VaultGrantService`, `VaultGrantListResolver`, `VaultTrashService`, `VaultPayloadReencryptionService`) plus `VaultPasswordGenerator` | yes | none (`readonly` or no properties) | ✅ | ✅ |
| 6 `Repository\DoctrineOrm*Repository` | yes | none; resolve the manager from `doctrine` (`ManagerRegistry`) per call, reset when closed | ✅ | ✅ (identity map: application responsibility, see W-03) |
| `Doctrine\VaultMetadataListener` (`loadClassMetadata`) | yes | none (`readonly` table names) | ✅ | ✅ |
| `Twig\VaultExtension` (globals) | yes | none; one constant global `nowo_vault_css_framework` | ✅ | ✅ |
| `Form\VaultItemFormType`, `Form\VaultShareType` | yes | FormKit trait fields (merger, profile name, bound builder restored in `finally`) | ✅ | ✅ |
| 3 controllers (`VaultManageController`, `VaultRuntimeConfigController`, `VaultBrowserExtensionController`) | yes (public) | none (`readonly` dependencies) | ✅ | ✅ |
| 2 commands (`nowo:vault:reencrypt`, `nowo:vault:extension-tokens:purge`) | CLI only | none | N/A | N/A |

Entities, DTOs, events, enums and value objects are created per call; none is stored in a service property. Note: `services.yaml` registers the whole `src/` tree (including `Entity/`, `Event/`, `Dto/`) as autowired services; they are never requested and are removed by the container, so this has no runtime effect.

## Findings

### W-01 — Merged runtime config (and encryption key) memoized per worker with no reset (High)

- **Where:** `src/Config/VaultRuntimeConfigProvider.php:25` (`private ?array $resolved`), `:41-56` (`get()` returns the memoized array forever), `:58-62` (`invalidateCache()` clears only the instance it is called on). Consumers: `src/Security/VaultRuntimeConfigResolver.php:27-43` (`resolveEncryptionKeyBase64()`), `src/Security/RuntimeKeyVaultPayloadCryptographer.php:31-40`, `src/Security/ConfigurableVaultAccessChecker.php:86-90`, `src/Controller/VaultManageController.php:725-751`, `src/Controller/VaultRuntimeConfigController.php:130-162`, `src/Form/VaultItemFormType.php:156`.
- **Worker impact:** with `config_storage.enabled: true`, the first request in each worker loads the merged config (YAML + `vault_settings` row) into `$resolved` and never reads it again. The class has no `ResetInterface`, so scenario A does not clear it either. When the config changes, only the worker that ran `VaultRuntimeConfigWriter::update()` / `reset()` (`src/Config/VaultRuntimeConfigWriter.php:57`, `:79`) drops its copy; every other worker thread, and every worker when the change comes from the CLI (`nowo:vault:reencrypt --persist-new-key`, `src/Command/ReencryptVaultPayloadsCommand.php:166-167`), keeps the old values until it restarts. The most serious case is `encryption_key`: after a key is generated in the UI or rotated from the CLI, stale workers keep decrypting with the old key (re-encrypted items fail with `Vault decryption failed.`) and keep **encrypting new and edited items with the retired key**. Those items stop decrypting once the workers restart, which can lose data. Stale `max_attachment_bytes` and `password_field.level` values are a smaller issue. Security roles, routes and templates are YAML-only (`src/Config/VaultRuntimeConfigSchema.php:27-31`), so they are not affected.
- **Recommendation:** do not memoize across requests. Either drop `$resolved` and rely on the shared cache pool (it is already cached through `CacheInterface::get()`), or implement `ResetInterface` (`reset(): $this->resolved = null`) and tag the service with `kernel.reset`. Under scenario B, prefer removing the property, because the pool is the only place that is invalidated for every worker. Until then, restart all workers (`frankenphp` reload / rolling restart) after every encryption key change.
- **Status:** Resolved — `src/Config/VaultRuntimeConfigProvider.php`: `$resolved` removed; with `config_storage.enabled: true` every `get()` goes through the shared `config_storage.cache_pool` (default `cache.app`), and `invalidateCache()` only deletes the pool entry. Only the validated YAML baseline (immutable) is kept. The settings row is re-read with `Query::HINT_REFRESH` on cache miss (`src/Repository/DoctrineOrmVaultSettingsRepository.php`) so a stale identity map cannot resurrect the old key. The pool must be shared by all workers. Tests: `VaultRuntimeConfigProviderTest::testChangeWrittenByAnotherWorkerIsSeenOnNextRequestWithoutReset`, `testCliInvalidationIsSeenByLongLivedProviderWithoutReset`, `testYamlOnlyConfigIsValidatedOnce`.

### W-02 — Item read-only decisions cached across requests and users' lifetimes (High)

- **Where:** `src/Service/VaultAccessGuard.php:32` (`private array $readOnlyCache`), `:67-83` (`isItemReadOnly()` stores the result of the `VaultEvents::ITEM_READ_ONLY_RESOLVE` event per `userId:itemId` and returns it forever). Used by `canAccessItem()` (`:52`) for every non-view action and by `VaultManageController::renderItemForm()` (`src/Controller/VaultManageController.php:258-282`) and `resolveReadOnlyMap()` (`:90-98`).
- **Worker impact:** the key includes the user id, so user X never sees user Y's result. But the decision is an authorization decision and it is never re-evaluated in that worker: if an application listener on `ITEM_READ_ONLY_RESOLVE` later returns `true` for a user (role removed, item locked, policy changed), the worker keeps the cached `false` and still allows edits (`VaultAccessAction::Edit` / `Restore`), and the reverse. `VaultAccessGuard` has no `ResetInterface`, so this happens under scenario A and B. The array also grows with every (user, item) pair that is listed or opened, so memory grows without bound in a long-lived worker. With no listener registered the cached value is always `false` and only the memory growth applies.
- **Recommendation:** make the cache per request: implement `ResetInterface` (`$this->readOnlyCache = []`) and tag `kernel.reset`, or better, keep the map local to `resolveReadOnlyMap()` and do not cache in `isItemReadOnly()`. For scenario B, remove the property.
- **Status:** Resolved — `src/Service/VaultAccessGuard.php`: `$readOnlyCache` removed; `isItemReadOnly()` dispatches `ITEM_READ_ONLY_RESOLVE` on every call and `resolveReadOnlyMap()` builds a local map. Tests: `VaultAccessGuardTest::testReadOnlyDecisionIsReEvaluatedOnNextRequestWithoutReset`, `testReadOnlyDecisionDoesNotLeakBetweenUsersAcrossRequests`, `testUserWithoutIdIsNeverReadOnly`.

### W-03 — Repositories rely on Doctrine's resetter for the EntityManager (Medium, scenario B)

- **Where:** `src/DependencyInjection/VaultExtension.php:114-129` injects `doctrine.orm.<em>_entity_manager` into the six `DoctrineOrm*Repository` classes; every write calls `flush()` directly (for example `src/Repository/DoctrineOrmVaultItemRepository.php:21-31`, `src/Repository/DoctrineOrmVaultSettingsRepository.php:22-26`, `src/Repository/DoctrineOrmVaultExtensionTokenRepository.php:19-29`).
- **Worker impact:** under scenario A, DoctrineBundle's `kernel.reset` hook clears the identity map and replaces a closed EntityManager, so this is fine. Under scenario B, one failed `flush()` (unique violation on a tag or grant, database restart) closes the EntityManager and every later vault request fails with "EntityManager is closed". The identity map also keeps growing and serves stale entities: `find(VaultItem)` / `find(VaultSettings)` return the copy loaded in an earlier request (stale `deletedAt`, ciphertext or encryption key).
- **Recommendation:** keep `services_resetter` enabled (scenario A). If scenario B is required, the application must call `ManagerRegistry::resetManager()` / `EntityManager::clear()` between requests.
- **Status:** Resolved (bundle side) — new `src/Repository/ResolvesEntityManagerTrait.php` used by the six `DoctrineOrm*Repository` classes: the manager is obtained from `ManagerRegistry` (`doctrine`, wired in `src/DependencyInjection/VaultExtension.php` with the configured `database.entity_manager`) on every call and replaced with `resetManager()` when a previous flush closed it, so one failed write no longer breaks every later request of the worker. Constructors keep `$entityManager` first; the new `$registry` / `$managerName` arguments are optional. The freshness-critical read (`findByScope()`, which carries the encryption key) uses `Query::HINT_REFRESH`. Token lookups (`findValidByTokenHash()`) and grant checks already filter in SQL. The bundle does **not** call `clear()` on the application's manager: clearing the identity map between requests (memory growth, stale `find()` results for items/folders) remains the application's responsibility under scenario B. Test: `ResolvesEntityManagerTraitTest`.

### W-04 — Route loader refuses a second load in the same process (Low)

- **Where:** `src/Routing/VaultRouteLoader.php:18`, `:31-35` (`$loaded` flag, throws `Vault routes already loaded.`).
- **Worker impact:** in production the router uses the compiled cache and never calls the loader inside the worker. If the route collection is rebuilt inside a long-lived process (debug mode with a changed routing resource and no worker restart), the second call throws. The demo uses `watch`, which restarts workers on changes, so this is unlikely.
- **Recommendation:** remove the flag, or reset it in `ResetInterface::reset()`.
- **Status:** Resolved — `src/Routing/VaultRouteLoader.php`: `$loaded` flag and the exception removed; the loader is stateless. Test: `VaultRouteLoaderTest::testCanLoadTwiceInTheSameProcess`.

### W-05 — Key material stays in worker memory (Info)

- **Where:** `src/Security/RuntimeKeyVaultPayloadCryptographer.php:12-14`, `src/Security/SodiumVaultPayloadCryptographer.php:27`, `src/Config/VaultRuntimeConfigProvider.php:25`.
- **Worker impact:** the decoded libsodium key and the merged config stay in the worker process for its whole life. This is expected for a server-side vault and is not a cross-user leak, but a worker memory dump contains the key for longer than under PHP-FPM.
- **Recommendation:** none required; mention it in the threat model. Fixing W-01 also shortens the time a stale key is kept.
- **Status:** Accepted — inherent to a server-side vault; after W-01 the delegate is rebuilt as soon as the resolved key changes, so a retired key is no longer used.

No other findings. The browser extension rate limiter, Bearer tokens and CSRF checks all keep their state in the cache pool, the database or the session, not in service properties.

## Usage recommendations in worker mode

- W-01 and W-02 are fixed: runtime config and read-only decisions are re-evaluated on every request. `ITEM_READ_ONLY_RESOLVE` listeners now run on every check, so keep them cheap.
- Keep `services_resetter` / `kernel.reset` enabled when possible so the Doctrine identity map is cleared between requests (W-03). Under scenario B the bundle recovers from a closed EntityManager on its own, but clearing the identity map is the application's job.
- Set FrankenPHP `max_requests` (or `FRANKENPHP_LOOP_MAX`) to recycle workers; this bounds identity-map growth when nothing clears it.
- Use a cache pool shared by all workers (`cache.app` on a shared filesystem, Redis) for `config_storage.cache_pool` and `browser_extension.login_rate_limit.cache_pool`; a per-worker pool (e.g. `cache.adapter.array`) would split rate-limit counters between workers.
- Custom `VaultAccessCheckerInterface`, `VaultTeamMembershipResolverInterface` and `VaultBrowserExtensionAuthenticatorInterface` implementations must be stateless, or implement `ResetInterface`.
- The demo (`demo/symfony8/Caddyfile`) already runs FrankenPHP in worker mode with `watch`.

## Re-audit triggers

Re-run this audit when a change adds or modifies: properties on `VaultRuntimeConfigProvider`, `VaultAccessGuard`, `RuntimeKeyVaultPayloadCryptographer` or any other service; new DB-configurable keys in `VaultRuntimeConfigSchema` (especially security roles); a new event listener or subscriber; a new cache inside a repository; or any use of `$_SERVER` / `$_ENV` at runtime.
