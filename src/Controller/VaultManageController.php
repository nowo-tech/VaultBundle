<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Controller;

use InvalidArgumentException;
use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Dto\PasswordGeneratorOptions;
use Nowo\VaultBundle\Dto\VaultItemFormData;
use Nowo\VaultBundle\Dto\VaultShareFormData;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Event\VaultGrantListQueryEvent;
use Nowo\VaultBundle\Form\VaultActionType;
use Nowo\VaultBundle\Form\VaultFolderCreateType;
use Nowo\VaultBundle\Form\VaultItemFormType;
use Nowo\VaultBundle\Form\VaultItemSearchType;
use Nowo\VaultBundle\Form\VaultPasswordGeneratorType;
use Nowo\VaultBundle\Form\VaultShareType;
use Nowo\VaultBundle\Repository\VaultItemFolderCounterInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Routing\VaultRouteLoader;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Service\VaultAccessGuard;
use Nowo\VaultBundle\Service\VaultFolderService;
use Nowo\VaultBundle\Service\VaultGrantListResolver;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Service\VaultItemLister;
use Nowo\VaultBundle\Service\VaultItemUpdater;
use Nowo\VaultBundle\Service\VaultPasswordGenerator;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Service\VaultTrashService;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Ui\VaultPageFactory;
use Nowo\VaultBundle\Ui\VaultPages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function base64_encode;
use function in_array;
use function is_string;
use function max;

/**
 * Manage Web UI: item, folder, tag, sharing, trash and password generator actions.
 */
final class VaultManageController extends AbstractController
{
    use VaultCsrfTrait;
    use VaultPagesTrait;

    /**
     * Stores the access, listing, persistence, crypto and configuration collaborators of the manage UI.
     */
    public function __construct(
        private readonly VaultAccessCheckerInterface $accessChecker,
        private readonly VaultAccessGuard $accessGuard,
        private readonly VaultItemLister $itemLister,
        private readonly VaultItemCreator $itemCreator,
        private readonly VaultItemUpdater $itemUpdater,
        private readonly VaultTagService $tagService,
        private readonly VaultFolderService $folderService,
        private readonly VaultTrashService $trashService,
        private readonly VaultGrantService $grantService,
        private readonly VaultGrantListResolver $grantListResolver,
        private readonly VaultPasswordGenerator $passwordGenerator,
        private readonly VaultItemRepositoryInterface $itemRepository,
        private readonly VaultPayloadCryptographerInterface $cryptographer,
        private readonly TranslatorInterface $translator,
        private readonly VaultRuntimeConfigProvider $runtimeConfig,
        private readonly bool $passwordStrengthEnabled,
        private readonly bool $tagInputEnabled,
        /** @var array{enabled: bool, cache_pool: string} */
        private readonly array $configStorage,
        private readonly bool $allowUnauthenticated = false,
        private readonly int $perPage = 50,
        private readonly ?VaultPageFactory $pageFactory = null,
    ) {
    }

    /**
     * Renders the vault landing page (item types, password generator, runtime config link).
     */
    #[Route(path: '/tools/vault', name: 'nowo_vault_index', options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'index'], methods: ['GET'])]
    public function home(): Response
    {
        $this->denyUnlessFeature('list');

        $routes = $this->routes();

        return $this->render($this->templates()['home'], $this->pages()->context([
            'page'           => $this->pages()->home($routes, $this->configStorageAdminRoute(), $this->dashboardRoute()),
            'routes'         => $routes,
            'dashboardRoute' => $this->dashboardRoute(),
            'itemTypes'      => VaultItemType::cases(),
            'generatorForm'  => $this->createForm(VaultPasswordGeneratorType::class)->createView(),
        ]));
    }

    /**
     * Reads the 1-based `page` query parameter; anything below 1 or non-numeric falls back to page 1.
     */
    private function requestedPage(Request $request): int
    {
        return max(1, $request->query->getInt('page', 1));
    }

    private function configStorageAdminRoute(): ?string
    {
        if (!$this->configStorage['enabled']) {
            return null;
        }

        /** @var list<string> $adminRoles */
        $adminRoles = $this->runtimeConfig->get()['security']['admin_roles'];
        foreach ($adminRoles as $role) {
            if ($this->isGranted($role)) {
                $name = $this->runtimeConfig->get()['routes']['runtime_config']['name'] ?? null;

                return is_string($name) && $name !== '' ? $name : null;
            }
        }

        return null;
    }

