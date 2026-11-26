# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/vault-bundle`  
**Last audited**: 2026-11-26 (2.5.0; the 2.4.0 stamp "2026-11-27" was a typo for 2026-11-23)

This file proves that **every production source artifact** under `src/` is referenced by the baseline specification. Test-only files under `tests/` and `*.test.ts` under `src/` are out of Packagist scope. Built assets under `Resources/public/` are documented as Vite/build outputs.

## Bundle & DI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Config/VaultRuntimeConfigProvider.php` | Runtime configuration | FR-CFG-003 |
| `Config/VaultRuntimeConfigSchema.php` | Runtime configuration | FR-CFG-003 |
| `Config/VaultRuntimeConfigWriter.php` | Runtime configuration | FR-CFG-003 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Compiler pass | FR-DI-002 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/VaultExtension.php` | DI extension | FR-CFG-002 |
| `Security/VaultRuntimeConfigResolver.php` | Runtime config resolver | FR-CFG-003 |
| `VaultBundle.php` | Bundle entry | FR-BUNDLE-001 |
| `DependencyInjection/Compiler/BrowserExtensionUserCheckerPass.php` | Points the extension authenticator at the firewall user checker | FR-EXT-002 |
| `DependencyInjection/Compiler/CsrfProtectionPass.php` | Fails closed without CSRF protection when the manage UI is enabled | FR-SEC-003 |

## CLI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/PurgeExtensionTokensCommand.php` | CLI maintenance | FR-CLI-004 |
| `Command/ReencryptVaultPayloadsCommand.php` | CLI maintenance | FR-CLI-004 |

## Controllers

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Controller/VaultCsrfTrait.php` | CSRF trait | FR-SEC-003 |
| `Controller/VaultPagesTrait.php` | Page view-model factory of the controllers | FR-UI-001 |
| `Controller/VaultManageController.php` | Manage UI controller | FR-UI-001 |
| `Controller/VaultRuntimeConfigController.php` | Runtime config controller | FR-CFG-003 |

## Persistence

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Entity/VaultExtensionToken.php` | Persistence model | FR-ENTITY-001 |
| `Entity/VaultFolder.php` | Persistence model | FR-ENTITY-001 |
| `Entity/VaultGrant.php` | Persistence model | FR-ENTITY-001 |
| `Entity/VaultItem.php` | Persistence model | FR-ENTITY-001 |
| `Entity/VaultSettings.php` | Persistence model | FR-ENTITY-001 |
| `Entity/VaultTag.php` | Persistence model | FR-ENTITY-001 |
| `Repository/DoctrineOrmVaultExtensionTokenRepository.php` | Repository implementation | FR-REPO-002 |
| `Repository/DoctrineOrmVaultFolderRepository.php` | Repository implementation | FR-REPO-002 |
| `Repository/DoctrineOrmVaultGrantRepository.php` | Repository implementation | FR-REPO-002 |
| `Repository/DoctrineOrmVaultItemRepository.php` | Repository implementation | FR-REPO-002 |
| `Repository/DoctrineOrmVaultSettingsRepository.php` | Repository implementation | FR-REPO-002 |
| `Repository/DoctrineOrmVaultTagRepository.php` | Repository implementation | FR-REPO-002 |
| `Repository/VaultExtensionTokenRepositoryInterface.php` | Repository contract | FR-REPO-001 |
| `Repository/VaultFolderRepositoryInterface.php` | Repository contract | FR-REPO-001 |
| `Repository/VaultGrantRepositoryInterface.php` | Repository contract | FR-REPO-001 |
| `Repository/VaultItemRepositoryInterface.php` | Repository contract | FR-REPO-001 |
| `Repository/VaultItemFolderCounterInterface.php` | Optional batch folder counter contract | FR-REPO-001 |
| `Repository/VaultItemPaginatorInterface.php` | Optional database pagination contract for the manage lists (REQ-PERF-001) | FR-REPO-001 |
| `Repository/VaultSettingsRepositoryInterface.php` | Repository contract | FR-REPO-001 |
| `Repository/VaultTagRepositoryInterface.php` | Repository contract | FR-REPO-001 |
| `Repository/ResolvesEntityManagerTrait.php` | Per-call EntityManager resolution; reopens a closed EM (worker-safe) | FR-REPO-002, FR-WORKER-001 |

