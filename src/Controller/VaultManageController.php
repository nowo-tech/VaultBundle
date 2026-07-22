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
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Event\VaultGrantListQueryEvent;
use Nowo\VaultBundle\Form\VaultItemFormType;
use Nowo\VaultBundle\Form\VaultShareType;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function base64_encode;
use function in_array;
use function is_string;

#[IsGranted('IS_AUTHENTICATED')]
final class VaultManageController extends AbstractController
{
    use VaultCsrfTrait;

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
    ) {
    }

    public function home(): Response
    {
        $this->denyUnlessFeature('list');

        return $this->render($this->templates()['home'], [
            'layout'                  => $this->templates()['layout'],
            'routes'                  => $this->routes(),
            'dashboardRoute'          => $this->dashboardRoute(),
            'itemTypes'               => VaultItemType::cases(),
            'configStorageEnabled'    => $this->configStorage['enabled'],
            'configStorageAdminRoute' => $this->configStorageAdminRoute(),
        ]);
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

    public function items(Request $request): Response
    {
        $this->denyUnlessFeature('list');

        /** @var UserInterface $user */
        $user        = $this->getUser();
        $folderId    = $request->query->getString('folder') ?: null;
        $searchQuery = trim($request->query->getString('q'));
        $tagId       = $request->query->getString('tag') ?: null;
        $result      = $this->itemLister->list(
            $user,
            $folderId,
            searchQuery: $searchQuery !== '' ? $searchQuery : null,
            tagId: $tagId,
        );
        $folders          = $this->folderService->listForCreator($user);
        $tags             = $this->tagService->listForCreator($user);
        $folderItemCounts = [];
        foreach ($folders as $folder) {
            $folderItemCounts[$folder->getId()] = $this->itemRepository->countActiveByFolder($folder->getId());
        }
        $itemIds     = array_map(static fn (VaultItem $item): string => $item->getId(), $result['items']);
        $grantCounts = $this->grantService->countForResources(VaultResourceType::Item, $itemIds);

        return $this->render($this->templates()['items'], [
            'layout'           => $this->templates()['layout'],
            'items'            => $result['items'],
            'total'            => $result['total'],
            'readOnlyMap'      => $this->accessGuard->resolveReadOnlyMap($user, $result['items']),
            'grantCounts'      => $grantCounts,
            'folders'          => $folders,
            'tags'             => $tags,
            'folderItemCounts' => $folderItemCounts,
            'activeFolder'     => $folderId,
            'activeTag'        => $tagId,
            'searchQuery'      => $searchQuery,
            'routes'           => $this->routes(),
            'dashboardRoute'   => $this->dashboardRoute(),
            'itemTypes'        => VaultItemType::cases(),
        ]);
    }

    public function shared(): Response
    {
        $this->denyUnlessFeature('list');

        /** @var UserInterface $user */
        $user   = $this->getUser();
        $result = $this->itemLister->list($user, sharedOnly: true);

        return $this->render($this->templates()['shared'], [
            'layout'         => $this->templates()['layout'],
            'items'          => $result['items'],
            'readOnlyMap'    => $this->accessGuard->resolveReadOnlyMap($user, $result['items']),
            'routes'         => $this->routes(),
            'dashboardRoute' => $this->dashboardRoute(),
        ]);
    }

    public function trash(): Response
    {
        $this->denyUnlessFeature('list');

        /** @var UserInterface $user */
        $user   = $this->getUser();
        $result = $this->itemLister->list($user, trashOnly: true);

        return $this->render($this->templates()['trash'], [
            'layout'         => $this->templates()['layout'],
            'items'          => $result['items'],
            'routes'         => $this->routes(),
            'dashboardRoute' => $this->dashboardRoute(),
        ]);
    }

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

        return $this->render($this->templates()['item_form'], [
            'layout'                  => $this->templates()['layout'],
            'form'                    => $form,
            'itemType'                => $itemType,
            'item'                    => null,
            'folders'                 => $folders,
            'routes'                  => $this->routes(),
            'dashboardRoute'          => $this->dashboardRoute(),
            'passwordStrengthEnabled' => $this->passwordStrengthEnabled,
            'tagInputEnabled'         => $this->tagInputEnabled,
            'activeFolder'            => $data->folder?->getId(),
        ]);
    }

    public function editItem(Request $request, string $id): Response
    {
        return $this->renderItemForm($request, $id, viewMode: false);
    }

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
            $this->itemUpdater->update($item, $data->title, $payload, $data->folder);
            $this->tagService->syncItemTags($item, $user, $data->tags);
            $this->addFlash('success', $this->translator->trans('vault.flash.item_updated', [], 'NowoVaultBundle'));

            return $this->redirectToItems($data->folder);
        }

        return $this->render($this->templates()['item_form'], [
            'layout'                  => $this->templates()['layout'],
            'form'                    => $form,
            'itemType'                => $item->getItemType(),
            'item'                    => $item,
            'readOnly'                => $readOnly,
            'viewMode'                => $viewMode,
            'folders'                 => $folders,
            'routes'                  => $this->routes(),
            'dashboardRoute'          => $this->dashboardRoute(),
            'passwordStrengthEnabled' => $this->passwordStrengthEnabled,
            'tagInputEnabled'         => $this->tagInputEnabled,
            'activeFolder'            => $item->getFolder()?->getId(),
        ]);
    }

    public function revokeItemGrant(Request $request, string $id, string $grantId): RedirectResponse
    {
        $this->denyUnlessFeature('access');
        $this->denyUnlessValidCsrf('vault_revoke_grant_' . $grantId, $request);

        $item = $this->findItemOr404($id);
        $this->denyUnlessItemAccess($item, VaultAccessAction::Share);

        $grant = $this->grantService->findById($grantId);
        if (!$grant instanceof VaultGrant || $grant->getResourceType() !== VaultResourceType::Item || $grant->getResourceId() !== $item->getId()) {
            throw $this->createNotFoundException('Grant not found.');
        }

        $this->grantService->revoke($grant);
        $this->addFlash('success', $this->translator->trans('vault.flash.grant_revoked', [], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes()['item_share']['name'], ['id' => $item->getId()]);
    }

    public function revokeFolderGrant(Request $request, string $id, string $grantId): RedirectResponse
    {
        $this->denyUnlessFeature('access');
        $this->denyUnlessValidCsrf('vault_revoke_grant_' . $grantId, $request);

        $folder = $this->findFolderOr404($id);
        $this->denyUnlessFolderAccess($folder, VaultAccessAction::Share);

        $grant = $this->grantService->findById($grantId);
        if (!$grant instanceof VaultGrant || $grant->getResourceType() !== VaultResourceType::Folder || $grant->getResourceId() !== $folder->getId()) {
            throw $this->createNotFoundException('Grant not found.');
        }

        $this->grantService->revoke($grant);
        $this->addFlash('success', $this->translator->trans('vault.flash.grant_revoked', [], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes()['items']['name'], ['folder' => $folder->getId()]);
    }

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

    public function restoreItem(Request $request, string $id): RedirectResponse
    {
        $this->denyUnlessFeature('revoke');
        $this->denyUnlessValidCsrf('vault_restore_' . $id, $request);

        $item = $this->findItemOr404($id);
        $this->denyUnlessItemAccess($item, VaultAccessAction::Restore);
        $this->trashService->restoreItem($item);
        $this->addFlash('success', $this->translator->trans('vault.flash.item_restored', [], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes()['trash']['name']);
    }

    public function purgeItem(Request $request, string $id): RedirectResponse
    {
        $this->denyUnlessFeature('revoke');
        $this->denyUnlessValidCsrf('vault_purge_' . $id, $request);

        $item = $this->findItemOr404($id);
        $this->denyUnlessItemAccess($item, VaultAccessAction::Purge);
        $this->trashService->purgeItem($item);
        $this->addFlash('success', $this->translator->trans('vault.flash.item_purged', [], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes()['trash']['name']);
    }

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

    private function findItemOr404(string $id): VaultItem
    {
        $item = $this->itemRepository->findById($id);

        return $item ?? throw $this->createNotFoundException('Vault item not found.');
    }

    private function findFolderOr404(string $id): VaultFolder
    {
        $folder = $this->folderService->find($id);

        return $folder ?? throw $this->createNotFoundException('Vault folder not found.');
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
        /** @var UserInterface $user */
        $user = $this->getUser();
        if (!$this->accessGuard->canAccessFolder($user, $folder, $action)) {
            throw $this->createAccessDeniedException();
        }
    }

    private function denyUnlessItemAccess(VaultItem $item, VaultAccessAction $action): void
    {
        /** @var UserInterface $user */
        $user = $this->getUser();
        if (!$this->accessGuard->canAccessItem($user, $item, $action)) {
            throw $this->createAccessDeniedException();
        }
    }

    private function denyUnlessFeature(string $feature): void
    {
        $user = $this->getUser();

        $allowed = match ($feature) {
            'access' => $this->accessChecker->canAccess($user),
            'create' => $this->accessChecker->canCreate($user),
            'list'   => $this->accessChecker->canList($user),
            'revoke' => $this->accessChecker->canRevoke($user),
            default  => false,
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
        if (!$grantList->isGranteeAllowed($data->granteeType, $data->granteeId)) {
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
        return $this->render($this->templates()['share'], [
            'layout'           => $this->templates()['layout'],
            'form'             => $form,
            'resourceLabel'    => $resourceLabel,
            'grants'           => $grants,
            'resourceId'       => $resourceId,
            'grantRevokeRoute' => $grantRevokeRoute,
            'granteeLabels'    => $grantList->getLabelMap(),
            'routes'           => $this->routes(),
            'dashboardRoute'   => $this->dashboardRoute(),
            'backRoute'        => $backRoute,
            'backParams'       => $backParams,
        ]);
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
