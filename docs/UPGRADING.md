# Upgrading

## Table of contents

- [To 2.6.0](#to-260)
- [To 2.5.1](#to-251)
- [To 2.5.0](#to-250)
- [To 2.4.0](#to-240)
- [To 2.3.0](#to-230)
- [To 2.2.1](#to-221)
- [To 2.2.0](#to-220)
- [To 2.1.1](#to-211)
- [To 2.1.0](#to-210)
- [To 2.0.0](#to-200)
  - [Before you upgrade](#before-you-upgrade)
  - [Private manager only](#private-manager-only)
  - [Ports](#ports)
  - [Events](#events)
  - [Configuration, templates and Twig global](#configuration-templates-and-twig-global)
  - [Removed](#removed)
  - [Kept](#kept)
  - [1.x support](#1x-support)
- [To 1.21.2](#to-1212)
- [To 1.21.1](#to-1211)
- [To 1.21.0](#to-1210)
- [To 1.20.1](#to-1201)
- [To 1.20.0](#to-1200)
  - [Concurrency (REQ-RUNTIME-003)](#concurrency-req-runtime-003)
- [To 1.19.3](#to-1193)
  - [Exact collation of the user id columns (`user_mapping: id`, MySQL / MariaDB)](#exact-collation-of-the-user-id-columns-user_mapping-id-mysql--mariadb)
- [To 1.19.2](#to-1192)
- [To 1.19.1](#to-1191)
- [To 1.19.0](#to-1190)
  - [What changes for you](#what-changes-for-you)
  - [Framework-free ports](#framework-free-ports)
  - [Events](#events-1)
  - [Private entity manager (opt-in)](#private-entity-manager-opt-in)
  - [XML mapping](#xml-mapping)
- [To 1.18.0](#to-1180)
  - [Existing settings and token tables (conditional mapping)](#existing-settings-and-token-tables-conditional-mapping)
- [To 1.17.1](#to-1171)
- [To 1.17.0](#to-1170)
- [To 1.16.0](#to-1160)
  - [Schema](#schema)
  - [BC notes](#bc-notes)
  - [Configuration examples](#configuration-examples)
  - [New options (all off by default)](#new-options-all-off-by-default)
- [To 1.15.2](#to-1152)
- [To 1.15.1](#to-1151)
- [To 1.15.0](#to-1150)
  - [Template contract (REQ-UI-003)](#template-contract-req-ui-003)
  - [Configuration](#configuration)
  - [Security (fail closed)](#security-fail-closed)
- [To 1.14.0](#to-1140)
- [To 1.13.0](#to-1130)
- [To 1.12.0](#to-1120)
- [To 1.11.1](#to-1111)
- [To 1.11.0](#to-1110)
- [To 1.10.0](#to-1100)
- [To 1.9.0](#to-190)
  - [Forms rendered through Form Types (REQ-TWIG-005)](#forms-rendered-through-form-types-req-twig-005)
- [To 1.8.2](#to-182)
- [To 1.8.1](#to-181)
- [To 1.8.0](#to-180)
- [To 1.7.0](#to-170)
- [To 1.6.0](#to-160)
- [To 1.5.1](#to-151)
- [To 1.5.0](#to-150)
- [To 1.4.8](#to-148)
- [From 1.4.6 to 1.4.7](#from-146-to-147)
- [From 1.4.6 to 1.4.7](#from-146-to-147-1)
- [1.3.0 (2026-08-03)](#130-2026-08-03)
  - [Behaviour](#behaviour)
  - [Breaking changes](#breaking-changes)
- [1.2.0 (2026-07-30)](#120-2026-07-30)
  - [Behaviour](#behaviour-1)
- [1.1.5 (2026-07-29)](#115-2026-07-29)
- [1.1.4 (2026-07-22)](#114-2026-07-22)
- [1.1.3 (2026-07-16)](#113-2026-07-16)
- [1.1.2 (2026-07-13)](#112-2026-07-13)
- [1.1.1 (2026-07-08)](#111-2026-07-08)
- [1.1.0 (2026-07-05)](#110-2026-07-05)
  - [Summary](#summary)
  - [Upgrade steps](#upgrade-steps)
  - [CSRF on manage UI (breaking for custom integrations)](#csrf-on-manage-ui-breaking-for-custom-integrations)
  - [Browser extension (optional)](#browser-extension-optional)
  - [Runtime config (optional)](#runtime-config-optional)
  - [Encryption key rotation](#encryption-key-rotation)
  - [Template overrides](#template-overrides)
  - [Suggested packages](#suggested-packages)
- [1.0.0 (2026-07-04)](#100-2026-07-04)
  - [Upgrade steps](#upgrade-steps-1)
  - [Symfony 8 + Doctrine](#symfony-8--doctrine)
  - [Access control events](#access-control-events)
  - [FormKitBundle (admin forms)](#formkitbundle-admin-forms)
- [Unreleased](#unreleased)
- [To 1.4.6](#to-146)
- [To 1.4.5](#to-145)
- [To 1.4.4](#to-144)
- [To 1.4.3](#to-143)
- [To 1.4.2](#to-142)
- [To 1.4.1](#to-141)
- [To 1.4.0](#to-140)
  - [Twig Extra Bundle (REQ-TWIG-004)](#twig-extra-bundle-req-twig-004)
  - [Twig-CS-Fixer (maintainers)](#twig-cs-fixer-maintainers)

## To 2.6.0

From **2.5.1**:

```bash
composer update nowo-tech/vault-bundle
```

**Password generator dialog.** `vault/_password_generator.html.twig` is now the fragment the `password_generate` route answers for a
remote dialog (root element `data-vault-password-modal` carrying the URL and the CSRF token, footer in `[data-nowo-ui-dialog-actions]`),
no longer a `<dialog>`; `vault/home.html.twig` includes UiKit's `remote_form_dialog` shell instead. A host that overrides either template
takes the new shape; a host that maps `password_generate` to a route of its own must allow `GET` there. Rebuild `vault.js` if you build your own copy.

## To 2.5.1

From **2.5.0**:

```bash
composer update nowo-tech/vault-bundle
```

## To 2.5.0

From **2.4.0**:

```bash
composer update nowo-tech/vault-bundle
```

From **2.4.0** (no BC break):

```bash
composer update nowo-tech/vault-bundle
```

- **UserKit hosts:** the vault now joins UserKit's GDPR erasure and export (eraser and section `vault`). If you
  already registered your own UserKit adapters for the vault, set `nowo_vault.personal_data.user_kit: false` (or remove
  your adapters) **before** upgrading: an export provider of yours with the section key `vault` makes every UserKit
  export fail with UserKit's `LogicException` "Duplicate personal data export section" (another key exports the vault
  twice); your eraser would run a second, idempotent erasure. UserKit's
  `personal_data.enabled` must be on. Keep the vault's manager on the same connection as your user manager so that the
  erasure joins UserKit's transaction (see [CONCURRENCY](CONCURRENCY.md#residual-risks)).
- **Your own erasure inside a transaction:** `VaultPersonalDataEraser::erase()` called while a transaction is open on
  the vault's connection now writes inside it, without savepoints. A failure leaves your transaction to roll back (on
  PostgreSQL it is aborted); do not commit after a failed erasure.
- **GDPR export:** two new sections (`grants_on_personal`, `scope_folders_created`); consumers of the array that list
  its keys should accept them.
- **Item limits:** nothing changes until you listen to `ITEM_CREATE_CHECK`. Host code calling
  `VaultItemCommandsInterface::create()` / `createInScope()` may then receive `VaultItemCreateDeniedException`.
- **Templates:** an overridden `vault/catalogue.html.twig` receives `activeOwner` and `viewerId`; an overridden
  `vault/item_form.html.twig` should render `form_errors(form)` to show a refused creation.

## To 2.4.0

From **2.3.0**:

```bash
composer update nowo-tech/vault-bundle
```

## To 2.3.0

From **2.2.1**:

```bash
composer update nowo-tech/vault-bundle
```

```bash
composer update nowo-tech/vault-bundle nowo-tech/ui-kit-bundle
```

- **Lazy dialogs: one confirm dialog per table.** The vault lists print ONE shared confirm dialog per table (UiKit
  2.68 `confirm_shared`, id `nowo-ui-table-confirm`) and one for the folder / tag chips (`vault-chips-confirm`)
  instead of one `<dialog>` per row. `nowo-tech/ui-kit-bundle` must be `^2.68`. Check host tests and overrides that
  rely on the per-row markup:
  - a row toggle's `data-nowo-confirm-target` is the shared id, not `confirmId()`; the row's POST form (target + CSRF)
    sits hidden next to its toggle (`data-nowo-confirm-form` names it) instead of inside a per-row dialog;
  - a host override of the UiKit `action`, `row_actions` or `menu` partials (global or for `NowoVaultBundle`) must
    handle and forward `confirm_shared` (UiKit 2.68), or the toggles open nothing;
  - the bundle now prepends `nowo_ui_kit.theme.bundles.NowoVaultBundle.partials.table:
    '@NowoVaultBundle/ui/_table.html.twig'` (it extends your global `table` partial). To keep the per-row dialogs,
    set that key to your own partial or to `'@NowoUiKitBundle/partials/_table.html.twig'`.

## To 2.2.1

From **2.2.0**:

```bash
composer update nowo-tech/vault-bundle
```

## To 2.2.0

From **2.1.1**:

```bash
composer update nowo-tech/vault-bundle
```

## To 2.1.1

From **2.1.0**:

```bash
composer update nowo-tech/vault-bundle
```

## To 2.1.0

From **2.0.0**:

```bash
composer update nowo-tech/vault-bundle
```

From **2.0.0** (target 2.1.0, minor):

```bash
composer update nowo-tech/vault-bundle
```

Nothing to change: no schema, template or signature change, and no default behaviour change (one new opt-in option).

- **New id-based ports** for a DDD / hexagonal host: `VaultItemCommandsInterface`, `VaultFolderCommandsInterface`,
  `VaultGrantCommandsInterface`, `VaultTagCommandsInterface`, `VaultItemQueryInterface` and
  `VaultAccessCheckInterface` (see [USAGE](USAGE.md#host-ports-by-id-210)). Move host code that builds or reads vault
  entities (`VaultItem::forScope()`, `getRepository(VaultItem::class)`, DQL over the vault tables) to them; seed and
  sync commands are the typical case.
- **Deprecated for host code** (removal from the public API in 3.0): the entity / `UserInterface` signatures of
  `VaultItemCreator`, `VaultItemUpdater::update()`, `VaultFolderService`, `VaultTagService::syncItemTags()` /
  `deleteForCreator()`, `VaultTrashService`, `VaultGrantService::grantAs()` / `revokeAs()` and the `VaultAccessGuard`
  checks. No runtime deprecation is triggered.
- **Opt-in: events after your commit** (doctrine/dbal 4): with `events.after_host_commit: true`, when your code opens
  a transaction on the vault's connection and calls the vault inside it, the change events (`ITEM_CHANGED`,
  `FOLDER_CHANGED`, `TAG_CHANGED`, `GRANT_*`) arrive after your commit (at the next vault change outside a transaction
  or at the end of the request, message or command) and never when you roll back. A listener that relies on running
  inside that transaction must then open its own. The default stays `false` (2.0 timing) in 2.x and **becomes `true` in
  3.0**: turn it on now to be ready.

  ```yaml
  nowo_vault:
      events:
          after_host_commit: true
  ```

## To 2.0.0

From **1.21.2**:

```bash
composer update nowo-tech/vault-bundle
```

From **1.21.x** (major):

```bash
composer require nowo-tech/vault-bundle:^2.0 -W
```

2.0 contains only changes that 1.x announced through deprecation notices: an application that runs the last 1.x
minor without deprecation notices from this bundle upgrades without code changes. After 2.0.0 is released, the 1.x
line receives security fixes for 6 months: fixes rated Moderate and above are backported to the last 1.x minor;
Low ones are fixed in 2.x only.

No schema change, no asset change. **Security is unchanged:** encryption, key rotation (`nowo:vault:reencrypt`),
re-authentication proofs, reveal limits, the decrypted-export lock, the browser-extension tokens and every access
decision (ownership, grants, scopes, read-only, `deny_status`) are those of 1.21. The removed paths below either had
the same answers (the framework-free ports, tested against the 1.21.2 answers) or fail the container build.

### Before you upgrade

On 1.21, clear every deprecation notice of this bundle. They are raised when the container is built (they show in
`bin/console debug:container --deprecations`, not in the request log), except the layout notice, which is raised when
a vault page renders:

- `Not setting "nowo_vault.database.private_entity_manager" is deprecated: it keeps the 1.18 behaviour (the vault
  flushes the "…" entity manager on its own writes, including your pending changes) and becomes true in 2.0. Set it to
  true (recommended, see UPGRADING.md "To 1.19.0").` and `"nowo_vault.database.private_entity_manager: false" is
  deprecated: the private entity manager becomes the only mode in 2.0.`: set `private_entity_manager: true` on 1.21,
  run your tests, then follow [Private manager only](#private-manager-only) below;
- `The vault service "…" (…) behind … implements only the deprecated "…"; implement "…" instead (the deprecated
  interface is removed in 2.0).`, `The vault service "…" behind … is decorated by "…", which implements only the
  deprecated "…" …`, `The vault port … is decided by both "…" and the deprecated "…" (the weaker answer wins) …` and
  `The "…" service alias is deprecated, use "…" instead (removed in 2.0).`: move the service, the decorator or the
  injection to the framework-free interface ([Ports](#ports));
- `The "nowo_vault.route_prefix" option is deprecated, use "nowo_vault.web_ui.path_prefix" instead.`, `The
  "nowo_vault.templates" options are deprecated, use "nowo_vault.web_ui.layout_template" and
  "nowo_vault.web_ui.templates.<page>" instead.` and `The "nowo_vault.css_framework" option is deprecated, set
  "nowo_ui_kit.css_framework" instead (REQ-UI-003).`: see [Configuration, templates and Twig global](#configuration-templates-and-twig-global);
- `The layout "…" does not render the UiKit canonical blocks (nowo_ui_page_header, nowo_ui_content, ...); it is wrapped
  in a deprecated bridge. …` (raised at render time): render the canonical blocks, extend
  `@NowoUiKit/layout/admin.html.twig`, or set `nowo_vault.web_ui.layout_template: null`.

The 1.16 members (`getCreator()`, `VaultGrantService::grant()` / `revoke()`) and the 1.19 event and result members
(`UserInterface` getters) carry `@deprecated` without a runtime notice: search your code for them
([Removed](#removed)).

### Private manager only

The vault always reads and writes its rows through its own private entity manager on the connection, mapping and
event manager of `database.entity_manager` (REQ-ARCH-001 rule 6; the opt-in of 1.19, recommended since then).

| `database.private_entity_manager` | 1.21 | 2.0 |
| --- | --- | --- |
| unset | the 1.18 wiring (the vault flushes your manager), deprecation | the private manager |
| `true` | the private manager | the private manager; the option is deprecated (no effect, removed in 3.0) |
| `false` | the 1.18 wiring, deprecation | **the container build fails** |

There is no way to keep the 1.18 wiring. **Your pending changes are no longer flushed by the vault**: code that
changed its own entities and relied on a vault write (create, edit, share, trash, erase…) to flush them must flush
them itself; set `private_entity_manager: true` on 1.21 first and run your tests. Check these code patterns:

- **Vault entities from your own manager.** An item, folder, tag or grant loaded through your entity manager (your
  repository, `$em->find(VaultItem::class, …)`, an association of one of your entities) and given to a vault service
  (`VaultItemUpdater`, `VaultTrashService`, `VaultGrantService`, …) fails with Doctrine's *entity is not managed* /
  *new entity found through the relationship*. Load vault rows through the vault repositories
  (`VaultItemRepositoryInterface::findById()`, …) or by id.
- **Changing a vault entity and flushing your manager** persists nothing: write through the vault services or
  repositories. Changes still pending in the private manager at the end of a request, message or command are dropped
  and logged as a warning (entity classes and counts).
- **Your entities pointing at vault entities** (a `ManyToOne` / `OneToOne` to `VaultItem`, `VaultFolder`, …) must take
  `$yourEntityManager->getReference(VaultItem::class, $id)`, never the instance a vault service returned (it belongs to
  the private manager: your flush would fail with *new entity found through the relationship*). Storing the id is
  simpler.
- **Your users (relation mapping).** The vault stores a `user_class` instance of your manager as a reference of the
  same id in the private manager. `getOwner()->getUser()`, `getCreatedBy()` and the browser-extension Bearer user are
  objects of the private manager: compare users by id, and never persist or associate them through your own manager
  (re-load them by id).
- **A failed vault write no longer resets your manager** (1.18 reset it to recover): it closes only the private
  manager, which comes back on the next call.
- **Doctrine listeners** on the vault entities receive the private manager (`$args->getObjectManager()`).
- **Forms:** an `EntityType` of vault entities in your own forms must use the vault's manager or plain ids.
- In FrankenPHP worker mode (kernel reset on or off) the private manager is dropped at every boundary: main request,
  terminate, Messenger message, console command and `kernel.reset`.

### Ports

The six host ports that took a Symfony `UserInterface` or `Request` were deprecated in 1.19 and are removed; implement
their framework-free twins (same configuration keys):

| Removed (deprecated since 1.19.0) | Implement instead |
|---|---|
| `Security\VaultAccessCheckerInterface::canAccess/canCreate/canList/canRevoke(?UserInterface)` | `Security\VaultAccessPolicyInterface::allowsAccess/allowsCreate/allowsList/allowsRevoke(?VaultUserRef)` |
| `Security\VaultTeamMembershipResolverInterface::getTeamIdsForUser(UserInterface)` | `Security\VaultTeamMembershipProviderInterface::teamIdsOf(VaultUserRef)` |
| `Security\VaultScopeAccessResolverInterface::resolve(UserInterface, string)` | `Security\VaultScopeAccessProviderInterface::scopeAccessOf(VaultUserRef, string)` |
| `Security\VaultReauthenticationCheckerInterface::getProof(UserInterface, Request, string)`, `consume()`, `getReauthenticationUrl(Request, …)` | `Security\VaultReauthenticationProviderInterface::proofFor(VaultReauthContext)`, `consumeProof()`, `reauthenticationUrl(VaultReauthContext, string)` |
| `Support\VaultUserLocatorInterface::find(string): ?UserInterface` | `Support\VaultUserDirectoryInterface::findUser(string): ?VaultUserRef` |
| `Service\VaultHealthReporterInterface::forScope/forPersonalVault(UserInterface, …)` | `Service\VaultHealthReportProviderInterface::reportForScope/reportForPersonalVault(VaultUserRef, …)` |

`$ref->user()` is your security user inside an adapter (see "To 1.19.0", [Framework-free ports](#framework-free-ports)).
What fails the container build, with a message naming the service and the interface to implement:

- a service configured behind a port (`security.access_checker`, `team_membership_resolver`, `scopes.access_resolver`,
  `export.reauth_checker`) or aliased to a port interface whose class does not implement it;
- a class that still implements a removed interface (it cannot be loaded), whether named by the configuration or
  registered by resource discovery (`#[AsAlias]`, `#[AsDecorator]`). A class that implemented both
  interfaces must drop the removed one;
- an alias of a removed interface id (e.g. your `Nowo\VaultBundle\Support\VaultUserLocatorInterface: '@App\…'`
  alias) whose class does **not** implement the twin, or whose class cannot be known before compilation (a factory).
  In 1.x that alias picked your lookup; it is never ignored silently;
- a service **registered under** a removed interface id (e.g. `Nowo\VaultBundle\Security\VaultAccessCheckerInterface:
  { class: App\VaultAccess }`). 1.21 replaced that definition with its own alias of the id, so it never served the
  port: the built-in did. Using it now would change who gets access, so the build fails instead: alias the twin to it
  if you want it to serve the port, or remove it;
- a decorator of a removed interface id;
- a decorator of a port (of the interface or of the service behind it) whose class does not implement the interface.

**Your alias of a removed id, when the class already implements the twin** (the 1.17 way to replace the user lookup,
kept by many 1.19 hosts whose class moved to the twin): it keeps working until 3.0. The vault aliases the twin to that
service and raises a deprecation when the container is built (`bin/console debug:container --deprecations`):

> The service id "Nowo\VaultBundle\Support\VaultUserLocatorInterface" was removed in 2.0; the vault uses
> "App\Directory" (which implements "Nowo\VaultBundle\Support\VaultUserDirectoryInterface") through that interface.
> Alias "Nowo\VaultBundle\Support\VaultUserDirectoryInterface" instead (the old id is accepted until 3.0).

Rename the alias to the twin to clear it. This applies to aliases only: a service registered under a removed id fails
the build (see above). When you alias the twin as well, both must name the same service: two different services fail
the build (keep only the alias of the twin).

**Decorators.** The 1.19 bridge that asked a decorator of a deprecated access port *together with* the 1.19 interface
("both are asked, the weaker answer wins") is removed: decorate the framework-free interface instead (for example
`#[AsDecorator(VaultAccessPolicyInterface::class)]`). A decorator that only narrows access keeps narrowing it; there is
no path where a removed decorator is skipped and access widens (the build fails instead).

**Built-ins.** `ConfigurableVaultAccessChecker`, `AllowAllVaultAccessChecker`, `NullVaultTeamMembershipResolver`,
`NullVaultScopeAccessResolver`, `VaultUserLocator` and `VaultHealthReporter` implement only the framework-free
interfaces: their removed-interface methods (`canAccess()`, `getTeamIdsForUser()`, `resolve()`, `find()`,
`forScope()`, `forPersonalVault()`) are gone. Inject the port interfaces, not the built-in classes.

**Services you build yourself.** Constructor arguments of vault services that were typed with a removed interface
(`VaultAccessGuard`, `VaultSharedItemResolver`, `VaultGrantService`, `VaultBrowserExtensionAuthService`,
`VaultPersonalDataExporter`, `VaultPersonalDataEraser`, `RequestScopedScopeAccessCache`, the controllers) now take the
bundle's internal gate of that port (`Port\Vault…Gate`, service `nowo_vault.port.<name>`), which wraps your port
implementation. Get these services from the container instead of building them.

### Events

The `UserInterface` getters of the hook events (deprecated since 1.19.0) are removed; the entity getters stay. Read
the framework-free accessors (the reference's `user()` is the user object the vault checked):

| Event | Removed | Use |
|---|---|---|
| `VaultItemAccessCheckEvent`, `VaultFolderAccessCheckEvent`, `VaultItemReadOnlyEvent`, `VaultFieldRevealCheckEvent`, `VaultCatalogueAccessCheckEvent`, `VaultHealthAccessCheckEvent` | `getUser()` | `getUserRef()` / `getUserId()` |
| `VaultItemListQueryEvent` | `getViewer()`, `getListSubject()`, `setListSubject(UserInterface)` | `getViewerRef()` / `getViewerId()`, `getListSubjectRef()` / `getListSubjectId()`, `setListSubjectRef(VaultUserRef)` |
| `VaultItemListResultEvent` | `getViewer()` | `getViewerRef()` / `getViewerId()` |
| `VaultGrantPolicyCheckEvent` | `getActor()` | `getActorRef()` / `getActorId()` |
| `VaultGrantListQueryEvent` | `getGrantedBy()` | `getGrantedByRef()` / `getGrantedById()` |
| `BrowserExtension\VaultBrowserExtensionAuthResult` | `success(UserInterface)`, `getUser()` | `authenticated(VaultUserRef)` (`new VaultUserRef($id, $identifier, $roles, $user)` keeps your user object), `getUserRef()` |

### Configuration, templates and Twig global

The keys deprecated since 1.15 fail the container build with the key to set instead:

| Removed (deprecated since 1.15.0) | Replacement |
| --- | --- |
| `nowo_vault.route_prefix` | `nowo_vault.web_ui.path_prefix` |
| `nowo_vault.templates.layout` | `nowo_vault.web_ui.layout_template` (null = `nowo_ui_kit.layouts.admin`) |
| `nowo_vault.templates.<page>` | `nowo_vault.web_ui.templates.<page>` (`index` → `home`) |
| `nowo_vault.css_framework` | `nowo_ui_kit.css_framework` / `icon_set` (no longer copied there by the vault) |
| Twig global `nowo_vault_css_framework` (class `Twig\VaultExtension`) | `nowo_ui_kit.css_framework` |
| Container parameter `nowo_vault.css_framework` | `nowo_ui_kit.css_framework` |
| `vault/_legacy_layout.html.twig` (the bridge around a 1.x layout) and the page variables `nowo_vault_legacy_layout` / `nowo_vault_legacy_blocks` | a layout that renders the UiKit canonical blocks |

**Layouts.** `web_ui.layout_template: null` (the default) renders the pages in `nowo_ui_kit.layouts.admin`, as before.
A configured layout must render the UiKit canonical blocks (`nowo_ui_page_header`, `nowo_ui_content`, …; see
[TEMPLATES.md](TEMPLATES.md)). A 1.x layout that renders `{% block body %}` instead is no longer wrapped: the page
fails with a `LogicException` that says what to do (it never renders an empty page).

### Removed

| Removed | Deprecated since | Replacement |
| --- | --- | --- |
| `database.private_entity_manager: false` (the 1.18 wiring on `database.entity_manager`) | 1.19.0 | none: the private manager ([Private manager only](#private-manager-only)) |
| The six ports and their service aliases ([Ports](#ports)) | 1.19.0 | their framework-free twins |
| Your alias of a removed port id | 1.19.0 | alias the twin; with a class that implements the twin the old alias still works with a deprecation until 3.0 ([Ports](#ports)) |
| A service registered under a removed port id (never used by 1.21: the built-in served the port) | 1.19.0 | the build fails: alias the twin to it to use it, or remove it ([Ports](#ports)) |
| Container parameter `nowo_vault.private_entity_manager` | 1.19.0 | none (always the private manager) |
| The event and result user members ([Events](#events)) | 1.19.0 | the `…Ref()` / `…Id()` accessors |
| `VaultItem::getCreator()`, `VaultFolder::getCreator()`, `VaultTag::getCreator()` | 1.16.0 | `getOwner()` (`getUser()`, `getScope()`, `key()`), `getCreatorId()`, or `getCreatedBy()` (audit creator) |
| `VaultGrantService::grant()` / `revoke()` | 1.16.0 | `grantAs($actor, …, ?$expiresAt)` / `revokeAs($actor, $grant)` (they re-check that the actor may Share) |
| `route_prefix`, `templates.*`, `css_framework`, the Twig global and the layout bridge ([Configuration](#configuration-templates-and-twig-global)) | 1.15.0 | the `web_ui.*` keys, `nowo_ui_kit.*` |

### Kept

- `database.private_entity_manager: true` is accepted (no effect, deprecated since 2.0, removed in 3.0); remove it.
- Your alias of a removed port id whose class implements the twin is accepted (deprecated since 2.0, removed in 3.0);
  alias the twin instead.
- `database.entity_manager` keeps naming the source manager of the private one (connection, mapping, listeners, SQL
  filters).
- The id rules: byte-for-byte user ids in `user_mapping: id` (1.19.3) and the cross-class owner checks (1.17) are
  unchanged.

### 1.x support

After 2.0.0 is released, the 1.x line receives security fixes for 6 months: fixes rated Moderate and above are
backported to the last 1.x minor; Low ones are fixed in 2.x only. See [SECURITY.md](SECURITY.md).

## To 1.21.2

From **1.21.1**:

```bash
composer update nowo-tech/vault-bundle
```

## To 1.21.1

From **1.21.0**:

```bash
composer update nowo-tech/vault-bundle
```

## To 1.21.0

From **1.20.1**:

```bash
composer update nowo-tech/vault-bundle
```

- **SearchKit group `vault_items` (opt-in).** Nothing changes unless you install nowo-tech/search-kit-bundle 1.9 or later
  (PHP 8.3 or later; the vault still supports PHP 8.2 without it) **and** set `nowo_vault.search_kit.enabled: true`. The
  group lists item **names** only and is sensitive: SearchKit shows it only while its recents are revalidated or off.
  With scope vaults, add a `VaultEvents::SEARCH_SCOPES` listener that names the viewer's scopes; without one, scope items
  are not searched (see USAGE "Header search (SearchKit)").
- **Migration for `user_mapping: relation` on MySQL / MariaDB.** `vault_grants.grantee_id` (user and team ids) now gets
  the same binary NO PAD collation as with the id mapping, so team look-alikes never match in SQL. Generate the
  migration and run it:

  ```bash
  php bin/console doctrine:migrations:diff
  php bin/console doctrine:migrations:migrate
  ```

  or alter the column yourself (replace `vault_grants` if you use another `table_prefix`):

  ```sql
  -- MySQL 8.0.17 or later
  ALTER TABLE vault_grants MODIFY grantee_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL;
  -- MariaDB
  ALTER TABLE vault_grants MODIFY grantee_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL;
  ```

  If two grants on the same resource differ only by case, accents or trailing spaces of the grantee id, the unique
  index `vault_grants_unique` no longer treats them as one; nothing else changes. PostgreSQL and SQLite need nothing.
  Set `doctrine.dbal.server_version` if the database is not reachable at cache warmup; otherwise the column keeps its
  old collation and a warning is logged.

## To 1.20.1

From **1.20.0**:

```bash
composer update nowo-tech/vault-bundle
```

```bash
composer update nowo-tech/vault-bundle nowo-tech/transaction-doctrine
```

- **nowo-tech/transaction-doctrine `^1.7.1` is required** (was `^1.6`). Nothing to configure. Read its UPGRADING
  ("To 1.7.0", "To 1.7.1"): no signature changed.
- **Commit tracking.** With `conflict_response.commit_tracking: bundle` (the default) the vault's middleware now also
  wraps the connection of any other entity manager that maps the vault's entities (audit UE3-CF-1). The vault reuses
  transaction-doctrine's ledger only when its `nowo_transaction_doctrine.commit_tracking_middleware` service exists
  (its bundle with `commit_detection` on); a `CommitLedger` service registered by anything else no longer replaces the
  vault's own tracking (audit UE3-CF-2). Nothing to do; a test asserting `CONFLICT_001` after a commit in one of
  these setups needs `CONFLICT_002`.
- **Conflict detection.** A write unit's `ConcurrencyConflict` (or a lock conflict inside Messenger's wrapped
  exceptions) reaching a vault route answers 409 + `CONFLICT_*` instead of a 500.

## To 1.20.0

From **1.19.3**:

```bash
composer update nowo-tech/vault-bundle
```

### Concurrency (REQ-RUNTIME-003)

```bash
composer update nowo-tech/vault-bundle --with-dependencies
```

- **New dependencies**: `nowo-tech/transaction-contract` `^1.0` and `nowo-tech/transaction-doctrine` `^1.6` (installed by Composer; registering transaction-doctrine's bundle is optional).
- **No schema change.**
- **Lock conflicts answer 409 instead of 500** on the vault's routes, with `X-Conflict-Code: CONFLICT_001` (+ `Retry-After`) or `CONFLICT_002`. To keep a 503 for lock conflicts, set:

  ```yaml
  nowo_vault:
      conflict_response:
          status: 503
  ```

  Unset, the vault follows transaction-doctrine's `conflict_response` when its bundle is registered, else 409 with `Retry-After: 1`. JSON answers keep their `error` key and gain `code`. See [CONCURRENCY.md](CONCURRENCY.md).
- **Stale item edits answer 409.** The item edit page now carries a hidden `editToken`. A save from a page opened before another save of the same item is refused with 409 and `X-Error-Code: VAULT_ITEM_CHANGED`, and the current item is shown again. A host that renders its own item edit form keeps working: without the field the save is not refused (it still locks the row and keeps the stored secrets of the current row). Add the field (it is rendered with the other children of the form) to get the check.
- **1.18 mode only (`database.private_entity_manager` unset or false):** the item create, item edit and folder trash pages now run in one transaction on your entity manager through transaction-doctrine's `forHostEntityManager()`. It **throws LogicException** (a 500) when your manager holds changes you did not flush when the vault page action starts, instead of flushing them as part of the vault's write. Flush your changes before a vault page runs (for example in your own listeners), or switch to the private entity manager (`database.private_entity_manager: true`, recommended), which never touches your manager.
- **Change events after the commit.** `ITEM_CHANGED`, `FOLDER_CHANGED`, `TAG_CHANGED` and `GRANT_*` dispatched while one of these page actions runs now reach your listeners after its commit (and not at all when it fails). A listener that read the vault rows inside the vault's transaction now reads them committed.
- **CI (contributors):** the required checks "Id mapping on MySQL 8.4 / MariaDB 11.4 / PostgreSQL 17" are replaced by "Databases on MySQL 8.4 / MariaDB 11.4 / PostgreSQL 16 - DBAL 3 / 4", and "Dependency floor (nowo-tech/transaction-* lowest, demo lock)" is new.

## To 1.19.3

From **1.19.2**:

```bash
composer update nowo-tech/vault-bundle
```

Target **1.19.3** (patch), from **1.19.2**:

```bash
composer update nowo-tech/vault-bundle
```

Nothing changes with the default `user_mapping: relation`, nor on PostgreSQL, SQLite or SQL Server.

### Exact collation of the user id columns (`user_mapping: id`, MySQL / MariaDB)

1.17.0–1.19.2 gave the user id columns `utf8mb4_bin`. That collation is case- and accent-sensitive but PAD SPACE: `usera`
and `usera ` compare equal. Since 1.19.3 the columns get a binary NO PAD collation: `utf8mb4_0900_bin` on MySQL
8.0.17+, `utf8mb4_nopad_bin` on MariaDB. MySQL 5.7, or a `doctrine.dbal.server_version` without its patch number
(`8`, `8.0`), keeps `utf8mb4_bin`, and the bundle logs a warning when it builds its metadata. Configure the full version
(for example `8.4.3`).

The columns are `creator_id` and `created_by_id` (items, folders), `creator_id` (tags), `created_by_id` and `grantee_id`
(grants), and `user_id` (browser extension tokens). The vault already refused padded, control and invisible ids
(`VaultUserClassPolicy`) and re-checked every row with `===`. The ALTER is defence in depth and makes list pages exact in
SQL. It is not required for safety.

**1. Detection (before the ALTER).** The NO PAD collation only splits values that compare equal today, so it cannot make
`vault_grants_unique` or `vault_tags_creator_name_unique` fail. Look first at the values that will stop matching each
other. Compare the bytes: `TRIM`, `DISTINCT`, `LOWER` or a `*_ci` / `*_ai_*` collation fold them too. Run each query
for every column above.

```sql
-- look-alike ids that compare equal today (usera / UserA / úsera / "usera ")
SELECT creator_id, COUNT(DISTINCT HEX(creator_id)) AS variants, COUNT(*) AS items_count
FROM vault_items WHERE creator_id IS NOT NULL
GROUP BY creator_id HAVING COUNT(DISTINCT HEX(creator_id)) > 1;

-- ids the vault does not accept (spaces, controls, NBSP, zero-width, bidi marks, BOM): stored before the strict id
-- rules of 1.17.0, unreachable since then
SELECT id, HEX(creator_id) FROM vault_items
WHERE HEX(creator_id) REGEXP '^(..)*([01][0-9A-F]|20|7F|C2A0|C2AD|E2808[0-9A-F]|E280A[89A-F]|E281A[0-9A-F]|E38080|EFBBBF)';
```

With `utf8mb4_bin` the first query only finds trailing-space variants. After the ALTER each variant is its own id; the
vault never served one id's rows to another, because of the `===` re-checks.

**2. ALTER** (use your table names, and `utf8mb4_nopad_bin` instead of `utf8mb4_0900_bin` on MariaDB). MySQL 8.0.17+:

```sql
ALTER TABLE vault_items
  MODIFY creator_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_bin` DEFAULT NULL,
  MODIFY created_by_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_bin` DEFAULT NULL;
ALTER TABLE vault_folders
  MODIFY creator_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_bin` DEFAULT NULL,
  MODIFY created_by_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_bin` DEFAULT NULL;
ALTER TABLE vault_tags
  MODIFY creator_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_bin` DEFAULT NULL;
ALTER TABLE vault_grants
  MODIFY created_by_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_bin` DEFAULT NULL,
  MODIFY grantee_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_bin` NOT NULL;
ALTER TABLE vault_extension_tokens
  MODIFY user_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_bin` NOT NULL;
```

Your `doctrine:migrations:diff` generates the same statements. Afterwards `doctrine:schema:update --dump-sql` shows
nothing for these columns. `information_schema.COLUMNS.COLLATION_NAME` shows the new collation.

## To 1.19.2

From **1.19.1**:

```bash
composer update nowo-tech/vault-bundle
```

- The bundle's private entity manager has its own repository factory. A `repository_factory` you configured on the
  Doctrine entity manager is used by it only for service repositories (repositories implementing
  `ServiceEntityRepositoryInterface`); every other repository of the private manager is built by the bundle, from the
  mapped `repositoryClass` or your `default_repository_class`.

## To 1.19.1

From **1.19.0** (patch):

```bash
composer update nowo-tech/vault-bundle
```

No configuration change, no schema change, no asset change. `VaultUnitOfWorkSubscriber` now tracks console runs as
frames (audit NM), so a listener that throws or stops the propagation no longer leaves the unit-of-work tracking
behind in a long-running process. Nothing to do on your side.

## To 1.19.0

From **1.18.x** (minor):

```bash
composer update nowo-tech/vault-bundle
```

No schema change, no asset change.

### What changes for you

**Access decisions do not change.** With an unchanged configuration every grant, sharing, ownership, scope, reveal and
`deny_status` decision is the one of 1.18.0, in the relation and id mappings (tested against literal 1.18.0 copies and
through the same HTTP requests for every host shape). The vault still writes through `database.entity_manager` (it
still flushes that manager) until you set `database.private_entity_manager: true`.

What you will notice without changing anything:

- **Deprecations when the container is built** (one per item; nothing breaks):
  - `database.private_entity_manager` is not set: set it (`true` recommended, see below);
  - a service you configured (`security.access_checker`, `team_membership_resolver`, `scopes.access_resolver`,
    `export.reauth_checker`) or aliased (`VaultUserLocatorInterface`) implements only the deprecated interface:
    implement the 1.19 twin;
  - your code injects one of the deprecated interfaces while the bundle serves it (e.g. `VaultHealthReporterInterface`,
    `VaultUserLocatorInterface`): inject the 1.19 twin;
  - a decorator of a deprecated access interface: both interfaces are now asked (see below).
- **A configured service that implements neither interface fails the build** (1.18 failed with a `TypeError` on the
  first request).
- **Writes inside your own transaction.** The vault's bulk and DBAL writes (grant expiry, revoke and update, token
  purge, the raw token table) run in a savepoint when your code opened a transaction on the connection, so a failing
  vault statement no longer aborts a PostgreSQL transaction.
- **Relation mapping:** a `user_class` instance managed by another entity manager than the vault's is stored as a
  reference of the same id (1.18 failed with *new entity found through the relationship*).
- New events `FOLDER_CHANGED` and `TAG_CHANGED` are dispatched (ids only).
- **Users looked up by id match byte for byte.** The user locator gate keeps a lookup only when the user found has
  exactly the requested id: a non-canonical id (`"05"` for user `5`, an upper-case UUID for a lower-case one) now finds
  nobody (no extension token resolved, no scope grantee check, no GDPR user object) where a loose 1.18 lookup
  (e.g. Doctrine casting `"05"` to `5`) found one. Store and pass canonical ids.
- If you instantiate `VaultFolderService`, `VaultTagService` or `VaultItemFormType` yourself: they take new optional
  constructor arguments at the end.

### Framework-free ports

Each port that took a Symfony `UserInterface` or `Request` has a 1.19 twin taking a `VaultUserRef` (or a
`VaultReauthContext`); the old interfaces are **deprecated** and removed in 2.0.

| Deprecated | 1.19 twin |
|---|---|
| `Security\VaultAccessCheckerInterface::canAccess/canCreate/canList/canRevoke(?UserInterface)` | `Security\VaultAccessPolicyInterface::allowsAccess/allowsCreate/allowsList/allowsRevoke(?VaultUserRef)` |
| `Security\VaultTeamMembershipResolverInterface::getTeamIdsForUser(UserInterface)` | `Security\VaultTeamMembershipProviderInterface::teamIdsOf(VaultUserRef)` |
| `Security\VaultScopeAccessResolverInterface::resolve(UserInterface, string)` | `Security\VaultScopeAccessProviderInterface::scopeAccessOf(VaultUserRef, string)` |
| `Security\VaultReauthenticationCheckerInterface::getProof(UserInterface, Request, string)`, `consume()`, `getReauthenticationUrl(Request, …)` | `Security\VaultReauthenticationProviderInterface::proofFor(VaultReauthContext)`, `consumeProof()`, `reauthenticationUrl(VaultReauthContext, string)` |
| `Support\VaultUserLocatorInterface::find(string): ?UserInterface` | `Support\VaultUserDirectoryInterface::findUser(string): ?VaultUserRef` |
| `Service\VaultHealthReporterInterface::forScope/forPersonalVault(UserInterface, …)` | `Service\VaultHealthReportProviderInterface::reportForScope/reportForPersonalVault(VaultUserRef, …)` |

`VaultUserRef` carries the user id (the string form of `getId()`, never trimmed or normalised; null when the user has
none), the identifier and the role names the user object reports; `user()` returns the user object the bundle would
have passed to the deprecated interface (never dumped, cast or serialized). `VaultReauthContext` carries the purpose,
the user, the method and the route; `path()` and `sessionBinding()` (`hash('sha256', session id)`, the value your
proof's `sessionBinding` must hold) are hidden the same way. Use them in your adapter; ask Symfony's own services
(`Security`, `RequestStack`) when you need more.

How the bundle picks the interface (same config keys as before):

| Your 1.18 setup | 1.19 behaviour |
|---|---|
| Built-ins (no custom service) | Same answers, through the 1.19 interfaces. |
| Service implementing only the deprecated interface | Asked exactly as in 1.18; deprecation at build time. |
| Service implementing both | Asked through the 1.19 interface only. Implement both while code of yours still injects the deprecated one: the deprecated alias exists only for a class that implements it. |
| Service implementing only the 1.19 interface | Asked through it; the deprecated alias is removed (code of yours that injects it fails to build). |
| Service implementing neither | **The build fails.** |
| Service whose class is unknown at build time (factory) | Asked as in 1.18, no deprecation. |
| You alias the 1.19 interface yourself | Your service wins over the built-in; with a different configured (non built-in) service the build fails (keep one). |
| A decorator of the deprecated access checker, team membership or scope access interface | It keeps running. When the 1.19 interface is served too, **both** are asked and the weaker answer wins: both must allow; only the team ids both return; not a member when either says so, the lower baseline, each flag only when both set it. Deprecation naming the decorator. |
| A decorator of the deprecated user locator, re-authentication or health interface | It keeps running and that interface stays the one asked. Together with another service behind the 1.19 interface the build fails. |
| A decorator of the service id itself (e.g. `nowo_vault.access_checker.default`, `VaultUserLocator`, your configured id) | When its class lacks the 1.19 interface, the deprecated interface is asked, as in 1.18 (deprecation naming it). A decorator lacking the 1.19 interface on a service you aliased to the 1.19 interface fails the build. |

A `VaultUserRef` built by your code with an id and no user object (`VaultUserRef::ofId($id)`) is only a pointer: where
the vault accepts one (`VaultHealthReportProviderInterface`, `VaultBrowserExtensionAuthResult::authenticated()`,
`VaultItemListQueryEvent::setListSubjectRef()`) it looks the id up again through the user locator port (your
`VaultUserDirectoryInterface` or the deprecated locator) and uses that answer; the identifier and role names of the
reference are ignored, and an unknown id is refused (no token, access denied). A reference carrying a user object uses
that object, under the same class rules as in 1.18 (`user_class` / `accepted_user_classes`). The built-in role checker
denies a reference without a user object. Your directory must answer only for exactly the requested id and return null
for disabled, locked or foreign users: the vault's stand-in for its answer is not seen by a Symfony `UserChecker`.

**Both interfaces asked can deny more than 1.18.** A decorator of a deprecated access port that *widened* access in
1.18 (granted more, added teams, raised a scope standing) no longer widens it once your service also implements the
1.19 interface: the weaker of the two answers wins. Move such a decorator to the 1.19 interface.

### Events

The hook events keep their `UserInterface` and entity getters (the user getters are deprecated, removed in 2.0) and
gain framework-free accessors: `getUserRef()` / `getUserId()` (or `getViewerRef()`, `getActorRef()`,
`getGrantedByRef()`, `getListSubjectRef()` and their `…Id()`), `getItemId()`, `getFolderId()`, `getOwnerKey()`,
`VaultItemListResultEvent::getItemIds()`, `VaultItemListQueryEvent::setListSubjectRef()`. New `FOLDER_CHANGED` and
`TAG_CHANGED` carry ids only. `VaultBrowserExtensionAuthResult::success(UserInterface)` / `getUser()` are deprecated:
use `authenticated(VaultUserRef)` / `getUserRef()`.

### Private entity manager (opt-in)

```yaml
nowo_vault:
    database:
        entity_manager: default          # unchanged: the source manager (connection, mapping, event manager)
        private_entity_manager: true     # recommended; the only mode in 2.0
```

Unset (or `false`) keeps the 1.18 behaviour with a deprecation: the vault flushes `database.entity_manager` on its own
writes, which also commits **every change your code left pending in that manager**, and resets it after a failed
vault write. With `true` the vault reads and writes its rows through a private manager on the same connection: your
pending changes are never flushed by the vault and a failed vault write never closes your manager. **Check these code
patterns before turning it on:**

- **Vault entities from your own manager.** An item, folder, tag or grant loaded through your entity manager (your
  repository, `$em->find(VaultItem::class, …)`, an association of one of your entities) and given to a vault service
  (`VaultItemUpdater`, `VaultTrashService`, `VaultGrantService`, …) fails with Doctrine's *entity is not managed* /
  *new entity found through the relationship*. Load vault rows through the vault repositories
  (`VaultItemRepositoryInterface::findById()`, …) or by id.
- **Changing a vault entity and flushing your manager** persists nothing: write through the vault services or
  repositories. Changes still pending in the private manager at the end of a request, message or command are dropped
  and logged as a warning (entity classes and counts).
- **Your entities never reference vault entities** loaded by the vault (reference them by id).
- **Doctrine listeners** on the vault entities receive the private manager (`$args->getObjectManager()`). A listener
  that caches per entity manager sees one manager per unit of work.
- **Forms:** an `EntityType` of vault entities in your own forms must use the same manager as the choices
  (`'em' => <the vault's manager>` or plain ids).
- With the relation mapping, `getCreator()` / `getCreatedBy()` (and the browser-extension Bearer user, loaded from the
  token row) are objects of the private manager, not of yours: compare users by id, as the vault does, and never
  persist or associate them through your own manager (Doctrine refuses them as new entities found through a
  relationship); re-load them by id with your manager instead. Your Doctrine listeners on the vault entities receive
  these objects too.
- With doctrine/dbal 3 there is no rollback middleware: after a rollback of your transaction the private manager is
  only replaced at the next unit-of-work boundary.
- In worker mode the private manager is dropped at every boundary; your own managers are still yours to clear
  (REQ-RUNTIME-002 rule 7, see [FRANKENPHP-WORKER-AUDIT](FRANKENPHP-WORKER-AUDIT.md)).

A transaction you open on the connection includes the vault's rows; a rollback of it makes the private manager start
over (its identity map could describe rows that no longer exist).

### XML mapping

`database.mapping_type: xml` maps the six entities from `src/Resources/config/doctrine/*.orm.xml` (same tables,
columns, indexes and constraints as the attributes: switching moves nothing). `database.register_mapping: false` lets
you map `Nowo\VaultBundle\Entity` yourself, e.g. from that directory on a dedicated manager (then set
`database.entity_manager` to it). `table_prefix`, `user_mapping` and `conditional_mapping` work with both.

## To 1.18.0

From **1.17.x** (minor):

```bash
composer update nowo-tech/vault-bundle
php bin/console assets:install   # vault.js / vault.css changed
```

**Nothing to do to keep today's behaviour.** The new options are off by default (`sharing.enabled` defaults to `true`).
What changes without configuration:

- **Show / Copy** on the item page are icon buttons in a row under the input (UiKit `btn_secondary` + `btn_icon`,
  icons `view` / `hide` / `copy`). An overridden `item_form` template that does not print the new
  `<template data-vault-reveal-template>` elements keeps the old text buttons; copy them from the bundle template to
  get the icons. Host CSS for `.vault-reveal-button` still applies.
- **View page:** the password field is a plain password input without the PasswordStrength meter (view mode and
  read-only items). On the edit page the requirement list is hidden while the input is empty.
- **Translations:** the share row action uses `vault.action.share` (*Share* / *Compartir* / …); "Vault" is translated in
  the non-English catalogues; `vault.shared.hint` is user-facing copy. Host overrides of `vault.action.access` no
  longer reach the row action: override `vault.action.share` instead. Host translation overrides of these keys can be
  removed.

Opt-in options (see [CONFIGURATION](CONFIGURATION.md)):

```yaml
nowo_vault:
    web_ui:
        host_note_template: 'vault/_host_note.html.twig'   # A1: one host line under the home / items header
        destructive_actions_in_menu: true                  # A4: Move to trash at the end of the row kebab menu
    sharing:
        enabled: false                                     # A5: no share UI, share / revoke routes answer 404
    database:
        conditional_mapping: true                          # A7: settings / token tables only with their feature
```

- `web_ui.host_note_template` replaces a `web_ui.templates.home` / `.items` override that only added a line: drop the
  override. The template gets `page` and `routes`; the `vault_host_note` block can be overridden instead.
- `sharing.enabled: false` removes four routes: links to `nowo_vault_item_share`, `nowo_vault_item_grant_revoke`,
  `nowo_vault_folder_share` or `nowo_vault_folder_grant_revoke` in host templates must go (or be guarded by
  `nowo_vault_sharing_enabled`, a variable of every vault page). Existing grants are kept and **still give access**,
  but owners can neither see the *Shared* badge nor revoke them from the UI any more: revoke the unwanted grants
  before turning sharing off, or call `VaultGrantService::revokeAs()` from your code. The *Shared with me* list and
  its home card stay. `VaultGrantService` still creates and revokes grants.
- `web_ui.destructive_actions_in_menu: true` prepends `nowo_ui_kit.theme.bundles.NowoVaultBundle.partials.row_actions`
  (`@NowoVaultBundle/ui/_row_actions.html.twig`). A host that already sets that bundle partial keeps its own.

### Existing settings and token tables (conditional mapping)

`database.conditional_mapping: true` leaves `{table_prefix}_settings` out of the mapping while
`config_storage.enabled` is `false`, and `{table_prefix}_extension_tokens` while `browser_extension.enabled` is
`false`. **No table is ever dropped automatically:**

- The bundle registers a DBAL schema asset filter on the connection of the vault entity manager that hides those
  tables from `doctrine:schema:update`, `doctrine:schema:validate` and `doctrine:migrations:diff`. A host that already
  has the tables gets **no** `DROP TABLE` in its next migration and `doctrine:schema:validate` stays in sync. A new
  host does not get them created.
- The rows stay where they are. Turning the feature back on maps the table again (it must still match the 1.17
  schema; run `doctrine:migrations:diff` after enabling it).
- To remove the tables, write the migration yourself (for example `DROP TABLE vault_settings` and
  `DROP TABLE vault_extension_tokens`) once you are sure you do not need the data: with the filter in place, the
  diff tool will not generate it.
- With the option on and the browser extension off, rows left in an old token table are still handled, with plain SQL
  on the vault connection (the schema tools do not see the table): the personal data exporter lists them, the eraser
  deletes the user's rows (byte-exact user id with `user_mapping: id`, as in 1.17.0) and
  `nowo:vault:extension-tokens:purge` deletes the expired ones. A token still inside its TTL would work again if the
  extension is re-enabled, so keep running the purge (or drop the table). A missing table counts as empty: its
  existence is checked first with a catalogue query that cannot fail (`to_regclass()` on PostgreSQL,
  `information_schema.tables` on MySQL / MariaDB), so an erasure inside the host's own transaction still commits.
- The plain SQL fallback assumes the default column names of the 1.17 token table (`id`, `user_id`, `created_at`,
  `expires_at`, `last_used_at`); a host that renamed them (naming strategy, mapping override) must migrate or drop the
  table itself.
- The fallback looks for the table under the **current** `table_prefix` (`{table_prefix}_extension_tokens`): if the
  prefix changed since the rows were written, the old table is not found; drop or rename it yourself.
- `database.conditional_mapping` defaults to `false` in 1.x (every table mapped, as before).

## To 1.17.1

From **1.17.0**:

```bash
composer update nowo-tech/vault-bundle
```

## To 1.17.0

From **1.16.0** (minor):

```bash
composer update nowo-tech/vault-bundle
```

**Standard hosts:** nothing to do. `user_mapping` defaults to `relation` (today's Doctrine relations to `user_class`),
`accepted_user_classes` to `[]`, and the schema does not change.

**BC notes**

- A signed-in user that is not a `user_class` instance (nor listed in `accepted_user_classes`) is now refused on the
  vault pages (403), by the repositories (`AccessDeniedException`), by `VaultAccessGuard` (no ownership, grant or
  scope membership), `VaultFieldRevealer` (`VaultAccessDeniedException`), `grantAs()` and by the browser extension
  login.
  Such users could not create vault rows before (Doctrine refused them) and, with a colliding id, could be matched
  with another user's personal vault. If your security user is a wrapper or a read model carrying the `user_class` id,
  list its class:

  ```yaml
  nowo_vault:
      user_class: App\Entity\User
      accepted_user_classes: [App\Security\SecurityUser]
  ```

- Entity user properties are typed `object|string|null` internally (they hold the id with the id mapping); the public
  getters keep their signatures. Custom code that persisted vault entities with a non-`user_class` user now gets an
  `AccessDeniedException` at `persist()` instead of a Doctrine error at `flush()`.
- `VaultPersonalDataExporter`, `VaultPersonalDataEraser` and `VaultGrantService` take the new
  `VaultUserLocatorInterface` (the `VaultUserLocator` class implements it, so existing wiring keeps working).
- `VaultFieldRevealer` refuses non-vault users but has no ownership or grant check of its own: if your code calls it,
  check `VaultAccessGuard::canAccessItem($user, $item, VaultAccessAction::View)` first (LQ-V-P2-I5).

**Opt-in: id mapping for DDD / hexagonal hosts.** New installs can store user ids instead of relations:

```yaml
nowo_vault:
    user_class: App\Security\SecurityUser   # the class whose getId() is stored; need not be a Doctrine entity
    user_mapping: id
```

With an existing database, switching from `relation` to `id` changes the user columns from foreign keys to
`VARCHAR(128)`: generate the migration (`doctrine:migrations:diff`), check that it drops the foreign keys and converts
`creator_id`, `created_by_id` and `user_id` keeping their values (ids are stored as strings), that it adds the
`<table>_<column>_idx` indexes and, on MySQL / MariaDB, `CHARACTER SET utf8mb4 COLLATE utf8mb4_bin` on those columns
and on `vault_grants.grantee_id` (SQL Server: `COLLATE Latin1_General_100_BIN2`), then migrate. Ids are compared byte
for byte: with `user_mapping: id` an id is usable only when it is a positive int, or a string of at most 128 bytes,
valid UTF-8, without leading / trailing whitespace and without control, format (zero-width, bidi), separator (any space)
or other default-ignorable characters; other users are refused, never trimmed, normalised or truncated (the default
`relation` mapping keeps the 1.16 rules). On a schema whose `grantee_id` still folds case, sharing with a case variant
of an existing grantee is refused (`grantee_not_allowed`) instead of failing. The bundle reads the database platform when it builds its
metadata in id mapping: set `doctrine.dbal.server_version` when the database is not reachable at `cache:warmup`
(otherwise the warmup fails with a `LogicException` rather than caching metadata without the collation). Alias
`Nowo\VaultBundle\Support\VaultUserLocatorInterface` to your own user directory when `user_class` is no Doctrine
entity (browser extension tokens and scope grantee checks load users through it).

## To 1.16.0

From **1.15.2** (minor):

```bash
composer update nowo-tech/vault-bundle
php bin/console assets:install
php bin/console doctrine:migrations:diff   # required: new columns, nullable owner FKs (see "Schema" below)
# add the two backfill UPDATEs below to the generated migration, then:
php bin/console doctrine:migrations:migrate
```

Every adopter must migrate, even without using the new features. With every new option left at its default, the
behaviour is 1.15.2's apart from the BC notes below.

### Schema

New columns (all additive) and nullable owner FKs, so that an item, folder or tag can be owned by a host **scope**
instead of a user:

| Table | Change |
| --- | --- |
| `vault_items` | `creator_id` nullable (still `ON DELETE CASCADE`); new `owner_scope` VARCHAR(128) NULL, `created_by_id` FK user NULL `ON DELETE SET NULL`, `secret_changed_at` DATETIME NULL, `catalogue_hidden` BOOLEAN NOT NULL DEFAULT false; indexes `vault_items_owner_scope_idx (owner_scope, deleted_at, updated_at)`, `vault_items_created_by_idx (created_by_id)` |
| `vault_folders` | `creator_id` nullable; new `owner_scope`, `created_by_id` (`SET NULL`); index `vault_folders_owner_scope_idx (owner_scope, deleted_at)` |
| `vault_tags` | `creator_id` nullable; new `owner_scope`; unique `vault_tags_scope_name_unique (owner_scope, name)` next to `(creator_id, name)` |
| `vault_grants` | new `expires_at` DATETIME NULL; indexes `vault_grants_expires_idx (expires_at)`, `vault_grants_grantee_idx (grantee_type, grantee_id)` (`created_by_id` already became `SET NULL` in 1.15.2) |

Reference DDL (default `table_prefix: vault_`; `<USER_ID_TYPE>` / `<user_table>` = your user entity's id type and
table, `<fk_…>` = the names Doctrine generated). The generated diff is the source of truth; it must contain this and
nothing else:

```sql
-- MySQL 8+ / MariaDB
ALTER TABLE vault_items
  MODIFY creator_id <USER_ID_TYPE> DEFAULT NULL,
  ADD owner_scope VARCHAR(128) DEFAULT NULL,
  ADD created_by_id <USER_ID_TYPE> DEFAULT NULL,
  ADD secret_changed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  ADD catalogue_hidden TINYINT(1) DEFAULT 0 NOT NULL;
ALTER TABLE vault_items ADD CONSTRAINT <fk_items_created_by> FOREIGN KEY (created_by_id) REFERENCES <user_table> (id) ON DELETE SET NULL;
CREATE INDEX vault_items_owner_scope_idx ON vault_items (owner_scope, deleted_at, updated_at);
CREATE INDEX vault_items_created_by_idx ON vault_items (created_by_id);
ALTER TABLE vault_folders
  MODIFY creator_id <USER_ID_TYPE> DEFAULT NULL,
  ADD owner_scope VARCHAR(128) DEFAULT NULL,
  ADD created_by_id <USER_ID_TYPE> DEFAULT NULL;
ALTER TABLE vault_folders ADD CONSTRAINT <fk_folders_created_by> FOREIGN KEY (created_by_id) REFERENCES <user_table> (id) ON DELETE SET NULL;
CREATE INDEX vault_folders_owner_scope_idx ON vault_folders (owner_scope, deleted_at);
ALTER TABLE vault_tags
  MODIFY creator_id <USER_ID_TYPE> DEFAULT NULL,
  ADD owner_scope VARCHAR(128) DEFAULT NULL;
CREATE UNIQUE INDEX vault_tags_scope_name_unique ON vault_tags (owner_scope, name);
ALTER TABLE vault_grants ADD expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)';
CREATE INDEX vault_grants_expires_idx ON vault_grants (expires_at);
CREATE INDEX vault_grants_grantee_idx ON vault_grants (grantee_type, grantee_id);

-- PostgreSQL
ALTER TABLE vault_items ALTER creator_id DROP NOT NULL;
ALTER TABLE vault_items ADD owner_scope VARCHAR(128) DEFAULT NULL;
ALTER TABLE vault_items ADD created_by_id <USER_ID_TYPE> DEFAULT NULL;
ALTER TABLE vault_items ADD secret_changed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;
ALTER TABLE vault_items ADD catalogue_hidden BOOLEAN DEFAULT false NOT NULL;
ALTER TABLE vault_items ADD CONSTRAINT <fk_items_created_by> FOREIGN KEY (created_by_id) REFERENCES <user_table> (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE;
CREATE INDEX vault_items_owner_scope_idx ON vault_items (owner_scope, deleted_at, updated_at);
CREATE INDEX vault_items_created_by_idx ON vault_items (created_by_id);
ALTER TABLE vault_folders ALTER creator_id DROP NOT NULL;
ALTER TABLE vault_folders ADD owner_scope VARCHAR(128) DEFAULT NULL;
ALTER TABLE vault_folders ADD created_by_id <USER_ID_TYPE> DEFAULT NULL;
ALTER TABLE vault_folders ADD CONSTRAINT <fk_folders_created_by> FOREIGN KEY (created_by_id) REFERENCES <user_table> (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE;
CREATE INDEX vault_folders_owner_scope_idx ON vault_folders (owner_scope, deleted_at);
ALTER TABLE vault_tags ALTER creator_id DROP NOT NULL;
ALTER TABLE vault_tags ADD owner_scope VARCHAR(128) DEFAULT NULL;
CREATE UNIQUE INDEX vault_tags_scope_name_unique ON vault_tags (owner_scope, name);
ALTER TABLE vault_grants ADD expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;
CREATE INDEX vault_grants_expires_idx ON vault_grants (expires_at);
CREATE INDEX vault_grants_grantee_idx ON vault_grants (grantee_type, grantee_id);

-- SQLite: nullability and FK actions cannot be altered in place; Doctrine rebuilds the tables.

-- Backfill (same migration, after the DDL; Doctrine cannot generate it)
UPDATE vault_items   SET created_by_id = creator_id, secret_changed_at = updated_at;
UPDATE vault_folders SET created_by_id = creator_id;
```

On MySQL / MariaDB mark the migration **non-transactional** (`isTransactional(): false`): DDL auto-commits. No row
changes owner: every existing row stays personal (`owner_scope IS NULL`, `creator_id` set).

**Rollback to 1.15.x** is possible only while no scope row and no temporary grant exists. Check first (read-only, exit
code 1 when a rollback would lose data):

```bash
php bin/console nowo:vault:schema:check-downgrade
# {"safe":true,"scope_items":0,"scope_folders":0,"scope_tags":0,"temporary_grants":0}
```

then drop the new indexes and columns and restore `NOT NULL` on the three `creator_id` columns.

### BC notes

- **`VaultItem::getCreator()`, `VaultFolder::getCreator()`, `VaultTag::getCreator()` are deprecated.** They still
  return the owner of personal rows and throw `VaultOwnerException` for scope rows (which exist only once you enable
  `scopes`). Use `getOwner()` (`VaultOwner`: `isScope()`, `getUser()`, `getScope()`, `key()`, `isUser($user)`) or
  `getCreatedBy()` (audit creator, nullable). Listeners that call `getCreator()` on any item must switch before
  enabling scopes.
- **`VaultGrantService::grant()` and `revoke()` are deprecated** (they still work, create permanent grants only and
  never re-check the actor). Use `grantAs($actor, …, ?$expiresAt)` (returns `VaultGrantResult`: `Created`, `Updated`,
  `Unchanged`; throws `VaultGrantRefusedException` with a reason code) and `revokeAs($actor, $grant)` (false when the
  grant was already gone). Both old methods now also emit `GRANT_CREATED` / `GRANT_UPDATED` / `GRANT_REVOKED`. The
  built-in share pages use the new methods.
- **Grant reads skip expired grants.** `VaultGrantRepositoryInterface` is unchanged; the Doctrine repository's
  `findByGrantee()`, `findGrantedResourceIds()` and `countByResources()` now return active grants only, and every
  access decision re-checks `VaultGrant::isActiveAt()` against the clock (custom repositories keep working). No grant
  expires unless one is created with `expiresAt`. `findByResource()` / `findOne()` / `findById()` still return expired,
  not yet purged rows (the share page shows them as expired).
- **The access guard no longer runs its redundant third grant lookup** with the Doctrine repository: one query loads
  the user's and teams' grants on the item and its folder. A custom `VaultGrantRepositoryInterface` keeps the 1.15
  lookups.
- **Purging an item or folder deletes its grants** in the same transaction (no orphan grants), with
  `GRANT_REVOKED` (reason `resource_purged`).
- **Key rotation (`nowo:vault:reencrypt`) no longer touches `updated_at`** (it used to reorder every list).
- **New constructor arguments are appended and optional** (`VaultItem`, `VaultFolder`, `VaultTag`, `VaultGrant`,
  `VaultAccessGuard`, `VaultGrantService`, `VaultItemCreator`, `VaultItemUpdater`, `VaultTrashService`,
  `VaultSharedItemResolver`, `VaultFolderService`, `VaultRouteLoader`, `VaultManageController`).
- **New route keys** (`scope_items`, `scope_item_new`, `scope_folder_create`, `catalogue`, `health`, `item_attachment`,
  `personal_export`, `personal_export_download`) have
  defaults, so custom `nowo_vault.routes` maps keep working. The scope keys are registered only with `scopes.enabled`
  (and `catalogue` / `health` also with `catalogue.enabled` / `health.enabled`); `item_attachment` with the manage UI;
  the export keys with `export.enabled`.
- **Item form option `password_policy`** (`?VaultPasswordPolicy`, default null): the bundle controllers pass the
  owner's policy for login items; it validates only with `enforceOnSave`. Forms built by hosts are unchanged.
- **The reveal JSON gains keys:** `item_reveal` now answers `{"item", "field", "purpose", "encoding": "plain",
  "value"}` (1.15.2: `{"field", "value"}`). Clients must read `value` only when `encoding` is `plain` (a later
  zero-knowledge mode answers `ciphertext`). The bundled `vault.js` does. A `websites` reveal returns a list.
- **`VaultItemRevealedEvent`** has three more properties (`purpose`, `ownerKey`, `occurredAt`); the constructor
  arguments are appended and optional. Copies dispatch `ITEM_COPIED` (not `ITEM_REVEALED`): add it to your audit
  listener. Since an audit listener that throws now refuses the reveal (503), let it throw only when the audit write
  really failed.
- **Two reveal budgets:** `security.secret_access_rate_limit` (1.15.2, shared with `/logins`) still applies, and the
  web reveal, copy and download routes also count against `reveal.rate_limit`. Use a shared, persistent cache pool
  (`reveal.rate_limit.cache_pool`).
- **Templates:** `item_form.html.twig` gains Copy buttons and an attachment download button (data attributes on the
  hidden `[data-vault-reveal]` element: `data-vault-reveal-copy`, `-copied`, `-auto-hide`, `data-vault-attachment-url`).
  Overrides keep working; copy them to offer the new buttons. New templates `scope_items`, `catalogue`, `health` and
  `export`.
- **Headers:** every bundle response also carries `Expires: 0`.

### Configuration examples

The contract draft's Beacon example used `grants.require_grantee_list` and `reveal.mode`; neither exists (the config
tree refuses them and the container fails to compile). Remove them: the fail-closed grantee list is the 1.15.2 default
(`security.allow_unlisted_grantees: false`, keep it), and on-demand reveal is unconditional since 1.15.2.

```yaml
nowo_vault:
    scopes: { enabled: true, access_resolver: App\Vault\Scope\ScopeAccessResolver }
    security: { deny_status: 404, allow_unlisted_grantees: false }
    grants: { temporary: { max_ttl: 'P30D', max_permission: read } }
```

### New options (all off by default)

```yaml
nowo_vault:
    scopes:
        enabled: false
        access_resolver: null            # service implementing VaultScopeAccessResolverInterface (required when enabled)
    grants:
        temporary:
            min_ttl: PT5M
            max_ttl: P30D
            max_permission: read         # read | write (admin is refused)
            ui: false                    # expiry choice in the share form
    catalogue:
        enabled: false                   # needs scopes.enabled
        per_page: 50
    reveal:
        auto_hide_seconds: 30
        revealable_fields: {}            # per type, e.g. { login: [password, note, username] }; default the secret fields
        rate_limit:
            enabled: true
            per_user_per_minute: 30
            per_user_per_hour: 300
            per_user_item_field_per_minute: 10
            cache_pool: cache.app
    health:
        enabled: false                   # health route; needs scopes.enabled (the services work without it)
        max_items: 2000
        cache_ttl: 0                     # seconds; 0 = never cached
        min_interval_seconds: 10         # one computed report per viewer and owner per window
        breach_cache_ttl: 86400          # breach answers per item and secret change
        cache_pool: cache.app
        breach_checker: null             # service implementing VaultBreachCheckerInterface
        policy_resolver: null            # service implementing VaultPasswordPolicyResolverInterface
        policy: { min_length: 12, min_classes: 3, max_age_days: 365, enforce_on_save: false }
    export:
        enabled: false                   # decrypted personal export routes; needs reauth_checker
        reauth_checker: null             # service implementing VaultReauthenticationCheckerInterface (no default)
        reauth_max_age: 300              # seconds, at most 900
        accepted_primary_factors: [password]
        accepted_mfa_factors: [totp, webauthn]
        formats: [json, csv]
        max_items: 5000
        max_bytes: 52428800
        include_attachments: true        # JSON only
        cooldown_seconds: 600
        cache_pool: cache.app            # cooldown + used proof ids: use a pool shared by all workers
        lock_factory: lock.factory       # symfony/lock factory (if present): atomic proof use + cooldown per user
        csv_raw_values: false            # true = exact CSV cells (no ' before = + - @)
```

GDPR: call `VaultPersonalDataExporter::export($userId)` for access requests and `VaultPersonalDataEraser::erase($userId)`
**before** deleting a user row (it no longer relies on the FK cascades), and remove any interim eraser of your own.

Schedule `php bin/console nowo:vault:grants:purge-expired` (for example every 5 minutes) if you create temporary
grants: access already ends at `expiresAt`, the command deletes the rows and emits `GRANT_EXPIRED`.

## To 1.15.2

Security patch. From **1.15.1**:

```bash
composer update nowo-tech/vault-bundle
php bin/console assets:install
php bin/console doctrine:migrations:diff   # one FK change, see "Schema" below
php bin/console doctrine:migrations:migrate
```

**Secret fields are no longer rendered.** View and edit pages show passwords, CVV, card PIN, card number, document number, secure note content and notes as empty fields with a "stored value hidden" placeholder and a Show button, which calls the new `item_reveal` route (`POST /tools/vault/items/{id}/reveal/{field}`). Saving with a secret field left empty keeps the stored value; tick it under **Clear stored values** to empty it. If you override `item_form.html.twig`, keep the hidden `[data-vault-reveal]` element (or render your own with the reveal URL, the `vault_reveal_<item id>` CSRF token and the labels) and load `vault.js`; if you customised `nowo_vault.routes`, the new `item_reveal` key has the default path `/tools/vault/items/{id}/reveal/{field}`. Run `assets:install` for the new `vault.js`.

**Sharing fails closed.** Without a `GRANT_LIST_QUERY` listener that lists grantees, the share form can no longer grant anyone (before, any user or team id typed in the form was accepted, across tenants). Add a listener that lists the users and teams each sharer may grant (see [examples/AccessControl.md](examples/AccessControl.md)). Only if you knowingly rely on the old free-form behaviour, restore it (unsafe):

```yaml
nowo_vault:
    security:
        allow_unlisted_grantees: true   # UNSAFE: any typed user / team id can be granted access
```

**New options (defaults keep 1.15.1 behaviour apart from the above):**

```yaml
nowo_vault:
    security:
        deny_status: 403                 # or 404: inaccessible items, folders, grants and fields answer "not found"
        secret_access_rate_limit:        # ONE budget per user shared by the reveal route and extension /logins (1 unit per call)
            enabled: true
            max_requests: 60
            window_seconds: 60
            cache_pool: cache.app            # must be shared by all workers and persistent (not an array pool)
```

**Cache headers.** Every bundle response now has `Cache-Control: no-store, private`, `Pragma: no-cache` and `X-Content-Type-Options: nosniff`. Browsers no longer keep vault pages in the back/forward cache.

**Audit.** Listen to `VaultEvents::ITEM_REVEALED` (`VaultItemRevealedEvent`) to record who revealed which field (channel `web`) and which credentials the browser extension received (channel `extension`). The event never carries a value.

**Schema.** `vault_grants.created_by_id` becomes nullable with `ON DELETE SET NULL` (was `NOT NULL`, `ON DELETE CASCADE`): deleting a user no longer deletes the grants that user created. `VaultGrant::getCreatedBy()` may now return `null`; handle it if your code reads it. Reference DDL (default `table_prefix`, `<fk_name>` = the name Doctrine generated, `<user_table>` / `<USER_ID_TYPE>` = your user entity's table and id type):

```sql
-- MySQL / MariaDB
ALTER TABLE vault_grants DROP FOREIGN KEY <fk_name>;
ALTER TABLE vault_grants MODIFY created_by_id <USER_ID_TYPE> DEFAULT NULL;
ALTER TABLE vault_grants ADD CONSTRAINT <fk_name> FOREIGN KEY (created_by_id) REFERENCES <user_table> (id) ON DELETE SET NULL;

-- PostgreSQL
ALTER TABLE vault_grants DROP CONSTRAINT <fk_name>;
ALTER TABLE vault_grants ALTER created_by_id DROP NOT NULL;
ALTER TABLE vault_grants ADD CONSTRAINT <fk_name> FOREIGN KEY (created_by_id) REFERENCES <user_table> (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE;

-- SQLite: Doctrine rebuilds the table.
```

No data changes. Rolling back to 1.15.1 needs no data fix as long as no grant has `created_by_id IS NULL` (delete those rows first).

## To 1.15.1

From **1.15.0**:

```bash
composer update nowo-tech/vault-bundle
```

No action needed when you import the `nowo_vault` route loader (`resource: .`, `type: nowo_vault`, the documented setup). If on Symfony >= 7.3 the vault pages or the browser extension API answered without that import (through the default `controllers: { resource: routing.controllers }` of `config/routes.yaml`), they are gone now: import the loader (see [INSTALLATION](INSTALLATION.md)).

## To 1.15.0

From **1.14.0**:

```bash
composer update nowo-tech/vault-bundle
```

### Template contract (REQ-UI-003)

The manage pages now extend UiKit scaffolds (see [TEMPLATES.md](TEMPLATES.md)). Hosts that only configured the bundle
need nothing; hosts with Twig overrides or a custom layout check the points below.

| Page | Before | Now |
| --- | --- | --- |
| `vault/home.html.twig` | extends `vault/base.html.twig`, block `body` | `page/dashboard`, data block `nowo_ui_dashboard_cards` |
| `vault/items.html.twig`, `shared.html.twig`, `trash.html.twig` | block `body`, local table / pager | `page/list`, data blocks `nowo_ui_list_filters`, `nowo_ui_list_row` |
| `vault/item_form.html.twig`, `share.html.twig` | block `body` | `page/form`, data blocks `nowo_ui_form_body`, `nowo_ui_form_aside` |
| `vault/runtime_config.html.twig` | block `body` | `page/settings`, data block `nowo_ui_settings_section` |

1. **Layout.** Point `nowo_ui_kit.layouts.admin` at your layout (or a bridge that renders the canonical blocks) and
   drop `nowo_vault.templates.layout`. The 1.x default `@NowoVaultBundle/layout.html.twig` (Tabler from a CDN) is
   removed: without configuration the UiKit layout applies. A 1.x host layout that renders `{% block body %}` keeps
   working through a deprecated bridge (deprecation logged).
2. **Removed partials:** `vault/base.html.twig`, `_flashes`, `_page_header`, `_pagination`, `_item_row_actions`,
   `_grants_table`, `_share_form_fields`. Overrides of these files in `templates/bundles/NowoVaultBundle/` are no longer
   used: theme the UiKit partials once (`nowo_ui_kit.theme.partials` / `templates/bundles/NowoUiKitBundle/`) or remap
   slots in `nowo_ui_kit.theme.bundles.NowoVaultBundle`.
3. **Page overrides** (`templates/bundles/NowoVaultBundle/vault/*.html.twig`) that filled `body` must move to the data
   blocks above; prefer `web_ui.templates.<page>` with a template that extends `@!NowoVaultBundle/vault/<page>.html.twig`.
   The pages receive `page` (view model) plus the variables listed in TEMPLATES.md; `layout`, `dashboardRoute` (lists),
   `trashForms`, `restoreForms`, `purgeForms`, `revokeGrantForms`, `tagDeleteForms`, `folderTrashForms` and
   `pagination` were replaced by the view models (`folderActions`, `tagActions`, `grantsTable`).
4. **Assets.** `vault.css` / `vault.js` stack in `nowo_ui_styles` / `nowo_ui_scripts`; a custom layout must render those
   blocks (and load the UiKit scripts for the confirm / generator dialogs: `nowo_ui_partial('scripts')`).
5. **JavaScript globals** `window.VAULT_PASSWORD_URL` / `VAULT_PASSWORD_CSRF_TOKEN` are gone: the generator reads
   `data-vault-password-url` / `data-vault-password-csrf` on its `<dialog>`.
6. **Translations:** `vault.pagination.*` removed (UiKit `pagination.*`); new keys listed in the CHANGELOG.

### Configuration

| Deprecated (removed in 2.0) | Replacement |
| --- | --- |
| `nowo_vault.route_prefix` | `nowo_vault.web_ui.path_prefix` |
| `nowo_vault.templates.layout` | `nowo_vault.web_ui.layout_template` (null = `nowo_ui_kit.layouts.admin`) |
| `nowo_vault.templates.<page>` | `nowo_vault.web_ui.templates.<page>` (`index` → `home`) |
| `nowo_vault.css_framework` | `nowo_ui_kit.css_framework` / `icon_set` |
| Twig global `nowo_vault_css_framework` | `nowo_ui_kit.css_framework` |

The bundle no longer seeds `nowo_ui_kit.css_framework: tabler` / `icon_set: tabler-icons` by default: it only copies the
deprecated `nowo_vault.css_framework` when you set it. Set `nowo_ui_kit.css_framework` (and `icon_set`) explicitly if
you relied on the old default.

### Security (fail closed)

- With `web_ui.enabled: true` (default) the container refuses to compile without CSRF protection
  (`framework.csrf_protection` + `framework.form.csrf_protection`). API-only installs set `web_ui.enabled: false`.
- The container refuses to compile when every `security.*_roles` list is empty without `access_checker` /
  `allow_unauthenticated`.

```bash
composer update nowo-tech/vault-bundle nowo-tech/ui-kit-bundle nowo-tech/form-kit-bundle
php bin/console cache:clear
php bin/console assets:install
```

## To 1.14.0

From **1.13.0**:

```bash
composer update nowo-tech/vault-bundle
```

From **1.13.0**:

```bash
composer update nowo-tech/vault-bundle
```

What changes for hosts:

- **Lists are paginated.** The items, shared and trash pages now show 50 entries per page with previous / next links
  (`?page=N`). To keep the previous single-page behaviour for small vaults, raise the limit (max 1000):

  ```yaml
  nowo_vault:
      pagination:
          per_page: 1000
  ```

  Overridden `items.html.twig` / `shared.html.twig` / `trash.html.twig` keep working but show only the first page
  until you add `{% include '@NowoVaultBundle/vault/_pagination.html.twig' with {pagination: pagination|default(null), routeName: routes.items.name} %}`
  (use `routes.shared.name` / `routes.trash.name` on those pages). Search results are unchanged (capped at 50).
- **Custom item repository** (`VaultItemRepositoryInterface` alias replaced): nothing to do; implement the new
  optional `VaultItemPaginatorInterface` to page in the database instead of in PHP.
- **`VaultItemListQueryEvent` overrides** are returned as given (not sliced): page them in your listener if needed.
- **Browser extension:** `/logins` decrypts at most `browser_extension.max_login_items` (default 1000) login items per
  lookup. Raise it if a single user really has more login items.
- **Logs:** with a logger (MonologBundle) the bundle now writes info / notice / warning / error records for extension
  logins, grant changes, purges, runtime config changes and re-encryption (see `docs/SECURITY.md#logging`; no secrets).
  Silence them with a Monolog channel / level filter if unwanted.
- **Routes:** declared with `#[Route]` attributes but still loaded only through `type: nowo_vault`; keep your
  `config/routes/nowo_vault.yaml` as is and do **not** add an `attribute` import of the bundle controllers.
- **Clock:** token expiry uses `Psr\Clock\ClockInterface` when registered (FrameworkBundle + `symfony/clock`); a
  host `MockClock` in tests now also drives extension token expiry.

## To 1.13.0

From **1.12.0**:

```bash
composer update nowo-tech/vault-bundle
```

## To 1.12.0

From **1.11.1**:

```bash
composer update nowo-tech/vault-bundle
```

- Browser extension: users need `security.access_roles` (or an admin role) to log in to the extension and to use an existing token; tokens of users the firewall user checker refuses are revoked on their next use. Custom `VaultAccessCheckerInterface` implementations receive the token's user as `$user` on these calls and must evaluate it (not the request token, which is empty on Bearer requests).

## To 1.11.1

From **1.11.0**:

```bash
composer update nowo-tech/vault-bundle
```

## To 1.11.0

From **1.10.0**:

```bash
composer update nowo-tech/vault-bundle
```

Security release (SEC-004 re-audit 2026-10-28):

- **Update the browser extension** in every browser (rebuild with `make extension-sync` or reinstall `extension/chrome` /
  `extension/firefox`): old extensions keep working but stay exposed to the autofill click-jacking fix.
- **Shared items:** a grantee with Write keeps editing the content, but can no longer move the item to another folder
  or change its tags. Folder grants now cover only items created by the folder's creator: if you shared a folder that
  holds items other users created, share those items directly.
- On `http://` pages the extension only offers logins whose website is stored as `http://…`.
- `security.allow_unauthenticated: true` fails container compilation in `prod`.
- The extension's vault URL must be `https://` (or `http://localhost`).

## To 1.10.0

From **1.9.0**:

```bash
composer update nowo-tech/vault-bundle
```

## To 1.9.0

From **1.8.2**:

```bash
composer update nowo-tech/vault-bundle
```

### Forms rendered through Form Types (REQ-TWIG-005)

The controllers now always pass form variables for every action that used hand-rolled markup (see [USAGE — Forms in templates](USAGE.md#forms-in-templates-req-twig-005)). **If you override one of these templates**, render the new variables with `form_start` / the child loop / `form_end`:

| Template | New variable(s) |
| -------- | --------------- |
| `vault/items.html.twig` | `searchForm`, `folderForm`, `tagDeleteForms[tag.id]`, `folderTrashForms[folder.id]`, `trashForms[item.id]` |
| `vault/shared.html.twig` / `vault/_item_row_actions.html.twig` | `trashForms[item.id]` (the partial reads it from the including context) |
| `vault/trash.html.twig` | `restoreForms[item.id]`, `purgeForms[item.id]` |
| `vault/share.html.twig` / `vault/_grants_table.html.twig` | `revokeGrantForms[grant.id]` |
| `vault/home.html.twig` / `vault/_password_generator.html.twig` | `generatorForm` |
| `vault/item_form.html.twig` | `form.attachment` (new unmapped child of `VaultItemFormType`; list it among the per-type fields excluded from the trailing loop, as the shipped template does) |
| `vault/runtime_config.html.twig` | `form` (`VaultRuntimeConfigType`) |

Posted names and token ids are unchanged, so existing overrides keep working until migrated. `VaultItemFormType` now contains a `FileType` child, so the item form is rendered as `multipart/form-data`; the identity-card attachment is therefore actually uploaded with the form.

## To 1.8.2

From **1.8.1**:

```bash
composer update nowo-tech/vault-bundle
```

## To 1.8.1

From **1.8.0**:

```bash
composer update nowo-tech/vault-bundle
```

## To 1.8.0

From **1.7.0** — security - firewall user checker on browser-extension login by default; explicit service options no longer overridden.

```bash
composer update nowo-tech/vault-bundle
```

- **Extension login user checker:** with `browser_extension.user_checker` unset, the default authenticator now runs `security.user_checker.<firewall>` (firewall `main`, else the first secured firewall) instead of the global `UserCheckerInterface` alias. Users your firewall checker rejects (disabled, locked, unverified…) can no longer obtain extension tokens. To use another firewall set `browser_extension.firewall: <name>` (must have security enabled, otherwise the container fails to compile); an explicit `browser_extension.user_checker` keeps priority.
- `browser_extension.user_provider` / `user_checker` are now actually applied (they were overridden by autowiring); verify the configured service ids exist. CORS wildcard `*` now works in `dev`/`test` as documented (still rejected in `prod`).

## To 1.7.0

From **1.6.0** — resumable re-encryption, per-IP extension login limit, docs, vitest 4.

```bash
composer update nowo-tech/vault-bundle
```

- **Extension login per-IP limit:** failed extension logins are now also counted per client IP across all usernames; after `browser_extension.login_rate_limit.max_attempts_per_ip` failures (default `20`) in `interval_seconds`, that IP gets HTTP 429 for every username. If many users log in through one shared IP (corporate NAT, reverse proxy without trusted proxies configured), raise the value or set `0` to disable. Make sure `framework.trusted_proxies` is set so the real client IP is used.

## To 1.6.0

From **1.5.1** — security - master key protection, user checker on extension login, CORS allow-list honoured.

```bash
composer update nowo-tech/vault-bundle
```

- With a YAML/env `encryption_key`, the runtime config screen no longer stores a database key; rotate with `nowo:vault:reencrypt --persist-new-key`.
- Storing the master key in the database now requires `nowo-tech/doctrine-encrypt-bundle` (it was stored in plaintext before).
- Disabled/locked users can no longer obtain browser-extension tokens. Apps with a custom firewall user checker should set `browser_extension.user_checker: security.user_checker.<firewall>`.
- A non-empty `cors_allowed_origins` must now list the extension origin explicitly (`chrome-extension://<id>`).

## To 1.5.1

From **1.5.0** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/vault-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.5.0

From **1.4.8** — Doctrine ORM `SortDirection`; `doctrine/orm` `^3.7` required.

```bash
composer update nowo-tech/vault-bundle doctrine/orm --with-all-dependencies
php bin/console cache:clear
```

- **Requirement:** `doctrine/orm` **^3.7** (ORM 2.x and 3.0–3.6 are no longer supported). Upgrade Doctrine ORM first if your application is on an older version.
- No configuration or public API changes.

## To 1.4.8

From **1.4.7** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/vault-bundle
php bin/console cache:clear
```

No breaking changes. Behaviour notes (FrankenPHP worker hardening):

- `ITEM_READ_ONLY_RESOLVE` listeners are now called on every access check instead of once per (user, item) for the worker life. Keep them cheap, or cache inside the listener with a per-request scope.
- The `DoctrineOrm*Repository` constructors accept two new optional arguments (`?ManagerRegistry $registry`, `?string $managerName`); the bundle wires them automatically. Subclassing is not possible (classes are `final`), so no action is needed.
- With `config_storage.enabled: true`, runtime config is read from `config_storage.cache_pool` on every call. Use a pool shared by all workers (for example `cache.app` on Redis or a shared filesystem); a per-process pool such as `cache.adapter.array` would still diverge between workers.
- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

## From 1.4.6 to 1.4.7

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/vault-bundle
```

## From 1.4.6 to 1.4.7

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/vault-bundle
```

# Upgrading

This document describes how to upgrade between versions of Vault Bundle.

## 1.3.0 (2026-08-03)

Minor release: REQ-UI-002 — `security.allow_unauthenticated`, AllowAll checker, SecurityBundle compile-time guard, and soft manage-UI gate (no hard `IsGranted` attribute).

```bash
composer require nowo-tech/vault-bundle:^1.3
php bin/console cache:clear
```

### Behaviour

| Topic | Before | 1.3.0 |
| --- | --- | --- |
| Apps without SecurityBundle | Could boot | Boot fails with `LogicException` unless `allow_unauthenticated: true` |
| Manage auth attribute | `#[IsGranted('IS_AUTHENTICATED')]` | Feature/admin checks via `VaultAccessCheckerInterface` (+ optional AllowAll) |
| Host firewall | Recommended | Still required in production (`access_control` on `/tools/vault`) |

**Trusted local demos only:**

```yaml
nowo_vault:
    security:
        allow_unauthenticated: true   # never in production
```

**Production:** keep `allow_unauthenticated: false`, install SecurityBundle, grant `access_roles` / area roles, and protect manage paths with host `access_control`.

### Breaking changes

Applications that used the manage UI **without** SecurityBundle must install/configure it or set `allow_unauthenticated: true` for non-production use. Item/folder ACL still requires an authenticated user for mutating flows.

## 1.2.0 (2026-07-30)

Optional host CSS stack hint and manage page shell for REQ-UI-001. **Non-breaking** — default remains `tabler` (demo CDN behaviour for Tabler/Bootstrap-compatible values).

```bash
composer require nowo-tech/vault-bundle:^1.2.0
php bin/console cache:clear
```

### Behaviour

- New root key `css_framework` → Twig global `nowo_vault_css_framework`.
- Manage pages go through `@NowoVaultBundle/vault/base.html.twig` (stacks package CSS/JS with `parent()`). Prefer `templates.layout` at the host chrome instead of freezing every page.
- If you **overrode** manage templates that `{% extends layout %}`, update them to `{% extends '@NowoVaultBundle/vault/base.html.twig' %}` (or keep extending your own shell and stack assets yourself).

```yaml
nowo_vault:
    css_framework: bootstrap5   # or tabler | custom | …
    templates:
        layout: 'layouts/app.html.twig'
```

## 1.1.5 (2026-07-29)

Maintainer tooling, docs, CI matrix, and PHPStan/coverage hygiene. **No action required** for applications already on 1.1.4 — no bundle API, schema, or configuration changes.

```bash
composer update nowo-tech/vault-bundle
```

Demo contributors: Symfony 8 demo image is `dunglas/frankenphp:1-php8.5-bookworm`. Recreate the container after pull if you use the demo.

## 1.1.4 (2026-07-22)

Demo and maintainer tooling patch. **No action required** for applications already on 1.1.3 — no bundle API, schema, or configuration changes.

Demo contributors: FrankenPHP mode is no longer tied to `APP_ENV`. Set `FRANKENPHP_MODE=classic` or `worker` in `demo/symfony8/.env`, then recreate the container (`docker compose up -d` / `make up`). See [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).

## 1.1.3 (2026-07-16)

Contributor and maintainer tooling release. **No action required** for applications already on 1.1.2 — no bundle API, schema, or configuration changes.

Maintainers and contributors: after cloning, run `make setup-hooks` once so commit messages reject Cursor `Co-authored-by` trailers (REQ-GIT-001). See [CONTRIBUTING.md](CONTRIBUTING.md) and [GITHUB_CI.md](GITHUB_CI.md).

## 1.1.2 (2026-07-13)

CI and test-infrastructure patch. **No action required** for applications already on 1.1.1 — no bundle API, schema, or configuration changes.

If you run **Symfony 8** with **PHP 8.4+**, ensure `doctrine/doctrine-bundle` ^3.0 is installed (see [1.0.0](#symfony-8--doctrine) below). Doctrine Bundle 3 enables native lazy objects by default on supported PHP versions.

## 1.1.1 (2026-07-08)

Documentation and demo developer-experience release. **No action required** for applications already on 1.1.0 — no bundle API, schema, or configuration changes.

Maintainers: see [SPEC-KIT.md](SPEC-KIT.md) and [specs/001-baseline/](../specs/001-baseline/) when updating production code under `src/`.

Demo contributors: sibling path repos for `doctrine-encrypt-bundle` and `tag-input-bundle` are no longer mounted in Docker; the demo resolves them from Packagist.

## 1.1.0 (2026-07-05)

### Summary

Adds browser extension API, item tags, optional database-backed runtime config, encryption key rotation command, CSRF on manage POST actions, and extension login rate limiting.

### Upgrade steps

```bash
composer update nowo-tech/vault-bundle
php bin/console doctrine:migrations:migrate   # or doctrine:schema:update --force
php bin/console assets:install
php bin/console cache:clear
```

New tables (when using default `table_prefix: vault_`):

| Table | When |
|-------|------|
| `vault_tags`, `vault_item_tag` | Always (tags feature) |
| `vault_settings` | When `config_storage.enabled: true` |
| `vault_extension_tokens` | When `browser_extension.enabled: true` |

### CSRF on manage UI (breaking for custom integrations)

All state-changing POST actions in the manage UI now require a valid CSRF token (`_token` form field, `X-CSRF-Token` header, or JSON `_token`). Custom forms or JavaScript that POST to vault manage routes must include the token from the rendered page or Symfony CSRF service.

The browser extension API uses Bearer tokens and is **not** affected by manage CSRF.

### Browser extension (optional)

To enable the extension API:

```yaml
nowo_vault:
    browser_extension:
        enabled: true
        user_provider: security.user.provider.concrete.app_user_provider
```

Add firewall rule for `^/api/vault/extension` with `PUBLIC_ACCESS` (Bearer auth is enforced by the bundle). Schedule token cleanup:

```bash
php bin/console nowo:vault:extension-tokens:purge
```

See [BROWSER-EXTENSION.md](BROWSER-EXTENSION.md).

### Runtime config (optional)

```yaml
nowo_vault:
    config_storage:
        enabled: true
```

Creates `{table_prefix}_settings` for runtime overrides (e.g. encryption key stored in DB). See [CONFIGURATION.md](CONFIGURATION.md#database-backed-runtime-configuration).

### Encryption key rotation

Use `nowo:vault:reencrypt` — see [ENCRYPTION-KEY-ROTATION.md](ENCRYPTION-KEY-ROTATION.md). Demo walkthrough: `make -C demo/symfony8 vault-rotation-demo`.

### Template overrides

If you copied `@NowoVaultBundle/vault/_item_access.html.twig`, switch to `_item_row_actions.html.twig` (the former was removed).

### Suggested packages

- `nowo-tech/tag-input-bundle` — tag input in item forms
- `nowo-tech/doctrine-encrypt-bundle` — encrypt `vault_settings.encryption_key` at rest

## 1.0.0 (2026-07-04)

Initial release of **VaultBundle** (password and secrets vault). If you previously used **YopassBundle** from the same repository lineage, treat this as a new package:

- Composer package: `nowo-tech/vault-bundle`
- Config alias: `nowo_vault`
- Routes type: `nowo_vault`

There is no automatic migration from Yopass share links. Plan a fresh schema and data import if needed.

### Upgrade steps

```bash
composer require nowo-tech/vault-bundle
php bin/console doctrine:migrations:migrate
php bin/console assets:install
php bin/console cache:clear
```

Configure `encryption_key`, `user_class`, and security roles as described in [INSTALLATION.md](INSTALLATION.md) and [CONFIGURATION.md](CONFIGURATION.md).

### Symfony 8 + Doctrine

If you run Symfony 8, ensure **`doctrine/doctrine-bundle` ^3.0** is installed:

```bash
composer require doctrine/doctrine-bundle:^3.0
```

### Access control events

Optional listeners for listing, grants, teams, and read-only items:

| Event | When |
|-------|------|
| `VaultItemListQueryEvent` | Before the vault list query |
| `VaultItemListResultEvent` | After items are loaded |
| `VaultItemAccessCheckEvent` | Before item view/edit/trash/share |
| `VaultFolderAccessCheckEvent` | Before folder actions |
| `VaultGrantListQueryEvent` | When building share UI for items/folders |
| `VaultItemReadOnlyEvent` | Resolve view-only access per item |

See [examples/AccessControl.md](examples/AccessControl.md).

[1.1.4]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.4
[1.1.3]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.3
[1.1.2]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.2
[1.1.1]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.1
[1.1.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.0
[1.0.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.0.0
### FormKitBundle (admin forms)

If you use admin/dashboard Symfony forms, ensure `nowo-tech/form-kit-bundle` ^2.0 is installed (pulled transitively) and `Nowo\FormKitBundle\NowoFormKitBundle` is registered. Form types use profile `vault` via `#[FormKitConfig]`; the bundle prepends that profile when the host has not defined it.

## Unreleased

## To 1.4.6

From **1.4.5** — Review Flex `when@prod` security defaults and extension `access_control` for `/api/vault/extension`.

```bash
composer update nowo-tech/vault-bundle
```

## To 1.4.5

No application upgrade steps. Transitive dependency refresh only.

```bash
composer update nowo-tech/vault-bundle
```

## To 1.4.4

Review Flex recipe `security_nowo_vault.yaml` after update.

```bash
composer update nowo-tech/vault-bundle
```

## To 1.4.3

Review Flex recipe `security_nowo_vault.yaml`: `^/tools/vault` requires `ROLE_USER`. Adjust roles or paths for your app.

```bash
composer update nowo-tech/vault-bundle
```

## To 1.4.2

No application upgrade steps.

```bash
composer update nowo-tech/vault-bundle
```

## To 1.4.1

No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`).

```bash
composer update nowo-tech/vault-bundle
php bin/console cache:clear
```

## To 1.4.0

From **1.3.0** — Adds FormKit and/or UiKit where applicable, Twig Extra (REQ-TWIG-004), and Twig-CS-Fixer. Register TwigExtraBundle, NowoFormKitBundle, and NowoUiKitBundle if Flex did not. See CHANGELOG.

```bash
composer update nowo-tech/vault-bundle
php bin/console cache:clear
```

### Twig Extra Bundle (REQ-TWIG-004)

Hosts that render this bundle's Twig templates must install:

```bash
composer require twig/extra-bundle twig/string-extra
```

and enable `Twig\Extra\TwigExtraBundle\TwigExtraBundle`. Flex recipes usually register it automatically.

### Twig-CS-Fixer (maintainers)

Package maintainers: `composer twig:lint` / `composer twig:fix` use `.twig-cs-fixer.php` over `src/` (and `templates/` when present).