## Forms

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Form/VaultItemFormType.php` | Symfony form type | FR-FORM-001 |
| `Form/VaultShareType.php` | Symfony form type | FR-FORM-001 |
| `Resources/views/Form/vault_item_form_theme.html.twig` | Form theme template | FR-VIEW-009 |

## Domain models

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Dto/PasswordGeneratorOptions.php` | Transfer object | FR-DTO-001 |
| `Dto/VaultBrowserExtensionLoginMatch.php` | Transfer object | FR-DTO-001 |
| `Dto/VaultGranteeChoice.php` | Transfer object | FR-DTO-001 |
| `Dto/VaultItemFormData.php` | Transfer object | FR-DTO-001 |
| `Dto/VaultShareFormData.php` | Transfer object | FR-DTO-001 |
| `Enum/GranteeType.php` | Domain enum | FR-MDL-001 |
| `Enum/VaultItemType.php` | Domain enum | FR-MDL-001 |
| `Enum/VaultPermission.php` | Domain enum | FR-MDL-001 |
| `Enum/VaultResourceType.php` | Domain enum | FR-MDL-001 |

## Application services

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `DependencyInjection/RuntimeConfiguration.php` | Runtime config tree | FR-CFG-004 |
| `Event/VaultAccessAction.php` | Domain events | FR-EVT-001 |
| `Event/VaultBrowserExtensionAuthEvent.php` | Domain events | FR-EVT-001 |
| `Event/VaultEvents.php` | Domain events | FR-EVT-001 |
| `Event/VaultFolderAccessCheckEvent.php` | Domain events | FR-EVT-001 |
| `Event/VaultGrantListQueryEvent.php` | Domain events | FR-EVT-001 |
| `Event/VaultItemAccessCheckEvent.php` | Domain events | FR-EVT-001 |
| `Event/VaultItemListQueryEvent.php` | Domain events | FR-EVT-001 |
| `Event/VaultItemListResultEvent.php` | Domain events | FR-EVT-001 |
| `Event/VaultItemReadOnlyEvent.php` | Domain events | FR-EVT-001 |
| `Resources/assets/src/vault-password-client.ts` | Password generator client | FR-PWD-002 |
| `Resources/assets/src/vault.css` | Vault styles source | FR-UI-011 |
| `Resources/assets/src/vault.ts` | Vault UI entrypoint | FR-UI-010 |
| `Service/VaultBrowserExtensionLoginResolver.php` | Extension login match | FR-EXT-003 |
| `Service/VaultFolderService.php` | Folder CRUD | FR-FOLDER-001 |
| `Service/VaultGrantListResolver.php` | Grant list query | FR-SHARE-002 |
| `Service/VaultGrantService.php` | Grant create/revoke | FR-SHARE-001 |
| `Service/VaultItemCreator.php` | Item create | FR-ITEM-001 |
| `Service/VaultItemLister.php` | Item list/filter | FR-ITEM-003 |
| `Service/VaultItemUpdater.php` | Item update | FR-ITEM-002 |
| `Service/VaultPasswordGenerator.php` | Password generator | FR-PWD-001 |
| `Service/VaultSharedItemResolver.php` | Shared-with-me resolver | FR-SHARE-003 |
| `Service/VaultTagService.php` | Tag assignment | FR-TAG-001 |
| `Service/VaultTrashService.php` | Trash restore/purge | FR-TRASH-001 |
| `Bridge/SearchKit/VaultItemSearchDefinition.php` | SearchKit group `vault_items`, name-only match (1.21) | FR-CAT-001, FR-PORT-001 |
| `Bridge/SearchKit/VaultSearchViewer.php` | Viewer of one search (1.21) | FR-CAT-001, FR-USER-001 |
| `Bridge/SearchKit/VaultSearchViewers.php` | Per-call viewer resolver, fail closed (1.21) | FR-CAT-001, FR-WORKER-001 |
| `Event/VaultSearchScopesEvent.php` | SEARCH_SCOPES: scope vaults one search covers (1.21) | FR-CAT-001, FR-EVT-001 |
| `Controller/VaultWriteUnitTrait.php` | Runs a vault page action in one write unit (1.20) | FR-WORKER-001, FR-UI-001 |
| `Doctrine/VaultWriteUnit.php` | Vault write unit through the transaction runner (1.20) | FR-WORKER-001 |
| `DependencyInjection/Compiler/VaultCommitTrackingPass.php` | Scopes commit tracking of the conflict answer (1.20) | FR-WORKER-001 |
| `EventListener/VaultConflictListener.php` | Lock conflict answered as 409 / CONFLICT codes (1.20) | FR-WORKER-001, FR-UI-001 |
| `Exception/VaultItemChangedException.php` | Item saved by someone else since the edit page opened (1.20) | FR-UI-001 |
| `Internal/DeferredVaultEventDispatcher.php` | Dispatches events after the outermost unit commits (1.20) | FR-EVT-002, FR-WORKER-001 |