    /**
     * Renders the paginated items list with folder / tag filters and search.
     */
    #[Route(path: '/tools/vault/items', name: 'nowo_vault_items', options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'items'], methods: ['GET'])]
    public function items(Request $request): Response
    {
        $this->denyUnlessFeature('list');

        /** @var UserInterface $user */
        $user        = $this->getUser();
        $folderId    = $request->query->getString('folder') ?: null;
        $searchQuery = trim($request->query->getString('q'));
        $tagId       = $request->query->getString('tag') ?: null;
        $page        = $this->requestedPage($request);
        $result      = $this->itemLister->list(
            $user,
            $folderId,
            searchQuery: $searchQuery !== '' ? $searchQuery : null,
            tagId: $tagId,
            page: $page,
            perPage: $searchQuery !== '' ? null : $this->perPage,
        );
        $folders   = $this->folderService->listForCreator($user);
        $tags      = $this->tagService->listForCreator($user);
        $folderIds = array_map(static fn (VaultFolder $folder): string => $folder->getId(), $folders);
        if ($this->itemRepository instanceof VaultItemFolderCounterInterface) {
            // One GROUP BY instead of one COUNT per folder.
            $folderItemCounts = $this->itemRepository->countActiveByFolders($folderIds);
        } else {
            $folderItemCounts = [];
            foreach ($folderIds as $folderId) {
                $folderItemCounts[$folderId] = $this->itemRepository->countActiveByFolder($folderId);
            }
        }
        $itemIds     = array_map(static fn (VaultItem $item): string => $item->getId(), $result['items']);
        $grantCounts = $this->grantService->countForResources(VaultResourceType::Item, $itemIds);
        $routes      = $this->routes();
        $readOnlyMap = $this->accessGuard->resolveReadOnlyMap($user, $result['items']);
        $pages       = $this->pages();
        $searchForm  = $this->createForm(VaultItemSearchType::class, ['q' => $searchQuery], [
            'action'  => $this->generateUrl($routes['items']['name']),
            'filters' => ['folder' => $folderId, 'tag' => $tagId],
        ])->createView();

        return $this->render($this->templates()['items'], $pages->context([
            'page' => $pages->items(
                $routes,
                $result['items'],
                $readOnlyMap,
                $this->actionForms($result['items'], 'item_trash', 'vault_trash_'),
                $searchForm,
                $searchQuery !== '' ? null : $pages->pagination($routes['items']['name'], $page, $this->perPage, $result['total'], ['folder' => $folderId, 'tag' => $tagId]),
                $folderId,
                $this->dashboardRoute(),
            ),
            'items'         => $result['items'],
            'total'         => $result['total'],
            'readOnlyMap'   => $readOnlyMap,
            'grantCounts'   => $grantCounts,
            'folders'       => $folders,
            'tags'          => $tags,
            'folderActions' => $pages->folderActions($routes, $folders, $this->actionForms($folders, 'folder_trash', 'vault_folder_trash_'), $folderItemCounts),
            'tagActions'    => $pages->tagActions($routes, $tags, $this->actionForms($tags, 'tag_delete', 'vault_tag_delete_', [
                'folder' => $folderId,
                'q'      => $searchQuery,
                'tag'    => $tagId,
            ])),
            'activeFolder' => $folderId,
            'activeTag'    => $tagId,
            'searchQuery'  => $searchQuery,
            'routes'       => $routes,
            'folderForm'   => $this->createForm(VaultFolderCreateType::class, null, [
                'action' => $this->generateUrl($routes['folder_create']['name']),
            ])->createView(),
        ]));
    }

    /**
     * Renders the paginated list of items shared with the user.
     */
    #[Route(path: '/tools/vault/shared', name: 'nowo_vault_shared', options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'shared'], methods: ['GET'])]
    public function shared(Request $request): Response
    {
        $this->denyUnlessFeature('list');

        /** @var UserInterface $user */
        $user        = $this->getUser();
        $page        = $this->requestedPage($request);
        $result      = $this->itemLister->list($user, sharedOnly: true, page: $page, perPage: $this->perPage);
        $routes      = $this->routes();
        $readOnlyMap = $this->accessGuard->resolveReadOnlyMap($user, $result['items']);
        $pages       = $this->pages();

        return $this->render($this->templates()['shared'], $pages->context([
            'page' => $pages->shared(
                $routes,
                $result['items'],
                $readOnlyMap,
                $this->actionForms($result['items'], 'item_trash', 'vault_trash_'),
                $pages->pagination($routes['shared']['name'], $page, $this->perPage, $result['total']),
                $this->dashboardRoute(),
            ),
            'items'       => $result['items'],
            'readOnlyMap' => $readOnlyMap,
            'routes'      => $routes,
        ]));
    }

