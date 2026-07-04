<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Controller;

use Nowo\VaultBundle\Dto\PasswordGeneratorOptions;
use Nowo\VaultBundle\Dto\VaultItemFormData;
use Nowo\VaultBundle\Dto\VaultShareFormData;
use Nowo\VaultBundle\Entity\VaultFolder;
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
use Nowo\VaultBundle\Service\VaultTrashService;
use Nowo\VaultBundle\Support\UserIdResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function base64_encode;
use function in_array;

#[IsGranted('IS_AUTHENTICATED')]
final class VaultManageController extends AbstractController
{
    /**
     * @param array<string, array{path: string, name: string}> $routes
     * @param array{layout: string, home: string, items: string, item_form: string, trash: string, shared: string, share: string} $templates
     */
    public function __construct(
        private readonly VaultAccessCheckerInterface $accessChecker,
        private readonly VaultAccessGuard $accessGuard,
        private readonly VaultItemLister $itemLister,
        private readonly VaultItemCreator $itemCreator,
        private readonly VaultItemUpdater $itemUpdater,
        private readonly VaultFolderService $folderService,
        private readonly VaultTrashService $trashService,
        private readonly VaultGrantService $grantService,
        private readonly VaultGrantListResolver $grantListResolver,
        private readonly VaultPasswordGenerator $passwordGenerator,
        private readonly VaultItemRepositoryInterface $itemRepository,
        private readonly VaultPayloadCryptographerInterface $cryptographer,
        private readonly TranslatorInterface $translator,
        private readonly array $routes,
        private readonly array $templates,
        private readonly ?string $dashboardRoute,
        private readonly int $maxAttachmentBytes,
        private readonly bool $passwordStrengthEnabled,
    ) {
    }

    public function home(): Response
    {
        $this->denyUnlessFeature('list');

        return $this->render($this->templates['home'], [
            'layout'         => $this->templates['layout'],
            'routes'         => $this->routes,
            'dashboardRoute' => $this->dashboardRoute,
            'itemTypes'      => VaultItemType::cases(),
        ]);
    }

    public function items(Request $request): Response
    {
        $this->denyUnlessFeature('list');

        /** @var UserInterface $user */
        $user        = $this->getUser();
        $folderId    = $request->query->getString('folder') ?: null;
        $searchQuery = trim($request->query->getString('q'));
        $result      = $this->itemLister->list(
            $user,
            $folderId,
            searchQuery: $searchQuery !== '' ? $searchQuery : null,
        );
        $folders          = $this->folderService->listForCreator($user);
        $folderItemCounts = [];
        foreach ($folders as $folder) {
            $folderItemCounts[$folder->getId()] = $this->itemRepository->countActiveByFolder($folder->getId());
        }
        $itemIds     = array_map(static fn (VaultItem $item): string => $item->getId(), $result['items']);
        $grantCounts = $this->grantService->countForResources(VaultResourceType::Item, $itemIds);

        return $this->render($this->templates['items'], [
            'layout'           => $this->templates['layout'],
            'items'            => $result['items'],
            'total'            => $result['total'],
            'readOnlyMap'      => $this->accessGuard->resolveReadOnlyMap($user, $result['items']),
            'grantCounts'      => $grantCounts,
            'folders'          => $folders,
            'folderItemCounts' => $folderItemCounts,
            'activeFolder'     => $folderId,
            'searchQuery'      => $searchQuery,
            'routes'           => $this->routes,
            'dashboardRoute'   => $this->dashboardRoute,
            'itemTypes'        => VaultItemType::cases(),
        ]);
    }

    public function shared(): Response
    {
        $this->denyUnlessFeature('list');

        /** @var UserInterface $user */
        $user   = $this->getUser();
        $result = $this->itemLister->list($user, sharedOnly: true);

        return $this->render($this->templates['shared'], [
            'layout'         => $this->templates['layout'],
            'items'          => $result['items'],
            'readOnlyMap'    => $this->accessGuard->resolveReadOnlyMap($user, $result['items']),
            'routes'         => $this->routes,
            'dashboardRoute' => $this->dashboardRoute,
        ]);
    }