## Security

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Security/ConfigurableVaultAccessChecker.php` | Configurable access checker | FR-SEC-001 |
| `Security/NullVaultTeamMembershipResolver.php` | Team membership resolver | FR-SEC-006 |
| `Security/RuntimeKeyVaultPayloadCryptographer.php` | Payload encryption | FR-CRYPT-001 |
| `Security/SodiumVaultPayloadCryptographer.php` | Payload encryption | FR-CRYPT-001 |
| `Security/VaultPayloadCryptographerInterface.php` | Cryptographer contract | FR-CRYPT-001 |
| `Service/VaultAccessGuard.php` | Access guard + events | FR-SEC-001 |
| `Service/VaultPayloadReencryptionResult.php` | Re-encryption result DTO | FR-CRYPT-003 |
| `Service/VaultPayloadReencryptionService.php` | Payload re-encryption | FR-CRYPT-003 |
| `Security/AllowAllVaultAccessChecker.php` | Permissive checker when `security.allow_unauthenticated` (demo/dev) | FR-SEC-001 |

## Browser extension

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `BrowserExtension/DefaultVaultBrowserExtensionAuthenticator.php` | Extension authenticator | FR-EXT-002 |
| `BrowserExtension/VaultBrowserExtensionAuthResult.php` | Extension auth result DTO | FR-EXT-002 |
| `BrowserExtension/VaultBrowserExtensionAuthenticatorInterface.php` | Extension auth contract | FR-EXT-001 |
| `BrowserExtension/VaultBrowserExtensionLoginRateLimiter.php` | Extension rate limiter | FR-EXT-004 |
| `BrowserExtension/VaultBrowserExtensionResponseFactory.php` | Extension JSON factory | FR-EXT-005 |
| `BrowserExtension/VaultLoginDomainMatcher.php` | Login domain matcher | FR-EXT-003 |
| `Controller/VaultBrowserExtensionController.php` | Browser extension API | FR-EXT-001 |
| `Service/VaultBrowserExtensionAuthService.php` | Extension auth service | FR-EXT-002 |

## Routing

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Routing/VaultRouteLoader.php` | Route loader | FR-ROUTE-001 |

## Persistence integration

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Database/DatabaseDriver.php` | Persistence integration | FR-DB-001 |
| `Doctrine/VaultMetadataListener.php` | Persistence integration | FR-DB-001 |
| `Integration/DoctrineEncryptIntegration.php` | Optional bundle integration | FR-PLUG-001 |
| `Integration/PasswordStrengthIntegration.php` | Optional bundle integration | FR-PLUG-001 |
| `Integration/TagInputIntegration.php` | Optional bundle integration | FR-PLUG-001 |

## Support utilities

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Support/UserIdResolver.php` | Support utility | FR-UTIL-001 |
| `ValueObject/Uuid.php` | Support utility | FR-UTIL-001 |
| `Support/VaultItemEditToken.php` | Edit token of a vault item, compared on submit (1.20) | FR-UI-001, FR-SEC-003 |

## Frontend TypeScript

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/assets/src/logger.ts` | Frontend logger: `scriptLoaded()` banner with build label, quiet switch (`data-nowo-console`, `NOWO_CONSOLE`), debug-gated levels (REQ-ASSETS-005) | FR-UI-012, FR-UI-013 |
| `Resources/public/vault.css` | Built frontend asset | FR-BUILD-001 |
| `Resources/public/vault.js` | Built frontend asset | FR-BUILD-001 |

## Symfony config

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/services.yaml` | Service wiring | FR-DI-001 |