    /**
     * Renders the paginated trash with restore and purge forms.
     */
    #[Route(path: '/tools/vault/trash', name: 'nowo_vault_trash', options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'trash'], methods: ['GET'])]
    public function trash(Request $request): Response
    {
        $this->denyUnlessFeature('list');

        /** @var UserInterface $user */
        $user   = $this->getUser();
        $page   = $this->requestedPage($request);
        $result = $this->itemLister->list($user, trashOnly: true, page: $page, perPage: $this->perPage);
        $routes = $this->routes();
        $pages  = $this->pages();

        return $this->render($this->templates()['trash'], $pages->context([
            'page' => $pages->trash(
                $routes,
                $result['items'],
                $this->actionForms($result['items'], 'item_restore', 'vault_restore_'),
                $this->actionForms($result['items'], 'item_purge', 'vault_purge_'),
                $pages->pagination($routes['trash']['name'], $page, $this->perPage, $result['total']),
                $this->dashboardRoute(),
            ),
            'items'  => $result['items'],
            'routes' => $routes,
        ]));
    }

    /**
     * Renders and handles the creation form of a new item of the given type.
     */
    #[Route(path: '/tools/vault/items/new/{type}', name: 'nowo_vault_item_new', requirements: ['type' => '[a-z_]+'], options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'item_new'], methods: ['GET', 'POST'])]
    public function newItem(Request $request, string $type): Response
    {
        $this->denyUnlessFeature('create');

        $itemType = VaultItemType::tryFrom($type);
        if ($itemType === null) {
            throw $this->createNotFoundException('Unknown vault item type.');
        }

        /** @var UserInterface $user */
        $user         = $this->getUser();
        $folders      = $this->folderService->listForCreator($user);
        $data         = new VaultItemFormData($itemType);
        $data->folder = $this->resolveFolderForUser(
            $user,
            $request->query->getString('folder') ?: null,
        );
        $form = $this->createForm(VaultItemFormType::class, $data, ['folders' => $folders]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $payload = $this->mergeAttachmentIntoPayload($data->toPayload(), $itemType, $request);
            $item    = $this->itemCreator->create(
                $itemType,
                $data->title,
                $user,
                $payload,
                $data->folder,
            );
            $this->tagService->syncItemTags($item, $user, $data->tags);

            $this->addFlash('success', $this->translator->trans('vault.flash.item_created', [], 'NowoVaultBundle'));

            return $this->redirectToItems($data->folder);
        }

        return $this->renderItemPage($form, $itemType, null, false, false, $folders, $data->folder?->getId());
    }

    /**
     * Renders and handles the edit form of an item (read-only when the user may only view it).
     */
    #[Route(path: '/tools/vault/items/{id}/edit', name: 'nowo_vault_item_edit', requirements: ['id' => VaultRouteLoader::UUID_REQUIREMENT], options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'item_edit'], methods: ['GET', 'POST'])]
    public function editItem(Request $request, string $id): Response
    {
        return $this->renderItemForm($request, $id, viewMode: false);
    }

    /**
     * Renders an item in read-only view mode.
     */
    #[Route(path: '/tools/vault/items/{id}', name: 'nowo_vault_item_view', requirements: ['id' => VaultRouteLoader::UUID_REQUIREMENT], options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'item_view'], methods: ['GET'])]
    public function viewItem(string $id): Response
    {
        return $this->renderItemForm(null, $id, viewMode: true);
    }

    private function renderItemForm(?Request $request, string $id, bool $viewMode): Response
    {
        $this->denyUnlessFeature('access');

        $item = $this->findItemOr404($id);

        /** @var UserInterface $user */
        $user = $this->getUser();

        if ($viewMode) {
            $this->denyUnlessItemAccess($item, VaultAccessAction::View);
            $readOnly = true;
        } else {
            $readOnly = $this->accessGuard->isItemReadOnly($user, $item);

            if ($readOnly) {
                $this->denyUnlessItemAccess($item, VaultAccessAction::View);
            } else {
                $this->denyUnlessItemAccess($item, VaultAccessAction::Edit);
            }
        }

        $folders      = $this->folderService->listForCreator($user);
        $payload      = $this->cryptographer->decrypt($item->getCiphertext());
        $data         = VaultItemFormData::fromPayload($item->getItemType(), $payload);
        $data->title  = $item->getTitle();
        $data->folder = $item->getFolder();
        $data->tags   = $item->getTagNames();

        $form = $this->createForm(VaultItemFormType::class, $data, ['folders' => $folders]);

        if (!$viewMode && $request instanceof Request) {
            $form->handleRequest($request);
        }

        if (!$viewMode && $form->isSubmitted() && $readOnly) {
            throw $this->createAccessDeniedException();
        }

        if (!$viewMode && $request instanceof Request && $form->isSubmitted() && $form->isValid()) {
            $payload = $this->mergeAttachmentIntoPayload($data->toPayload(), $item->getItemType(), $request, $payload);
            // Folder and tags are the creator's organisation: a grantee with Write may change the content only (a folder
            // move was a privilege escalation path, SEC-004 M1; the editor's folders / tags replaced the owner's, L1/L2).
            $isCreator = UserIdResolver::isSameUser($item->getCreator(), $user);
            $this->itemUpdater->update($item, $data->title, $payload, $isCreator ? $data->folder : $item->getFolder());
            if ($isCreator) {
                $this->tagService->syncItemTags($item, $user, $data->tags);
            }
            $this->addFlash('success', $this->translator->trans('vault.flash.item_updated', [], 'NowoVaultBundle'));

            return $this->redirectToItems($isCreator ? $data->folder : null);
        }

        return $this->renderItemPage($form, $item->getItemType(), $item, $readOnly, $viewMode, $folders, $item->getFolder()?->getId());
    }

