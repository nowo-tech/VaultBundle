# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/vault-bundle`  
**Last audited**: 2026-07-07

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

## CLI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/PurgeExtensionTokensCommand.php` | CLI maintenance | FR-CLI-004 |
| `Command/ReencryptVaultPayloadsCommand.php` | CLI maintenance | FR-CLI-004 |

## Controllers

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Controller/VaultCsrfTrait.php` | CSRF trait | FR-SEC-003 |
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
| `Repository/VaultSettingsRepositoryInterface.php` | Repository contract | FR-REPO-001 |
| `Repository/VaultTagRepositoryInterface.php` | Repository contract | FR-REPO-001 |

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

## Security

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Security/ConfigurableVaultAccessChecker.php` | Configurable access checker | FR-SEC-001 |
| `Security/NullVaultTeamMembershipResolver.php` | Team membership resolver | FR-SEC-006 |
| `Security/RuntimeKeyVaultPayloadCryptographer.php` | Payload encryption | FR-CRYPT-001 |
| `Security/SodiumVaultPayloadCryptographer.php` | Payload encryption | FR-CRYPT-001 |
| `Security/VaultAccessCheckerInterface.php` | Access checker contract | FR-SEC-001 |
| `Security/VaultPayloadCryptographerInterface.php` | Cryptographer contract | FR-CRYPT-001 |
| `Security/VaultTeamMembershipResolverInterface.php` | Team membership contract | FR-SEC-006 |
| `Service/VaultAccessGuard.php` | Access guard + events | FR-SEC-001 |
| `Service/VaultPayloadReencryptionResult.php` | Re-encryption result DTO | FR-CRYPT-003 |
| `Service/VaultPayloadReencryptionService.php` | Payload re-encryption | FR-CRYPT-003 |

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

## Frontend TypeScript

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/assets/src/logger.ts` | Frontend logger | FR-UI-012 |
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
| `Resources/views/layout.html.twig` | Layout template | FR-VIEW-001 |
| `Resources/views/vault/_flashes.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/vault/_grants_table.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/vault/_item_row_actions.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/vault/_page_header.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/vault/_password_generator.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/vault/_share_form_fields.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/vault/home.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/vault/item_form.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/vault/items.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/vault/runtime_config.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/vault/share.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/vault/shared.html.twig` | Manage UI template | FR-VIEW-005 |
| `Resources/views/vault/trash.html.twig` | Manage UI template | FR-VIEW-005 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Bundle & DI | 8 | 8 |
| CLI | 2 | 2 |
| Controllers | 3 | 3 |
| Persistence | 18 | 18 |
| Forms | 3 | 3 |
| Domain models | 9 | 9 |
| Application services | 24 | 24 |
| Security | 10 | 10 |
| Browser extension | 8 | 8 |
| Routing | 1 | 1 |
| Persistence integration | 5 | 5 |
| Support utilities | 2 | 2 |
| Frontend TypeScript | 3 | 3 |
| Symfony config | 1 | 1 |
| Translations | 7 | 7 |
| Twig views | 14 | 14 |
| **Total production sources** | **118** | **118** |

Audit: `find src -type f ! -path '*/assets/dist/*' ! -name '*.test.ts' | wc -l`