## Translations

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/translations/NowoVaultBundle.de.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoVaultBundle.en.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoVaultBundle.es.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoVaultBundle.fr.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoVaultBundle.it.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoVaultBundle.nl.yaml` | i18n messages | FR-I18N-004 |
| `Resources/translations/NowoVaultBundle.pt.yaml` | i18n messages | FR-I18N-004 |

## Twig views

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/views/vault/_password_generator.html.twig` | Password generator body (UiKit dialog) | FR-VIEW-005 |
| `Resources/views/vault/_new_item_dialog.html.twig` | New item dialog (UiKit) | FR-VIEW-005 |
| `Resources/views/vault/home.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/vault/item_form.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/vault/items.html.twig` | Manage UI template; the folder and tag chips share one confirm dialog (`vault-chips-confirm`) | FR-VIEW-005, FR-VIEW-010 |
| `Resources/views/vault/runtime_config.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/vault/share.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/vault/shared.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/vault/trash.html.twig` | Manage UI template | FR-VIEW-005 |

## Twig integration

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Ui/VaultPages.php` | Layout / legacy bridge of the UiKit page contract (REQ-UI-003); constants `TABLE_PARTIAL` (the bundle `table` partial) and `CHIPS_CONFIRM_DIALOG` (id of the one confirm dialog of the folder / tag chips) | FR-VIEW-001, FR-VIEW-010 |
| `Resources/views/ui/_table.html.twig` | UiKit `table` partial of the vault pages: extends the host / kit partial with `confirm_shared` on unless the caller passes it, so row actions with a Confirm (move to trash, purge, revoke access) share ONE confirm dialog per table; each row keeps its own hidden POST form and CSRF token | FR-VIEW-010 |
| `Ui/VaultPageFactory.php` | UiKit view models of the manage pages (REQ-UI-003) | FR-VIEW-005 |

## Scope vaults (1.16)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Controller/VaultScopeController.php` | Scope item list, item and folder creation | FR-SCOPE-001 |
| `Resources/views/vault/scope_items.html.twig` | Scope item list page (UiKit list scaffold) | FR-SCOPE-001 |
| `Repository/VaultScopeFolderRepositoryInterface.php` | Scope-owned folder queries | FR-SCOPE-001 |
| `Repository/VaultScopeItemRepositoryInterface.php` | Scope-owned item queries | FR-SCOPE-001 |
| `Repository/VaultScopeTagRepositoryInterface.php` | Scope tag queries | FR-SCOPE-001 |
| `Service/VaultScopeItemLister.php` | Scope items visible to one member | FR-SCOPE-001 |
| `Security/VaultScopeAccess.php` | Standing of a user in a scope | FR-SCOPE-001 |
| `Security/NullVaultScopeAccessResolver.php` | Default: no scope membership | FR-SCOPE-001 |
| `Security/RequestScopedScopeAccessCache.php` | Per-request membership memo | FR-SCOPE-001 |
| `ValueObject/VaultOwner.php` | Owner of a row: user or scope key | FR-SCOPE-001 |
| `Exception/VaultOwnerException.php` | Invalid owner (scope key, foreign folder/tag) | FR-SCOPE-001 |
| `Exception/VaultAccessDeniedException.php` | Scope feature refused | FR-SCOPE-001 |
| `Command/CheckDowngradeCommand.php` | Dry check before a 1.15 rollback | FR-SCOPE-001 |

## Temporary grants and grant policy (1.16)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/PurgeExpiredGrantsCommand.php` | Purge of expired grants (scheduler) | FR-GRANT-001 |
| `Service/VaultGrantExpiryService.php` | Expired-grant housekeeping, GRANT_EXPIRED | FR-GRANT-001 |
| `Service/VaultGrantPurgeResult.php` | Counts of a purge run | FR-GRANT-001 |
| `Service/VaultGrantResult.php` | Outcome of grantAs() | FR-GRANT-001 |
| `Repository/VaultGrantExpiryRepositoryInterface.php` | Expiry and race-safe grant queries | FR-GRANT-001 |
| `Exception/VaultGrantRefusedException.php` | Refused grant (reason code only) | FR-GRANT-001 |
| `Event/VaultGrantPolicyCheckEvent.php` | GRANT_POLICY_CHECK veto | FR-GRANT-001 |
| `Event/VaultGrantChangeEvent.php` | GRANT_CREATED / UPDATED / REVOKED / EXPIRED (ids) | FR-GRANT-001 |

## Metadata catalogue (1.16)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Service/VaultCatalogue.php` | Metadata-only catalogue of a scope | FR-CAT-001 |
| `Dto/VaultCatalogueEntry.php` | One catalogue row | FR-CAT-001 |
| `Dto/VaultCataloguePage.php` | One catalogue page | FR-CAT-001 |
| `Dto/VaultCatalogueQuery.php` | Catalogue filters and page | FR-CAT-001 |
| `Event/VaultCatalogueAccessCheckEvent.php` | CATALOGUE_ACCESS_CHECK | FR-CAT-001 |
| `Resources/views/vault/catalogue.html.twig` | Catalogue page | FR-CAT-001 |
| `Form/VaultItemSearchType.php` | GET search form of the item lists | FR-CAT-001 |