    public function trash(): Response
    {
        $this->denyUnlessFeature('list');

        /** @var UserInterface $user */
        $user   = $this->getUser();
        $result = $this->itemLister->list($user, trashOnly: true);

        return $this->render($this->templates['trash'], [
            'layout'         => $this->templates['layout'],
            'items'          => $result['items'],
            'routes'         => $this->routes,
            'dashboardRoute' => $this->dashboardRoute,
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
            $this->itemCreator->create(
                $itemType,
                $data->title,
                $user,
                $payload,
                $data->folder,
            );

            $this->addFlash('success', $this->translator->trans('vault.flash.item_created', [], 'NowoVaultBundle'));

            return $this->redirectToItems($data->folder);
        }

        return $this->render($this->templates['item_form'], [
            'layout'                  => $this->templates['layout'],
            'form'                    => $form,
            'itemType'                => $itemType,
            'item'                    => null,
            'folders'                 => $folders,
            'routes'                  => $this->routes,
            'dashboardRoute'          => $this->dashboardRoute,
            'passwordStrengthEnabled' => $this->passwordStrengthEnabled,
            'activeFolder'            => $data->folder?->getId(),
        ]);
    }

    public function editItem(Request $request, string $id): Response
    {
        $this->denyUnlessFeature('access');

        $item = $this->findItemOr404($id);

        /** @var UserInterface $user */
        $user     = $this->getUser();
        $readOnly = $this->accessGuard->isItemReadOnly($user, $item);

        if ($readOnly) {
            $this->denyUnlessItemAccess($item, VaultAccessAction::View);
        } else {
            $this->denyUnlessItemAccess($item, VaultAccessAction::Edit);
        }

        $folders      = $this->folderService->listForCreator($user);
        $payload      = $this->cryptographer->decrypt($item->getCiphertext());
        $data         = VaultItemFormData::fromPayload($item->getItemType(), $payload);
        $data->title  = $item->getTitle();
        $data->folder = $item->getFolder();

        $form = $this->createForm(VaultItemFormType::class, $data, ['folders' => $folders]);
        $form->handleRequest($request);

        $grants    = $this->grantService->listForResource(VaultResourceType::Item, $item->getId());
        $grantList = null;
        $shareForm = null;
        if (!$readOnly) {
            $grantList = $this->grantListResolver->resolveForItem($user, $item);
            $shareForm = $this->createShareForm($grantList);
        }

        if ($form->isSubmitted() && $readOnly) {
            throw $this->createAccessDeniedException();
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $payload = $this->mergeAttachmentIntoPayload($data->toPayload(), $item->getItemType(), $request, $payload);
            $this->itemUpdater->update($item, $data->title, $payload, $data->folder);
            $this->addFlash('success', $this->translator->trans('vault.flash.item_updated', [], 'NowoVaultBundle'));

            return $this->redirectToItems($data->folder);
        }

        return $this->render($this->templates['item_form'], [
            'layout'                  => $this->templates['layout'],
            'form'                    => $form,
            'itemType'                => $item->getItemType(),
            'item'                    => $item,
            'readOnly'                => $readOnly,
            'folders'                 => $folders,
            'grants'                  => $grants,
            'shareForm'               => $shareForm,
            'granteeLabels'           => $grantList?->getLabelMap() ?? [],
            'canManageAccess'         => !$readOnly,
            'routes'                  => $this->routes,
            'dashboardRoute'          => $this->dashboardRoute,
            'passwordStrengthEnabled' => $this->passwordStrengthEnabled,
            'activeFolder'            => $item->getFolder()?->getId(),
        ]);
    }

    public function revokeItemGrant(string $id, string $grantId): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyUnlessFeature('access');

        $item = $this->findItemOr404($id);
        $this->denyUnlessItemAccess($item, VaultAccessAction::Share);

        $grant = $this->grantService->findById($grantId);
        if (!$grant instanceof \Nowo\VaultBundle\Entity\VaultGrant || $grant->getResourceType() !== VaultResourceType::Item || $grant->getResourceId() !== $item->getId()) {
            throw $this->createNotFoundException('Grant not found.');
        }

        $this->grantService->revoke($grant);
        $this->addFlash('success', $this->translator->trans('vault.flash.grant_revoked', [], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes['item_edit']['name'], ['id' => $item->getId()]);
    }

    public function revokeFolderGrant(string $id, string $grantId): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyUnlessFeature('access');

        $folder = $this->findFolderOr404($id);
        $this->denyUnlessFolderAccess($folder, VaultAccessAction::Share);

        $grant = $this->grantService->findById($grantId);
        if (!$grant instanceof \Nowo\VaultBundle\Entity\VaultGrant || $grant->getResourceType() !== VaultResourceType::Folder || $grant->getResourceId() !== $folder->getId()) {
            throw $this->createNotFoundException('Grant not found.');
        }

        $this->grantService->revoke($grant);
        $this->addFlash('success', $this->translator->trans('vault.flash.grant_revoked', [], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes['items']['name'], ['folder' => $folder->getId()]);
    }

    public function trashItem(string $id): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyUnlessFeature('revoke');

        $item = $this->findItemOr404($id);
        $this->denyUnlessItemAccess($item, VaultAccessAction::Delete);
        $this->trashService->moveItemToTrash($item);
        $this->addFlash('success', $this->translator->trans('vault.flash.item_trashed', [], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes['items']['name']);
    }

    public function restoreItem(string $id): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyUnlessFeature('revoke');

        $item = $this->findItemOr404($id);
        $this->denyUnlessItemAccess($item, VaultAccessAction::Restore);
        $this->trashService->restoreItem($item);
        $this->addFlash('success', $this->translator->trans('vault.flash.item_restored', [], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes['trash']['name']);
    }

    public function purgeItem(string $id): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyUnlessFeature('revoke');

        $item = $this->findItemOr404($id);
        $this->denyUnlessItemAccess($item, VaultAccessAction::Purge);
        $this->trashService->purgeItem($item);
        $this->addFlash('success', $this->translator->trans('vault.flash.item_purged', [], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes['trash']['name']);
    }

    public function createFolder(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyUnlessFeature('create');

        $name = trim($request->request->getString('name'));
        if ($name === '') {
            $this->addFlash('error', $this->translator->trans('vault.flash.folder_name_required', [], 'NowoVaultBundle'));

            return $this->redirectToRoute($this->routes['items']['name']);
        }

        /** @var UserInterface $user */
        $user = $this->getUser();
        $this->folderService->create($name, $user);
        $this->addFlash('success', $this->translator->trans('vault.flash.folder_created', [], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes['items']['name']);
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
            return $this->redirectToRoute($this->routes['item_edit']['name'], ['id' => $item->getId()]);
        }

        return $this->renderSharePage(
            form: $form,
            resourceLabel: $item->getTitle(),
            grants: $this->grantService->listForResource(VaultResourceType::Item, $item->getId()),
            resourceId: $item->getId(),
            grantRevokeRoute: $this->routes['item_grant_revoke']['name'],
            grantList: $grantList,
            backRoute: $this->routes['item_edit']['name'],
            backParams: ['id' => $item->getId()],
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
            return $this->redirectToRoute($this->routes['items']['name'], ['folder' => $folder->getId()]);
        }

        return $this->renderSharePage(
            form: $form,
            resourceLabel: $folder->getName(),
            grants: $this->grantService->listForResource(VaultResourceType::Folder, $folder->getId()),
            resourceId: $folder->getId(),
            grantRevokeRoute: $this->routes['folder_grant_revoke']['name'],
            grantList: $grantList,
            backRoute: $this->routes['items']['name'],
            backParams: ['folder' => $folder->getId()],
        );
    }

    public function trashFolder(string $id): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyUnlessFeature('revoke');

        $folder = $this->findFolderOr404($id);
        $this->denyUnlessFolderAccess($folder, VaultAccessAction::Delete);
        $detached = $this->trashService->moveFolderToTrash($folder);
        $flashKey = $detached > 0 ? 'vault.flash.folder_trashed_items_detached' : 'vault.flash.folder_trashed';
        $this->addFlash('success', $this->translator->trans($flashKey, ['%count%' => $detached], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes['items']['name']);
    }

    public function generatePassword(Request $request): JsonResponse
    {
        $this->denyUnlessFeature('access');

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

        if ($file->getSize() > $this->maxAttachmentBytes) {
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

    private function redirectToItems(?VaultFolder $folder): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->redirectToRoute(
            $this->routes['items']['name'],
            $folder instanceof VaultFolder ? ['folder' => $folder->getId()] : [],
        );
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
     * @return \Symfony\Component\Form\FormInterface<VaultShareFormData>
     */
    private function createShareForm(VaultGrantListQueryEvent $grantList, ?VaultShareFormData $data = null): \Symfony\Component\Form\FormInterface
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
     * @param list<\Nowo\VaultBundle\Entity\VaultGrant> $grants
     * @param array<string, mixed> $backParams
     * @param \Symfony\Component\Form\FormInterface<VaultShareFormData> $form
     */
    private function renderSharePage(
        \Symfony\Component\Form\FormInterface $form,
        string $resourceLabel,
        array $grants,
        string $resourceId,
        string $grantRevokeRoute,
        VaultGrantListQueryEvent $grantList,
        string $backRoute,
        array $backParams,
    ): Response {
        return $this->render($this->templates['share'], [
            'layout'           => $this->templates['layout'],
            'form'             => $form,
            'resourceLabel'    => $resourceLabel,
            'grants'           => $grants,
            'resourceId'       => $resourceId,
            'grantRevokeRoute' => $grantRevokeRoute,
            'granteeLabels'    => $grantList->getLabelMap(),
            'routes'           => $this->routes,
            'dashboardRoute'   => $this->dashboardRoute,
            'backRoute'        => $backRoute,
            'backParams'       => $backParams,
        ]);
    }
}
