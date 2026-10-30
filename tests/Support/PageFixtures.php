<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Support;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Dto\VaultGranteeChoice;
use Nowo\VaultBundle\Dto\VaultItemFormData;
use Nowo\VaultBundle\Dto\VaultShareFormData;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Form\VaultActionType;
use Nowo\VaultBundle\Form\VaultFolderCreateType;
use Nowo\VaultBundle\Form\VaultItemFormType;
use Nowo\VaultBundle\Form\VaultItemSearchType;
use Nowo\VaultBundle\Form\VaultPasswordGeneratorType;
use Nowo\VaultBundle\Form\VaultRuntimeConfigType;
use Nowo\VaultBundle\Form\VaultShareType;
use Nowo\VaultBundle\Tests\App\Kernel;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\Ui\VaultPageFactory;
use Nowo\VaultBundle\Ui\VaultPages;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

use function assert;
use function http_build_query;

/**
 * Page contexts of the REQ-UI-003 page contract test: the view models come from {@see VaultPageFactory} (the service
 * the controllers use), the FormViews from the real form types of the test kernel (FormKit profile, CSRF, Doctrine
 * folder choices), the other variables mirror the controllers.
 */
final class PageFixtures
{
    private static ?ContainerInterface $container = null;

    /**
     * @return array<string, mixed>
     */
    public static function home(bool $admin = true, ?VaultPageFactory $factory = null): array
    {
        $factory ??= self::factory();
        $routes = self::routes();

        return $factory->context([
            'page'           => $factory->home($routes, $admin ? 'nowo_vault_runtime_config' : null, $admin ? null : 'app_dashboard'),
            'routes'         => $routes,
            'dashboardRoute' => $admin ? null : 'app_dashboard',
            'itemTypes'      => VaultItemType::cases(),
            'generatorForm'  => self::forms()->create(VaultPasswordGeneratorType::class)->createView(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function items(bool $empty = false, ?VaultPageFactory $factory = null): array
    {
        $factory ??= self::factory();
        $routes  = self::routes();
        $user    = new TestUser();
        $work    = new VaultFolder('Work', $user);
        $folders = $empty ? [] : [$work, new VaultFolder('Personal', $user)];
        $tags    = $empty ? [] : [new VaultTag('banking', $user), new VaultTag('shared', $user)];
        $items   = [];
        if (!$empty) {
            $login = new VaultItem(VaultItemType::Login, 'GitHub', $user, 'cipher', $work);
            $login->setTags([$tags[0]]);
            $items = [$login, new VaultItem(VaultItemType::CreditCard, 'Company card', $user, 'cipher'), new VaultItem(VaultItemType::SecureNote, 'Wi-Fi', $user, 'cipher')];
        }
        $readOnly = $empty ? [] : [$items[2]->getId() => true];
        $folderId = $empty ? null : $work->getId();
        $search   = self::forms()->createNamed('', VaultItemSearchType::class, ['q' => ''], [
            'action'  => '/nowo_vault_items',
            'filters' => ['folder' => $folderId, 'tag' => null],
        ])->createView();

        return $factory->context([
            'page' => $factory->items(
                $routes,
                $items,
                $readOnly,
                self::actionForms($items, 'vault_trash_'),
                $search,
                $factory->pagination('nowo_vault_items', 1, 2, $empty ? 0 : 3, ['folder' => $folderId]),
                $folderId,
                null,
            ),
            'items'         => $items,
            'total'         => $empty ? 0 : 3,
            'readOnlyMap'   => $readOnly,
            'grantCounts'   => $empty ? [] : [$items[0]->getId() => 2],
            'folders'       => $folders,
            'tags'          => $tags,
            'folderActions' => $factory->folderActions($routes, $folders, self::actionForms($folders, 'vault_folder_trash_'), $empty ? [] : [$work->getId() => 1]),
            'tagActions'    => $factory->tagActions($routes, $tags, self::actionForms($tags, 'vault_tag_delete_', ['folder' => $folderId])),
            'activeFolder'  => $folderId,
            'activeTag'     => null,
            'searchQuery'   => '',
            'routes'        => $routes,
            'folderForm'    => self::forms()->createNamed('', VaultFolderCreateType::class, null, ['action' => '/nowo_vault_folder_create'])->createView(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function shared(bool $empty = false, ?VaultPageFactory $factory = null): array
    {
        $factory ??= self::factory();
        $routes = self::routes();
        $owner  = new TestUser('owner-1', 'owner@example.com');
        $items  = $empty ? [] : [new VaultItem(VaultItemType::Login, 'Team wiki', $owner, 'cipher'), new VaultItem(VaultItemType::Contact, 'Accountant', $owner, 'cipher')];
        $ro     = $empty ? [] : [$items[1]->getId() => true];

        return $factory->context([
            'page'        => $factory->shared($routes, $items, $ro, self::actionForms($items, 'vault_trash_'), $factory->pagination('nowo_vault_shared', 1, 50, $empty ? 0 : 2), null),
            'items'       => $items,
            'readOnlyMap' => $ro,
            'routes'      => $routes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function trash(bool $empty = false, ?VaultPageFactory $factory = null): array
    {
        $factory ??= self::factory();
        $routes = self::routes();
        $items  = $empty ? [] : [new VaultItem(VaultItemType::Login, 'Old router', new TestUser(), 'cipher')];

        return $factory->context([
            'page'   => $factory->trash($routes, $items, self::actionForms($items, 'vault_restore_'), self::actionForms($items, 'vault_purge_'), $factory->pagination('nowo_vault_trash', 1, 50, $empty ? 0 : 1), null),
            'items'  => $items,
            'routes' => $routes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function itemForm(VaultItemType $type = VaultItemType::Login, bool $existing = false, bool $readOnly = false, bool $viewMode = false, ?VaultPageFactory $factory = null): array
    {
        $factory ??= self::factory();
        $routes  = self::routes();
        $user    = new TestUser();
        $folder  = self::managed(new VaultFolder('Work', $user));
        $folders = $existing ? [$folder] : [];
        $item    = $existing ? new VaultItem($type, 'Sample ' . $type->value, $user, 'cipher', $folder) : null;
        $data    = new VaultItemFormData($type);
        if ($item instanceof VaultItem) {
            $data->title  = $item->getTitle();
            $data->folder = $folder;
            $data->tags   = ['work'];
        }
        $form = self::forms()->create(VaultItemFormType::class, $data, ['folders' => $folders])->createView();

        return $factory->context([
            'page'                    => $factory->itemForm($routes, $form, $type, $item, $readOnly, $item?->getFolder()?->getId()),
            'form'                    => $form,
            'itemType'                => $type,
            'item'                    => $item,
            'readOnly'                => $readOnly,
            'viewMode'                => $viewMode,
            'folders'                 => $folders,
            'routes'                  => $routes,
            'passwordStrengthEnabled' => false,
            'tagInputEnabled'         => false,
            'activeFolder'            => $item?->getFolder()?->getId(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function share(bool $picker = true, bool $withGrants = true, ?VaultPageFactory $factory = null): array
    {
        $factory ??= self::factory();
        $routes  = self::routes();
        $user    = new TestUser();
        $item    = new VaultItem(VaultItemType::Login, 'GitHub', $user, 'cipher');
        $choices = $picker ? [new VaultGranteeChoice(GranteeType::User, 'user-2', 'Ana Pérez'), new VaultGranteeChoice(GranteeType::Team, 'team-ops', 'Operations')] : [];
        $form    = self::forms()->create(VaultShareType::class, new VaultShareFormData(), ['grantee_choices' => $choices])->createView();
        $grants  = $withGrants ? [
            new VaultGrant(VaultResourceType::Item, $item->getId(), GranteeType::User, 'user-2', VaultPermission::Read, $user),
            new VaultGrant(VaultResourceType::Item, $item->getId(), GranteeType::Team, 'team-ops', VaultPermission::Write, $user),
        ] : [];
        $revoke = [];
        foreach ($grants as $grant) {
            $revoke[$grant->getId()] = self::actionForm('vault_revoke_grant_' . $grant->getId());
        }

        return $factory->context([
            'page'          => $factory->share($form, $item->getTitle(), $routes['items']['name'], []),
            'grantsTable'   => $factory->grantsTable($grants, $revoke, $item->getId(), $routes['item_grant_revoke']['name']),
            'grants'        => $grants,
            'resourceLabel' => $item->getTitle(),
            'granteeLabels' => $picker ? ['user:user-2' => 'Ana Pérez'] : [],
            'routes'        => $routes,
        ]);
    }

    /**
     * @param 'configured'|'editable'|'one_time'|'unavailable' $mode
     *
     * @return array<string, mixed>
     */
    public static function runtimeConfig(string $mode = 'editable', ?VaultPageFactory $factory = null): array
    {
        $factory ??= self::factory();
        $form = self::forms()->create(VaultRuntimeConfigType::class, ['max_attachment_bytes' => 512_000, 'password_level' => 'medium'], [
            'action'                 => '/nowo_vault_runtime_config',
            'encryption_key_mode'    => $mode,
            'encryption_key_display' => $mode === 'one_time' ? 'REDACTED-TEST' : '',
        ])->createView();
        $updated = $mode === 'configured' ? new DateTimeImmutable('2026-10-30 08:00:00') : null;

        return $factory->context([
            'page'                  => $factory->runtimeConfig($form, $updated?->format('Y-m-d H:i:s'), $mode === 'configured' ? 'app_dashboard' : null),
            'routes'                => self::routes(),
            'dashboardRoute'        => null,
            'runtimeConfigRoute'    => 'nowo_vault_runtime_config',
            'dbUpdatedAt'           => $updated,
            'maxAttachmentBytes'    => 512_000,
            'passwordLevel'         => 'medium',
            'hasDbEncryptionKey'    => $mode === 'configured',
            'hasYamlEncryptionKey'  => false,
            'canStoreEncryptionKey' => $mode !== 'unavailable',
            'encryptionKeyDisplay'  => $mode === 'one_time' ? 'REDACTED-TEST' : null,
            'showOneTimeKeyWarning' => $mode === 'one_time',
            'form'                  => $form,
        ]);
    }

    public static function factory(?VaultPages $pages = null): VaultPageFactory
    {
        return new VaultPageFactory(self::urlGenerator(), $pages ?? new VaultPages());
    }

    /**
     * The `nowo_vault.routes` map of the default configuration (route key => path / name).
     *
     * @return array<string, array{path: string, name: string}>
     */
    public static function routes(): array
    {
        $routes = [];
        foreach (['index', 'items', 'shared', 'trash', 'item_new', 'item_edit', 'item_view', 'item_trash', 'item_restore', 'item_purge', 'folder_create', 'folder_trash', 'tag_delete', 'item_share', 'item_grant_revoke', 'folder_share', 'folder_grant_revoke', 'password_generate', 'runtime_config'] as $key) {
            $routes[$key] = ['path' => '/' . $key, 'name' => 'nowo_vault_' . $key];
        }

        return $routes;
    }

    /**
     * One CSRF-only VaultActionType per row, as the controllers build them.
     *
     * @param list<VaultFolder|VaultItem|VaultTag> $rows
     * @param array<string, string|null> $hidden
     *
     * @return array<string, FormView>
     */
    private static function actionForms(array $rows, string $tokenPrefix, array $hidden = []): array
    {
        $forms = [];
        foreach ($rows as $row) {
            $forms[$row->getId()] = self::actionForm($tokenPrefix . $row->getId(), $hidden);
        }

        return $forms;
    }

    /**
     * @param array<string, string|null> $hidden
     */
    private static function actionForm(string $tokenId, array $hidden = []): FormView
    {
        return self::forms()->createNamed('', VaultActionType::class, null, [
            'action'        => '/action',
            'csrf_token_id' => $tokenId,
            'hidden_fields' => $hidden,
        ])->createView();
    }

    /**
     * Form factory of the test kernel (FormKit vault profile, CSRF with a session, Doctrine for the folder choices).
     */
    private static function forms(): FormFactoryInterface
    {
        if (!self::$container instanceof ContainerInterface) {
            $kernel = new Kernel('test', true);
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            assert($container instanceof ContainerInterface);
            $request = new Request();
            $request->setSession(new Session(new MockArraySessionStorage()));
            $stack = $container->get('request_stack');
            assert($stack instanceof RequestStack);
            $stack->push($request);
            self::$container = $container;
        }

        $forms = self::$container->get('form.factory');
        assert($forms instanceof FormFactoryInterface);

        return $forms;
    }

    /**
     * Folder known to the entity manager (persisted, never flushed): the item form's EntityType reads its id.
     */
    private static function managed(VaultFolder $folder): VaultFolder
    {
        self::forms();
        $doctrine = self::$container?->get('doctrine');
        assert($doctrine instanceof ManagerRegistry);
        $doctrine->getManager()->persist($folder);

        return $folder;
    }

    private static function urlGenerator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/' . $name . ($parameters === [] ? '' : '?' . http_build_query($parameters));
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }
        };
    }
}