## Item limits and extension tokens per user (2.5.0)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Event/VaultItemCreateCheckEvent.php` | ITEM_CREATE_CHECK (ids, type, lazy locked count) | FR-LIMIT-001 |
| `Exception/VaultItemCreateDeniedException.php` | Refused creation (reason code) | FR-LIMIT-001 |
| `Service/VaultItemCreateGate.php` | Dispatches the check, counts the owner's items with FOR UPDATE | FR-LIMIT-001 |
| `Support/VaultUserBinding.php` | User value bound to the user columns (reference, never a directory stand-in; audit ZCM-L3) | FR-GDPR-001 |
| `Service/VaultExtensionTokenCommandsInterface.php` | Per-user token list / revoke port | FR-EXT-006 |
| `Service/VaultExtensionTokenCommands.php` | Token port on the private manager | FR-EXT-006 |
| `View/VaultExtensionTokenView.php` | Token read model (id and dates) | FR-EXT-006 |
| `Event/VaultExtensionTokenRevokedEvent.php` | EXTENSION_TOKEN_REVOKED (ids, after the commit) | FR-EXT-006 |

## Reveal, copy and attachments (1.16)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Controller/VaultRevealController.php` | Attachment download (POST + CSRF) | FR-REVEAL-001 |
| `Controller/VaultRevealResponsesTrait.php` | Sensitive headers and refused-reveal mapping | FR-REVEAL-001 |
| `Service/VaultFieldRevealer.php` | One field or attachment leaves the vault | FR-REVEAL-001 |
| `Support/VaultRevealedValue.php` | Decrypted value hidden from dumps | FR-REVEAL-001 |
| `Support/VaultSensitiveResponseHeaders.php` | no-store headers of decrypted responses | FR-REVEAL-001 |
| `Support/VaultAttachmentFilename.php` | Safe attachment file name | FR-REVEAL-001 |
| `EventListener/VaultNoStoreResponseListener.php` | no-store on every bundle route | FR-REVEAL-001 |
| `Security/VaultRevealRateLimiter.php` | reveal.rate_limit per user | FR-REVEAL-001 |
| `Security/VaultSecretAccessRateLimiter.php` | Shared budget of reveal and extension lookups | FR-REVEAL-001 |
| `Exception/VaultRevealRefusedException.php` | Refused reveal (reason, Retry-After) | FR-REVEAL-001 |
| `Exception/VaultDecryptionFailedException.php` | Generic decryption failure | FR-REVEAL-001 |
| `Event/VaultFieldRevealCheckEvent.php` | ITEM_FIELD_REVEAL_CHECK veto | FR-REVEAL-001 |
| `Event/VaultItemRevealedEvent.php` | Decrypted values returned (web / extension) | FR-REVEAL-001 |
| `Event/VaultRevealThrottledEvent.php` | REVEAL_THROTTLED | FR-REVEAL-001 |
| `Resources/assets/src/vault-reveal.ts` | Reveal / copy client (POST, clears the value) | FR-REVEAL-001 |

## Password health (1.16)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Service/VaultHealthReporter.php` | Server-side health report | FR-HEALTH-001 |
| `Service/VaultHealthReportProviderInterface.php` | Framework-free health report port (1.19) | FR-HEALTH-001 |
| `Dto/VaultHealthItemResult.php` | Flags of one login item | FR-HEALTH-001 |
| `Dto/VaultHealthReport.php` | Health of one owner for one viewer | FR-HEALTH-001 |
| `Dto/VaultPasswordPolicy.php` | Password policy of an owner | FR-HEALTH-001 |
| `Security/ConfigVaultPasswordPolicyResolver.php` | Default policy resolver (health.policy) | FR-HEALTH-001 |
| `Security/VaultPasswordPolicyResolverInterface.php` | Policy per owner hook | FR-HEALTH-001 |
| `Security/VaultBreachCheckerInterface.php` | Opt-in breach corpus hook | FR-HEALTH-001 |
| `Validator/VaultPasswordPolicyConstraint.php` | Policy on save (enforceOnSave) | FR-HEALTH-001 |
| `Validator/VaultPasswordPolicyConstraintValidator.php` | Length and character classes | FR-HEALTH-001 |
| `Event/VaultHealthAccessCheckEvent.php` | HEALTH_ACCESS_CHECK | FR-HEALTH-001 |
| `Event/VaultHealthReportGeneratedEvent.php` | HEALTH_REPORT_GENERATED (counts) | FR-HEALTH-001 |
| `Exception/VaultHealthThrottledException.php` | health.min_interval_seconds | FR-HEALTH-001 |
| `Exception/VaultHealthTooLargeException.php` | health.max_items (413) | FR-HEALTH-001 |
| `Resources/views/vault/health.html.twig` | Health report page | FR-HEALTH-001 |