    /**
     * Renders the item form page (create, edit, read-only view).
     *
     * @param FormInterface<VaultItemFormData> $form
     * @param list<VaultFolder> $folders
     */
    private function renderItemPage(FormInterface $form, VaultItemType $itemType, ?VaultItem $item, bool $readOnly, bool $viewMode, array $folders, ?string $activeFolder): Response
    {
        $routes = $this->routes();
        $pages  = $this->pages();
        $view   = $form->createView();

        return $this->renderForm($form, $this->templates()['item_form'], $pages->context([
            'page'                    => $pages->itemForm($routes, $view, $itemType, $item, $readOnly, $activeFolder, $this->tagInputEnabled ? [VaultPages::ITEM_FORM_THEME] : []),
            'form'                    => $view,
            'itemType'                => $itemType,
            'item'                    => $item,
            'readOnly'                => $readOnly,
            'viewMode'                => $viewMode,
            'folders'                 => $folders,
            'routes'                  => $routes,
            'passwordStrengthEnabled' => $this->passwordStrengthEnabled,
            'tagInputEnabled'         => $this->tagInputEnabled,
            'activeFolder'            => $activeFolder,
        ]));
    }

    /**
     * Revokes one grant of an item (CSRF protected).
     */
    #[Route(path: '/tools/vault/items/{id}/grants/{grantId}/revoke', name: 'nowo_vault_item_grant_revoke', requirements: ['id' => VaultRouteLoader::UUID_REQUIREMENT, 'grantId' => VaultRouteLoader::UUID_REQUIREMENT], options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'item_grant_revoke'], methods: ['POST'])]
    public function revokeItemGrant(Request $request, string $id, string $grantId): RedirectResponse
    {
        $this->denyUnlessFeature('access');
        $this->denyUnlessValidCsrf('vault_revoke_grant_' . $grantId, $request);

        $item = $this->findItemOr404($id, allowTrashed: true);
        $this->denyUnlessItemAccess($item, VaultAccessAction::Share);

        $grant = $this->grantService->findById($grantId);
        if (!$grant instanceof VaultGrant || $grant->getResourceType() !== VaultResourceType::Item || $grant->getResourceId() !== $item->getId()) {
            throw $this->createNotFoundException('Grant not found.');
        }

        $this->grantService->revoke($grant);
        $this->addFlash('success', $this->translator->trans('vault.flash.grant_revoked', [], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes()['item_share']['name'], ['id' => $item->getId()]);
    }

    /**
     * Revokes one grant of a folder (CSRF protected).
     */
    #[Route(path: '/tools/vault/folders/{id}/grants/{grantId}/revoke', name: 'nowo_vault_folder_grant_revoke', requirements: ['id' => VaultRouteLoader::UUID_REQUIREMENT, 'grantId' => VaultRouteLoader::UUID_REQUIREMENT], options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'folder_grant_revoke'], methods: ['POST'])]
    public function revokeFolderGrant(Request $request, string $id, string $grantId): RedirectResponse
    {
        $this->denyUnlessFeature('access');
        $this->denyUnlessValidCsrf('vault_revoke_grant_' . $grantId, $request);

        $folder = $this->findFolderOr404($id, allowTrashed: true);
        $this->denyUnlessFolderAccess($folder, VaultAccessAction::Share);

        $grant = $this->grantService->findById($grantId);
        if (!$grant instanceof VaultGrant || $grant->getResourceType() !== VaultResourceType::Folder || $grant->getResourceId() !== $folder->getId()) {
            throw $this->createNotFoundException('Grant not found.');
        }

        $this->grantService->revoke($grant);
        $this->addFlash('success', $this->translator->trans('vault.flash.grant_revoked', [], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes()['items']['name'], ['folder' => $folder->getId()]);
    }

    /**
     * Moves an item to the trash (CSRF protected).
     */
    #[Route(path: '/tools/vault/items/{id}/trash', name: 'nowo_vault_item_trash', requirements: ['id' => VaultRouteLoader::UUID_REQUIREMENT], options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'item_trash'], methods: ['POST'])]
    public function trashItem(Request $request, string $id): RedirectResponse
    {
        $this->denyUnlessFeature('revoke');
        $this->denyUnlessValidCsrf('vault_trash_' . $id, $request);

        $item = $this->findItemOr404($id);
        $this->denyUnlessItemAccess($item, VaultAccessAction::Delete);
        $this->trashService->moveItemToTrash($item);
        $this->addFlash('success', $this->translator->trans('vault.flash.item_trashed', [], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes()['items']['name']);
    }

    /**
     * Restores a trashed item (CSRF protected).
     */
    #[Route(path: '/tools/vault/items/{id}/restore', name: 'nowo_vault_item_restore', requirements: ['id' => VaultRouteLoader::UUID_REQUIREMENT], options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'item_restore'], methods: ['POST'])]
    public function restoreItem(Request $request, string $id): RedirectResponse
    {
        $this->denyUnlessFeature('revoke');
        $this->denyUnlessValidCsrf('vault_restore_' . $id, $request);

        $item = $this->findItemOr404($id, allowTrashed: true);
        $this->denyUnlessItemAccess($item, VaultAccessAction::Restore);
        $this->trashService->restoreItem($item);
        $this->addFlash('success', $this->translator->trans('vault.flash.item_restored', [], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes()['trash']['name']);
    }

    /**
     * Permanently deletes a trashed item (CSRF protected).
     */
    #[Route(path: '/tools/vault/items/{id}/purge', name: 'nowo_vault_item_purge', requirements: ['id' => VaultRouteLoader::UUID_REQUIREMENT], options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'item_purge'], methods: ['POST'])]
    public function purgeItem(Request $request, string $id): RedirectResponse
    {
        $this->denyUnlessFeature('revoke');
        $this->denyUnlessValidCsrf('vault_purge_' . $id, $request);

        $item = $this->findItemOr404($id, allowTrashed: true);
        $this->denyUnlessItemAccess($item, VaultAccessAction::Purge);
        $this->trashService->purgeItem($item);
        $this->addFlash('success', $this->translator->trans('vault.flash.item_purged', [], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes()['trash']['name']);
    }

    /**
     * Creates a folder for the current user (CSRF protected).
     */
    #[Route(path: '/tools/vault/folders', name: 'nowo_vault_folder_create', options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'folder_create'], methods: ['POST'])]
    public function createFolder(Request $request): RedirectResponse
    {
        $this->denyUnlessFeature('create');
        $this->denyUnlessValidCsrf('vault_folder_create', $request);

        $name = trim($request->request->getString('name'));
        if ($name === '') {
            $this->addFlash('error', $this->translator->trans('vault.flash.folder_name_required', [], 'NowoVaultBundle'));

            return $this->redirectToRoute($this->routes()['items']['name']);
        }

        /** @var UserInterface $user */
        $user = $this->getUser();
        $this->folderService->create($name, $user);
        $this->addFlash('success', $this->translator->trans('vault.flash.folder_created', [], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes()['items']['name']);
    }

    /**
     * Renders and handles the share form of an item.
     */
    #[Route(path: '/tools/vault/items/{id}/share', name: 'nowo_vault_item_share', requirements: ['id' => VaultRouteLoader::UUID_REQUIREMENT], options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'item_share'], methods: ['GET', 'POST'])]
    public function shareItem(Request $request, string $id): Response
    {
        $this->denyUnlessFeature('access');

        $item = $this->findItemOr404($id);
        $this->denyUnlessItemAccess($item, VaultAccessAction::Share);

        /** @var UserInterface $user */
        $user      = $this->getUser();
        $grantList = $this->grantListResolver->resolveForItem($user, $item);
        $data      = new VaultShareFormData();
        $form      = $this->createShareForm($grantList, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->applyShareGrant($grantList, $data, $user, VaultResourceType::Item, $item->getId())) {
            return $this->redirectToRoute($this->routes()['item_share']['name'], ['id' => $item->getId()]);
        }

        $backParams = $item->getFolder() instanceof VaultFolder ? ['folder' => $item->getFolder()->getId()] : [];

        return $this->renderSharePage(
            form: $form,
            resourceLabel: $item->getTitle(),
            grants: $this->grantService->listForResource(VaultResourceType::Item, $item->getId()),
            resourceId: $item->getId(),
            grantRevokeRoute: $this->routes()['item_grant_revoke']['name'],
            grantList: $grantList,
            backRoute: $this->routes()['items']['name'],
            backParams: $backParams,
        );
    }

    /**
     * Renders and handles the share form of a folder.
     */
    #[Route(path: '/tools/vault/folders/{id}/share', name: 'nowo_vault_folder_share', requirements: ['id' => VaultRouteLoader::UUID_REQUIREMENT], options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'folder_share'], methods: ['GET', 'POST'])]
    public function shareFolder(Request $request, string $id): Response
    {
        $this->denyUnlessFeature('access');

        $folder = $this->findFolderOr404($id);
        $this->denyUnlessFolderAccess($folder, VaultAccessAction::Share);

        /** @var UserInterface $user */
        $user      = $this->getUser();
        $grantList = $this->grantListResolver->resolveForFolder($user, $folder);
        $data      = new VaultShareFormData();
        $form      = $this->createShareForm($grantList, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->applyShareGrant($grantList, $data, $user, VaultResourceType::Folder, $folder->getId())) {
            return $this->redirectToRoute($this->routes()['items']['name'], ['folder' => $folder->getId()]);
        }

        return $this->renderSharePage(
            form: $form,
            resourceLabel: $folder->getName(),
            grants: $this->grantService->listForResource(VaultResourceType::Folder, $folder->getId()),
            resourceId: $folder->getId(),
            grantRevokeRoute: $this->routes()['folder_grant_revoke']['name'],
            grantList: $grantList,
            backRoute: $this->routes()['items']['name'],
            backParams: ['folder' => $folder->getId()],
        );
    }

    /**
     * Moves a folder to the trash and detaches its items (CSRF protected).
     */
    #[Route(path: '/tools/vault/folders/{id}/trash', name: 'nowo_vault_folder_trash', requirements: ['id' => VaultRouteLoader::UUID_REQUIREMENT], options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'folder_trash'], methods: ['POST'])]
    public function trashFolder(Request $request, string $id): RedirectResponse
    {
        $this->denyUnlessFeature('revoke');
        $this->denyUnlessValidCsrf('vault_folder_trash_' . $id, $request);

        $folder = $this->findFolderOr404($id);
        $this->denyUnlessFolderAccess($folder, VaultAccessAction::Delete);
        $detached = $this->trashService->moveFolderToTrash($folder);
        $flashKey = $detached > 0 ? 'vault.flash.folder_trashed_items_detached' : 'vault.flash.folder_trashed';
        $this->addFlash('success', $this->translator->trans($flashKey, ['%count%' => $detached], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes()['items']['name']);
    }

    /**
     * Deletes one of the user's tags (CSRF protected).
     */
    #[Route(path: '/tools/vault/tags/{id}/delete', name: 'nowo_vault_tag_delete', requirements: ['id' => VaultRouteLoader::UUID_REQUIREMENT], options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'tag_delete'], methods: ['POST'])]
    public function deleteTag(Request $request, string $id): RedirectResponse
    {
        $this->denyUnlessFeature('list');

        $this->denyUnlessValidCsrf('vault_tag_delete_' . $id, $request);

        /** @var UserInterface $user */
        $user = $this->getUser();

        try {
            $this->tagService->deleteForCreator($user, $id);
        } catch (InvalidArgumentException) {
            throw $this->createNotFoundException('Tag not found.');
        }

        $this->addFlash('success', $this->translator->trans('vault.flash.tag_deleted', [], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes()['items']['name'], $this->itemsListRedirectParams($request, $id));
    }

    /**
     * Generates a password with the submitted options and returns it as JSON (CSRF protected).
     */
    #[Route(path: '/tools/vault/password/generate', name: 'nowo_vault_password_generate', options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'password_generate'], methods: ['POST'])]
    public function generatePassword(Request $request): JsonResponse
    {
        $this->denyUnlessFeature('access');
        $this->denyUnlessValidCsrf('vault_password_generate', $request);

        /** @var array<string, mixed> $body */
        $body     = json_decode($request->getContent(), true) ?? $request->request->all();
        $options  = PasswordGeneratorOptions::fromArray($body);
        $password = $this->passwordGenerator->generate($options);

        return new JsonResponse([
            'password' => $password,
            'strength' => $this->passwordGenerator->estimateStrength($password),
        ]);
    }

    /**
     * @param bool $allowTrashed only restore / purge / grant revocation act on trashed items; every other action answers
     *                           404 for them (a trashed item must not stay viewable, editable or shareable by id)
     */
    private function findItemOr404(string $id, bool $allowTrashed = false): VaultItem
    {
        $item = $this->itemRepository->findById($id);
        if (!$item instanceof VaultItem || (!$allowTrashed && $item->isDeleted())) {
            throw $this->createNotFoundException('Vault item not found.');
        }

        return $item;
    }

    /**
     * @param bool $allowTrashed only grant revocation acts on trashed folders
     */
    private function findFolderOr404(string $id, bool $allowTrashed = false): VaultFolder
    {
        $folder = $this->folderService->find($id);
        if (!$folder instanceof VaultFolder || (!$allowTrashed && $folder->isDeleted())) {
            throw $this->createNotFoundException('Vault folder not found.');
        }

        return $folder;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $existing
     *
     * @return array<string, mixed>
     */
    private function mergeAttachmentIntoPayload(
        array $payload,
        VaultItemType $type,
        Request $request,
        array $existing = [],
    ): array {
        if (!in_array($type, VaultItemType::documentTypes(), true)) {
            return $payload;
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('attachment');
        if (!$file instanceof UploadedFile) {
            if ($existing !== []) {
                $payload['attachmentName']    = $existing['attachmentName'] ?? null;
                $payload['attachmentContent'] = $existing['attachmentContent'] ?? null;
            }

            return $payload;
        }

        if ($file->getSize() > $this->maxAttachmentBytes()) {
            throw $this->createAccessDeniedException('Attachment too large.');
        }

        $content                      = $file->getContent();
        $payload['attachmentName']    = $file->getClientOriginalName();
        $payload['attachmentContent'] = base64_encode($content);

        return $payload;
    }

    private function denyUnlessFolderAccess(VaultFolder $folder, VaultAccessAction $action): void
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface || !$this->accessGuard->canAccessFolder($user, $folder, $action)) {
            throw $this->createAccessDeniedException();
        }
    }

    private function denyUnlessItemAccess(VaultItem $item, VaultAccessAction $action): void
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface || !$this->accessGuard->canAccessItem($user, $item, $action)) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * Throws AccessDenied unless the access checker grants the feature to the current user.
     *
     * @param 'access'|'create'|'list'|'revoke' $feature
     */
    private function denyUnlessFeature(string $feature): void
    {
        if ($this->allowUnauthenticated) {
            return;
        }

        $user = $this->getUser();

        $allowed = match ($feature) {
            'access' => $this->accessChecker->canAccess($user instanceof UserInterface ? $user : null),
            'create' => $this->accessChecker->canCreate($user instanceof UserInterface ? $user : null),
            'list'   => $this->accessChecker->canList($user instanceof UserInterface ? $user : null),
            'revoke' => $this->accessChecker->canRevoke($user instanceof UserInterface ? $user : null),
            // No default arm: an unknown feature name is a programming error (UnhandledMatchError), never an allow.
        };

        if (!$allowed) {
            throw $this->createAccessDeniedException();
        }
    }

    private function redirectToItems(?VaultFolder $folder): RedirectResponse
    {
        return $this->redirectToRoute(
            $this->routes()['items']['name'],
            $folder instanceof VaultFolder ? ['folder' => $folder->getId()] : [],
        );
    }

    /**
     * @return array<string, string>
     */
    private function itemsListRedirectParams(Request $request, ?string $excludeTagId = null): array
    {
        $params = [];
        $folder = $request->request->getString('folder') ?: $request->query->getString('folder');
        if ($folder !== '') {
            $params['folder'] = $folder;
        }

        $searchQuery = trim($request->request->getString('q') ?: $request->query->getString('q'));
        if ($searchQuery !== '') {
            $params['q'] = $searchQuery;
        }

        $tagId = $request->request->getString('tag') ?: $request->query->getString('tag');
        if ($tagId !== '' && $tagId !== $excludeTagId) {
            $params['tag'] = $tagId;
        }

        return $params;
    }

    private function resolveFolderForUser(UserInterface $user, ?string $folderId): ?VaultFolder
    {
        if ($folderId === null || $folderId === '') {
            return null;
        }

        $folder = $this->folderService->find($folderId);
        if (!$folder instanceof VaultFolder || $folder->isDeleted()) {
            return null;
        }

        if (!UserIdResolver::isSameUser($user, $folder->getCreator())) {
            return null;
        }

        return $folder;
    }

    /**
     * @return FormInterface<VaultShareFormData>
     */
    private function createShareForm(VaultGrantListQueryEvent $grantList, ?VaultShareFormData $data = null): FormInterface
    {
        return $this->createForm(VaultShareType::class, $data ?? new VaultShareFormData(), [
            'grantee_choices' => $grantList->getGrantees(),
        ]);
    }

    private function applyShareGrant(
        VaultGrantListQueryEvent $grantList,
        VaultShareFormData $data,
        UserInterface $user,
        VaultResourceType $resourceType,
        string $resourceId,
    ): bool {
        // Sharing with yourself never adds access you should have; without a grantee list it was a way to raise your own
        // permission on a resource you only administer through another grant.
        $isSelf = $data->granteeType === GranteeType::User && $data->granteeId === (string) UserIdResolver::getId($user);
        if ($isSelf || !$grantList->isGranteeAllowed($data->granteeType, $data->granteeId)) {
            $this->addFlash('error', $this->translator->trans('vault.flash.grantee_not_allowed', [], 'NowoVaultBundle'));

            return false;
        }

        $this->grantService->grant(
            $resourceType,
            $resourceId,
            $data->granteeType,
            $data->granteeId,
            $data->permission,
            $user,
        );
        $this->addFlash('success', $this->translator->trans('vault.flash.share_created', [], 'NowoVaultBundle'));

        return true;
    }

    /**
     * @param list<VaultGrant> $grants
     * @param array<string, mixed> $backParams
     * @param FormInterface<VaultShareFormData> $form
     */
    private function renderSharePage(
        FormInterface $form,
        string $resourceLabel,
        array $grants,
        string $resourceId,
        string $grantRevokeRoute,
        VaultGrantListQueryEvent $grantList,
        string $backRoute,
        array $backParams,
    ): Response {
        $pages = $this->pages();
        $view  = $form->createView();

        return $this->renderForm($form, $this->templates()['share'], $pages->context([
            'page'          => $pages->share($view, $resourceLabel, $backRoute, $backParams),
            'grantsTable'   => $pages->grantsTable($grants, $this->revokeGrantForms($grants, $resourceId, $grantRevokeRoute), $resourceId, $grantRevokeRoute),
            'grants'        => $grants,
            'resourceLabel' => $resourceLabel,
            'granteeLabels' => $grantList->getLabelMap(),
            'routes'        => $this->routes(),
        ]));
    }

    /**
     * One CSRF-only form per row (REQ-TWIG-005): flat {@code _token}, token id {@code <prefix><id>}.
     *
     * @param iterable<VaultFolder|VaultItem|VaultTag> $rows
     * @param array<string, string|null> $hiddenFields
     *
     * @return array<string, FormView>
     */
    private function actionForms(iterable $rows, string $routeKey, string $tokenPrefix, array $hiddenFields = []): array
    {
        $forms = [];
        $route = $this->routes()[$routeKey]['name'];
        foreach ($rows as $row) {
            $id         = $row->getId();
            $forms[$id] = $this->createForm(VaultActionType::class, null, [
                'action'        => $this->generateUrl($route, ['id' => $id]),
                'csrf_token_id' => $tokenPrefix . $id,
                'hidden_fields' => $hiddenFields,
            ])->createView();
        }

        return $forms;
    }

    /**
     * @param list<VaultGrant> $grants
     *
     * @return array<string, FormView>
     */
    private function revokeGrantForms(array $grants, string $resourceId, string $route): array
    {
        $forms = [];
        foreach ($grants as $grant) {
            $grantId         = $grant->getId();
            $forms[$grantId] = $this->createForm(VaultActionType::class, null, [
                'action'        => $this->generateUrl($route, ['id' => $resourceId, 'grantId' => $grantId]),
                'csrf_token_id' => 'vault_revoke_grant_' . $grantId,
            ])->createView();
        }

        return $forms;
    }

    /**
     * @return array<string, array{path: string, name: string}>
     */
    private function routes(): array
    {
        /* @var array<string, array{path: string, name: string}> */
        return $this->runtimeConfig->get()['routes'];
    }

    /**
     * @return array{layout: string, home: string, items: string, item_form: string, trash: string, shared: string, share: string, index?: string}
     */
    private function templates(): array
    {
        /* @var array{layout: string, home: string, items: string, item_form: string, trash: string, shared: string, share: string, index?: string} */
        return $this->runtimeConfig->get()['templates'];
    }

    /**
     * Renders a page whose form was handled: 422 when it was submitted invalid (as AbstractController::render() does
     * for a FormInterface parameter; the pages receive the FormView inside their view model).
     *
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $parameters
     */
    private function renderForm(FormInterface $form, string $template, array $parameters): Response
    {
        $status = $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render($template, $parameters, new Response(null, $status));
    }

    private function dashboardRoute(): ?string
    {
        $route = $this->runtimeConfig->get()['dashboard_route'];

        return is_string($route) ? $route : null;
    }

    private function maxAttachmentBytes(): int
    {
        return (int) $this->runtimeConfig->get()['max_attachment_bytes'];
    }
}