## Personal data and decrypted export (1.16)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Controller/VaultExportController.php` | Decrypted personal export behind re-authentication | FR-GDPR-001 |
| `Service/VaultPersonalExportStreamer.php` | Streams the decrypted personal vault | FR-GDPR-001 |
| `Form/VaultPersonalExportType.php` | Export format + CSRF | FR-GDPR-001 |
| `Resources/views/vault/export.html.twig` | Export page | FR-GDPR-001 |
| `Event/VaultItemsExportedEvent.php` | ITEMS_EXPORTED before the first byte | FR-GDPR-001 |
| `Security/VaultReauthProof.php` | Host re-authentication proof | FR-GDPR-001 |
| `Security/VaultReauthProofValidator.php` | Proof checked on the bundle clock | FR-GDPR-001 |
| `DependencyInjection/Compiler/ExportLockFactoryPass.php` | Fails on an unknown export.lock_factory | FR-GDPR-001 |
| `Service/VaultPersonalDataEraser.php` | GDPR erasure of one user | FR-GDPR-001 |
| `Service/VaultPersonalDataEraserInterface.php` | Erasure port by id (1.19) | FR-GDPR-001 |
| `Service/VaultPersonalDataExporter.php` | GDPR metadata export of one user | FR-GDPR-001 |
| `Service/VaultPersonalDataExporterInterface.php` | Metadata export port by id (1.19) | FR-GDPR-001 |
| `Dto/VaultErasureResult.php` | Rows touched by an erasure | FR-GDPR-001 |
| `Event/VaultPersonalDataErasedEvent.php` | PERSONAL_DATA_ERASED | FR-GDPR-001 |
| `Bridge/UserKit/VaultUserKitEraser.php` | UserKit eraser `vault` (2.5.0) | FR-GDPR-002 |
| `Bridge/UserKit/VaultUserKitExportProvider.php` | UserKit export section `vault` (2.5.0) | FR-GDPR-002 |

## User mapping and accepted user classes (1.17)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Doctrine/VaultUserMapping.php` | relation | id | FR-USER-001 |
| `Doctrine/VaultUserColumnsListener.php` | Normalises the host user of new rows | FR-USER-001 |
| `Doctrine/ExactCollation.php` | Binary NO PAD collation of id columns (1.19.3) | FR-USER-001 |
| `EventListener/VaultUserGateListener.php` | Refuses users of non-accepted classes | FR-USER-001 |
| `Repository/BindsVaultUserTrait.php` | Binds a user to a user column | FR-USER-001 |
| `Support/DoctrineVaultUserReferenceResolver.php` | Default user reference resolver | FR-USER-001 |
| `Support/VaultUserReferenceResolverInterface.php` | User reference port | FR-USER-001 |
| `Support/VaultUserClassPolicy.php` | Accepted classes and usable ids | FR-USER-001 |
| `Support/VaultUserLocator.php` | Loads a host user by id | FR-USER-001 |
| `ValueObject/VaultUserId.php` | User known only by id | FR-USER-001 |

## Framework-free host ports (1.19, REQ-ARCH-001)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Security/VaultAccessPolicyInterface.php` | Access policy port | FR-PORT-001 |
| `Security/VaultScopeAccessProviderInterface.php` | Scope access port | FR-PORT-001 |
| `Security/VaultTeamMembershipProviderInterface.php` | Team membership port | FR-PORT-001 |
| `Security/VaultReauthenticationProviderInterface.php` | Re-authentication port | FR-PORT-001 |
| `Support/VaultUserDirectoryInterface.php` | User directory port | FR-PORT-001 |
| `ValueObject/VaultUserRef.php` | User reference VO | FR-PORT-001 |
| `ValueObject/VaultReauthContext.php` | Re-authentication request VO | FR-PORT-001 |
| `Internal/HiddenValues.php` | Sensitive VO values kept out of properties | FR-PORT-001 |
| `Support/VaultRefUser.php` | Stand-in user for a directory answer | FR-PORT-001 |
| `Support/VaultUserRefResolver.php` | Trusted user for a reference | FR-PORT-001 |
| `Support/VaultUserRefs.php` | Symfony user <-> VaultUserRef | FR-PORT-001 |
| `DependencyInjection/Compiler/VaultPortsPass.php` | Wires the ports behind the gates | FR-PORT-002 |
| `Port/VaultAccessCheckerGate.php` | Access gate (fail closed) | FR-PORT-002 |
| `Port/VaultHealthReporterGate.php` | Health report gate | FR-PORT-002 |
| `Port/VaultReauthenticationGate.php` | Re-authentication gate | FR-PORT-002 |
| `DependencyInjection/Compiler/RemovedPortsPass.php` | Accepts or refuses services still wired to the six ports removed in 2.0 | FR-PORT-003 |
| `Port/VaultScopeAccessGate.php` | Scope access gate (weaker answer wins) | FR-PORT-002 |
| `Port/VaultTeamMembershipGate.php` | Team membership gate (intersection) | FR-PORT-002 |
| `Port/VaultUserLocatorGate.php` | User locator gate | FR-PORT-002 |
| `Event/VaultFolderChangeEvent.php` | FOLDER_CHANGED (ids, after flush) | FR-EVT-002 |
| `Event/VaultItemChangeEvent.php` | ITEM_CHANGED (ids, after flush) | FR-EVT-002 |
| `Event/VaultTagChangeEvent.php` | TAG_CHANGED (ids, after flush) | FR-EVT-002 |

## Private entity manager, XML mapping and conditional mapping (1.18-1.19)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `DependencyInjection/Compiler/PrivateEntityManagerPass.php` | Private EM wiring | FR-DB-004 |
| `Doctrine/VaultEntityManager.php` | Bundle-owned EM, recreated per unit of work | FR-DB-004 |
| `Doctrine/VaultManagerRegistry.php` | Registry routing vault classes to the private EM | FR-DB-004 |
| `Doctrine/PrivateManagerRepositoryFactory.php` | Own repository factory (no worker leak) | FR-DB-004 |
| `Doctrine/PrivateEntityManagerMiddleware.php` | DBAL middleware of the private EM connection | FR-DB-004 |
| `Doctrine/PrivateEntityManagerDriver.php` | Driver wrapper | FR-DB-004 |
| `Doctrine/PrivateEntityManagerConnection.php` | Commit / rollback / savepoint reporting | FR-DB-004 |
| `Doctrine/VaultSavepoint.php` | Savepoint around vault DBAL writes | FR-DB-004 |
| `EventListener/VaultUnitOfWorkSubscriber.php` | Drops the private EM at every boundary | FR-DB-004 |
| `EventListener/VaultRequestBoundaryListener.php` | Clears request memos at every boundary | FR-WORKER-001 |
| `DependencyInjection/Compiler/ConditionalMappingPass.php` | database.conditional_mapping wiring | FR-DB-005 |
| `Doctrine/VaultFeatureMappingDriver.php` | Disabled features become transient | FR-DB-005 |
| `Doctrine/VaultUnmappedTablesSchemaFilter.php` | Hides tables of disabled features | FR-DB-005 |
| `Doctrine/VaultExtensionTokenTable.php` | Raw DBAL access to unmapped tokens | FR-DB-005 |
| `Resources/config/doctrine/VaultExtensionToken.orm.xml` | XML mapping (DDD host) | FR-DB-006 |
| `Resources/config/doctrine/VaultFolder.orm.xml` | XML mapping (DDD host) | FR-DB-006 |
| `Resources/config/doctrine/VaultGrant.orm.xml` | XML mapping (DDD host) | FR-DB-006 |
| `Resources/config/doctrine/VaultItem.orm.xml` | XML mapping (DDD host) | FR-DB-006 |
| `Resources/config/doctrine/VaultSettings.orm.xml` | XML mapping (DDD host) | FR-DB-006 |
| `Resources/config/doctrine/VaultTag.orm.xml` | XML mapping (DDD host) | FR-DB-006 |

## Forms and views (REQ-TWIG-005 / REQ-UI-003)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Form/VaultActionType.php` | CSRF-only row / toolbar action | FR-FORM-001 |
| `Form/VaultFolderCreateType.php` | New folder | FR-FORM-001 |
| `Form/VaultPasswordGeneratorType.php` | Password generator controls | FR-FORM-001 |
| `Form/VaultRuntimeConfigType.php` | Runtime configuration page | FR-FORM-001 |
| `Resources/views/ui/_row_actions.html.twig` | Row actions partial (UiKit) | FR-VIEW-005 |

## Host commands, queries and id access (2.1, DDD-host checklist B1, B7, B8)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Dto/VaultItemCriteria.php` | Item query criteria (owner, filters, sort, page) | FR-QRY-001 |
| `Enum/VaultItemSort.php` | Item query order | FR-QRY-001 |
| `Enum/VaultTrashFilter.php` | Active, trashed or both | FR-QRY-001 |
| `Service/VaultItemQueryInterface.php` | Item read port | FR-QRY-001 |
| `Service/VaultItemQuery.php` | Item read port on the private manager | FR-QRY-001 |
| `Internal/VaultViews.php` | Builds the read models from entities | FR-QRY-001 |
| `View/VaultItemView.php` | Item read model, no payload | FR-QRY-001 |
| `View/VaultItemPage.php` | One page of item views | FR-QRY-001 |
| `View/VaultFolderView.php` | Folder read model | FR-CMD-001 |
| `View/VaultGrantView.php` | Grant read model | FR-CMD-001 |
| `Service/VaultAccessCheckInterface.php` | Access checks by id (port) | FR-ACC-001 |
| `Service/VaultIdAccessChecker.php` | Default access checks by id | FR-ACC-001 |
| `Service/VaultItemCommandsInterface.php` | Item write port | FR-CMD-001 |
| `Service/VaultItemCommands.php` | Default item write port | FR-CMD-001 |
| `Service/VaultFolderCommandsInterface.php` | Folder write port | FR-CMD-001 |
| `Service/VaultFolderCommands.php` | Default folder write port | FR-CMD-001 |
| `Service/VaultGrantCommandsInterface.php` | Sharing write port | FR-CMD-001 |
| `Service/VaultGrantCommands.php` | Default sharing write port | FR-CMD-001 |
| `Service/VaultTagCommandsInterface.php` | Tag write port | FR-CMD-001 |
| `Service/VaultTagCommands.php` | Default tag write port | FR-CMD-001 |
| `Internal/VaultCommandsTrait.php` | Shared steps of the command services | FR-CMD-001 |
| `Internal/VaultRowLock.php` | Row load by exact id with FOR UPDATE | FR-CMD-001 |
| `Internal/VaultTransactionLevelInterface.php` | Transaction nesting level of the vault connection | FR-EVT-005 |
| `Internal/DeferredVaultEventBoundaryListener.php` | Request / message / command boundaries of held notifications | FR-EVT-005 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Bundle & DI | 10 | 10 |
| CLI | 2 | 2 |
| Controllers | 4 | 4 |
| Persistence | 21 | 21 |
| Forms | 3 | 3 |
| Domain models | 9 | 9 |
| Application services | 34 | 34 |
| Security | 9 | 9 |
| Browser extension | 8 | 8 |
| Routing | 1 | 1 |
| Persistence integration | 5 | 5 |
| Support utilities | 3 | 3 |
| Frontend TypeScript | 3 | 3 |
| Symfony config | 1 | 1 |
| Translations | 7 | 7 |
| Twig views | 9 | 9 |
| Twig integration | 3 | 3 |
| Scope vaults (1.16) | 13 | 13 |
| Temporary grants and grant policy (1.16) | 8 | 8 |
| Metadata catalogue (1.16) | 7 | 7 |
| Item limits and extension tokens per user (2.5.0) | 8 | 8 |
| Reveal, copy and attachments (1.16) | 15 | 15 |
| Password health (1.16) | 15 | 15 |
| Personal data and decrypted export (1.16) | 16 | 16 |
| User mapping and accepted user classes (1.17) | 10 | 10 |
| Framework-free host ports (1.19, REQ-ARCH-001) | 22 | 22 |
| Host commands, queries and id access (2.1) | 24 | 24 |
| Private entity manager, XML mapping and conditional mapping (1.18-1.19) | 20 | 20 |
| Forms and views (REQ-TWIG-005 / REQ-UI-003) | 5 | 5 |
| **Total production sources** | **295** | **295** |

Audit: `git ls-files src | grep -v '\.test\.ts$' | wc -l` → 295 (2026-11-26, 2.5.0).
