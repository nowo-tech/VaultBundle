<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Controller;

use LogicException;
use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Dto\VaultCatalogueQuery;
use Nowo\VaultBundle\Dto\VaultItemFormData;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultHealthThrottledException;
use Nowo\VaultBundle\Exception\VaultHealthTooLargeException;
use Nowo\VaultBundle\Form\VaultActionType;
use Nowo\VaultBundle\Form\VaultFolderCreateType;
use Nowo\VaultBundle\Form\VaultItemFormType;
use Nowo\VaultBundle\Form\VaultItemSearchType;
use Nowo\VaultBundle\Routing\VaultRouteLoader;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultPasswordPolicyResolverInterface;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Service\VaultAccessGuard;
use Nowo\VaultBundle\Service\VaultCatalogue;
use Nowo\VaultBundle\Service\VaultFolderService;
use Nowo\VaultBundle\Service\VaultHealthReporterInterface;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Service\VaultScopeItemLister;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Ui\VaultPageFactory;
use Nowo\VaultBundle\Ui\VaultPages;
use Nowo\VaultBundle\ValueObject\VaultOwner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_values;
use function base64_encode;
use function in_array;
use function max;
use function trim;

/**
 * Scope vault pages (1.16.0, registered only with `scopes.enabled`): the scope item list, item creation and folder
 * creation. `{scope}` is re-validated on every request through the host resolver; an invalid key or a non-member gets
 * the deny status (404 for hosts that hide existence). Existing item routes (view, edit, share, reveal, trash) serve
 * scope items too: their access comes from the owner model of {@see VaultAccessGuard}.
 */
final class VaultScopeController extends AbstractController
{
    use VaultCsrfTrait;
    use VaultPagesTrait;

    /**
     * Stores the scope collaborators and the deny status.
     */
    public function __construct(
        private readonly VaultAccessCheckerInterface $accessChecker,
        private readonly VaultAccessGuard $accessGuard,
        private readonly VaultScopeItemLister $scopeItemLister,
        private readonly VaultItemCreator $itemCreator,
        private readonly VaultFolderService $folderService,
        private readonly VaultTagService $tagService,
        private readonly TranslatorInterface $translator,
        private readonly VaultRuntimeConfigProvider $runtimeConfig,
        private readonly bool $passwordStrengthEnabled = false,
        private readonly bool $tagInputEnabled = false,
        private readonly int $perPage = 50,
        private readonly int $denyStatus = Response::HTTP_FORBIDDEN,
        private readonly ?VaultPageFactory $pageFactory = null,
        private readonly ?VaultCatalogue $catalogue = null,
        private readonly int $cataloguePerPage = 50,
        private readonly ?VaultHealthReporterInterface $healthReporter = null,
        private readonly ?VaultPasswordPolicyResolverInterface $passwordPolicyResolver = null,
    ) {
    }

    /**
     * Lists the scope's items visible to the member (baseline, or explicit grants only).
     */
    #[Route(path: '/tools/vault/scopes/{scope}', name: 'nowo_vault_scope_items', requirements: ['scope' => VaultOwner::SCOPE_REQUIREMENT], options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'scope_items'], methods: ['GET'])]
    public function items(Request $request, string $scope): Response
    {
        $user        = $this->member($scope);
        $folderId    = $request->query->getString('folder') ?: null;
        $tagId       = $request->query->getString('tag') ?: null;
        $searchQuery = trim($request->query->getString('q'));
        $page        = max(1, $request->query->getInt('page', 1));

        try {
            $result = $this->scopeItemLister->list($user, $scope, $folderId, $searchQuery !== '' ? $searchQuery : null, $tagId, $page, $this->perPage);
        } catch (VaultAccessDeniedException) {
            throw $this->denied();
        }

        $routes  = $this->routes();
        $pages   = $this->pages();
        $access  = $result['access'];
        $folders = $this->folderService->listForScope($scope);
        $tags    = $this->tagService->listForScope($scope);
        if ($result['visible'] !== null) {
            // Grants only (SEC-004 FQ-L1): folder and tag names are scope metadata; show only those of items the
            // member may View (and the folders' ancestors), plus folders the member administers through a grant.
            [$folders, $tags] = $this->visibleFoldersAndTags($user, $folders, $tags, $result['visible']);
        }
        $search = $this->createForm(VaultItemSearchType::class, ['q' => $searchQuery], [
            'action'  => $this->generateUrl($routes['scope_items']['name'], ['scope' => $scope]),
            'filters' => ['folder' => $folderId, 'tag' => $tagId],
        ])->createView();

        return $this->render($this->templates()['scope_items'], $pages->context([
            'page' => $pages->scopeItems(
                $routes,
                $scope,
                $result['items'],
                $result['permissions'],
                $this->trashForms($result['items'], $result['permissions']),
                $search,
                $pages->pagination($routes['scope_items']['name'], $page, $this->perPage, $result['total'], ['scope' => $scope, 'folder' => $folderId, 'tag' => $tagId, 'q' => $searchQuery]),
                $access->create,
                $folderId,
            ),
            'scope'        => $scope,
            'access'       => $access,
            'items'        => $result['items'],
            'permissions'  => $result['permissions'],
            'folders'      => $folders,
            'tags'         => $tags,
            'activeFolder' => $folderId,
            'activeTag'    => $tagId,
            'searchQuery'  => $searchQuery,
            'routes'       => $routes,
            'folderForm'   => $access->create ? $this->createForm(VaultFolderCreateType::class, null, [
                'action'        => $this->generateUrl($routes['scope_folder_create']['name'], ['scope' => $scope]),
                'csrf_token_id' => 'vault_scope_folder_create_' . $scope,
            ])->createView() : null,
        ]));
    }

    /**
     * Metadata-only catalogue of the scope (1.16.0, `catalogue.enabled`): members with `catalogue` see which items exist
     * (title, type, scope tags, date of the last change) and whether they already have access. Override the Twig block
     * `vault_catalogue_row_actions` (it receives `entry` only) to offer *Request access*.
     */
    #[Route(path: '/tools/vault/scopes/{scope}/catalogue', name: 'nowo_vault_catalogue', requirements: ['scope' => VaultOwner::SCOPE_REQUIREMENT], options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'catalogue'], methods: ['GET'])]
    public function catalogue(Request $request, string $scope): Response
    {
        $user  = $this->member($scope);
        $type  = VaultItemType::tryFrom($request->query->getString('type'));
        $tagId = $request->query->getString('tag') ?: null;
        $q     = trim($request->query->getString('q'));
        $page  = max(1, $request->query->getInt('page', 1));

        try {
            $result = $this->catalogueService()->page($user, $scope, new VaultCatalogueQuery($q !== '' ? $q : null, $type, $tagId, $page, $this->cataloguePerPage));
        } catch (VaultAccessDeniedException) {
            throw $this->denied();
        }

        $routes = $this->routes();
        $pages  = $this->pages();
        $search = $this->createForm(VaultItemSearchType::class, ['q' => $q], [
            'action'  => $this->generateUrl($routes['catalogue']['name'], ['scope' => $scope]),
            'filters' => ['tag' => $tagId, 'type' => $type?->value],
        ])->createView();

        return $this->render($this->templates()['catalogue'], $pages->context([
            'page'        => $pages->catalogue($routes, $scope, $result, $search, $pages->pagination($routes['catalogue']['name'], $page, $result->perPage, $result->total, ['scope' => $scope, 'q' => $q, 'type' => $type?->value, 'tag' => $tagId])),
            'scope'       => $scope,
            'catalogue'   => $result,
            'tags'        => $this->tagService->listForScope($scope),
            'itemTypes'   => VaultItemType::cases(),
            'activeType'  => $type?->value,
            'activeTag'   => $tagId,
            'searchQuery' => $q,
            'routes'      => $routes,
        ]));
    }

    /**
     * Password health of the scope (1.16.0, `health.enabled`): members with `health` (and HEALTH_ACCESS_CHECK) see weak,
     * reused, old and breached passwords of the items they may View, computed now in memory. 413 above
     * `health.max_items`.
     */
    #[Route(path: '/tools/vault/scopes/{scope}/health', name: 'nowo_vault_health', requirements: ['scope' => VaultOwner::SCOPE_REQUIREMENT], options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'health'], methods: ['GET'])]
    public function health(string $scope): Response
    {
        $user = $this->member($scope);

        try {
            $report = ($this->healthReporter ?? throw new LogicException('The scope health page needs the VaultHealthReporterInterface service.'))->forScope($user, $scope);
        } catch (VaultAccessDeniedException) {
            throw $this->denied();
        } catch (VaultHealthThrottledException $exception) {
            throw new HttpException(Response::HTTP_TOO_MANY_REQUESTS, $this->translator->trans('vault.health.throttled', [], 'NowoVaultBundle'), null, ['Retry-After' => (string) $exception->getRetryAfter()]);
        } catch (VaultHealthTooLargeException) {
            throw new HttpException(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $this->translator->trans('vault.health.too_large', [], 'NowoVaultBundle'));
        }

        $routes = $this->routes();
        $pages  = $this->pages();

        return $this->render($this->templates()['health'], $pages->context([
            'page'   => $pages->health($routes, $scope, $report),
            'scope'  => $scope,
            'report' => $report,
            'routes' => $routes,
        ]));
    }

    /**
     * Creates an item in the scope (`create` permission). The scope comes from the route, never from the form.
     */
    #[Route(path: '/tools/vault/scopes/{scope}/items/new/{type}', name: 'nowo_vault_scope_item_new', requirements: ['scope' => VaultOwner::SCOPE_REQUIREMENT, 'type' => '[a-z_]+'], options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'scope_item_new'], methods: ['GET', 'POST'])]
    public function newItem(Request $request, string $scope, string $type): Response
    {
        $user   = $this->member($scope);
        $access = $this->accessGuard->resolveScopeAccess($user, $scope);
        if (!$access instanceof VaultScopeAccess || !$access->create) {
            throw $this->denied();
        }

        $itemType = VaultItemType::tryFrom($type);
        if ($itemType === null) {
            throw $this->createNotFoundException('Unknown vault item type.');
        }

        $folders      = $this->folderService->listForScope($scope);
        $data         = new VaultItemFormData($itemType);
        $data->folder = $this->scopeFolder($folders, $request->query->getString('folder'));
        $form         = $this->createForm(VaultItemFormType::class, $data, [
            'folders'         => $folders,
            'scope_item'      => true,
            'password_policy' => $itemType === VaultItemType::Login ? $this->passwordPolicyResolver?->forOwner(VaultOwner::scope($scope)) : null,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // The folder choices are the scope's folders: another owner's folder is an invalid submission (422).
            $item = $this->itemCreator->createInScope($user, $itemType, $data->title, $scope, $this->withAttachment($data->toPayload(), $itemType, $request), $data->folder);
            $item->setCatalogueHidden($data->catalogueHidden);
            $this->tagService->syncItemTags($item, $user, $data->tags);
            $this->addFlash('success', $this->translator->trans('vault.flash.item_created', [], 'NowoVaultBundle'));

            return $this->redirectToRoute($this->routes()['scope_items']['name'], ['scope' => $scope] + ($data->folder instanceof VaultFolder ? ['folder' => $data->folder->getId()] : []));
        }

        $routes = $this->routes();
        $pages  = $this->pages();
        $view   = $form->createView();
        $back   = $this->generateUrl($routes['scope_items']['name'], ['scope' => $scope]);

        return $this->render($this->templates()['item_form'], $pages->context([
            'page'                    => $pages->itemForm($routes, $view, $itemType, null, false, null, $this->tagInputEnabled ? [VaultPages::ITEM_FORM_THEME] : [], $back),
            'form'                    => $view,
            'itemType'                => $itemType,
            'item'                    => null,
            'readOnly'                => false,
            'viewMode'                => false,
            'folders'                 => $folders,
            'routes'                  => $routes,
            'passwordStrengthEnabled' => $this->passwordStrengthEnabled,
            'tagInputEnabled'         => $this->tagInputEnabled,
            'activeFolder'            => null,
            'revealFields'            => [],
            'scope'                   => $scope,
        ]), new Response(null, $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Creates a folder in the scope (`create` permission, CSRF protected).
     */
    #[Route(path: '/tools/vault/scopes/{scope}/folders', name: 'nowo_vault_scope_folder_create', requirements: ['scope' => VaultOwner::SCOPE_REQUIREMENT], options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'scope_folder_create'], methods: ['POST'])]
    public function createFolder(Request $request, string $scope): RedirectResponse
    {
        $user = $this->member($scope);
        $this->denyUnlessValidCsrf('vault_scope_folder_create_' . $scope, $request);

        $name = trim($request->request->getString('name'));
        if ($name === '') {
            $this->addFlash('error', $this->translator->trans('vault.flash.folder_name_required', [], 'NowoVaultBundle'));

            return $this->redirectToRoute($this->routes()['scope_items']['name'], ['scope' => $scope]);
        }

        try {
            $this->folderService->createInScope($user, $name, $scope);
        } catch (VaultAccessDeniedException) {
            throw $this->denied();
        }
        $this->addFlash('success', $this->translator->trans('vault.flash.folder_created', [], 'NowoVaultBundle'));

        return $this->redirectToRoute($this->routes()['scope_items']['name'], ['scope' => $scope]);
    }

    /**
     * The current user when they may use the vault and are a member of the scope; the deny status otherwise.
     */
    private function member(string $scope): UserInterface
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface || !$this->accessChecker->canAccess($user)) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->accessGuard->resolveScopeAccess($user, $scope) instanceof VaultScopeAccess) {
            throw $this->denied();
        }

        return $user;
    }

    private function catalogueService(): VaultCatalogue
    {
        return $this->catalogue ?? throw new LogicException('The scope catalogue needs the VaultCatalogue service.');
    }

    /**
     * Permission denial in a scope: 403, or 404 with security.deny_status: 404 (a scope never confirms it exists).
     */
    /**
     * Folders and tags a grants-only member may see: those of the items they may View (folders with their ancestors),
     * and the folders they may Share (admin grant on the folder).
     *
     * @param list<VaultFolder> $folders
     * @param list<VaultTag> $tags
     * @param list<VaultItem> $visible
     *
     * @return array{0: list<VaultFolder>, 1: list<VaultTag>}
     */
    private function visibleFoldersAndTags(UserInterface $user, array $folders, array $tags, array $visible): array
    {
        $folderIds = [];
        $tagIds    = [];
        foreach ($visible as $item) {
            for ($folder = $item->getFolder(); $folder instanceof VaultFolder && !isset($folderIds[$folder->getId()]); $folder = $folder->getParent()) {
                $folderIds[$folder->getId()] = true;
            }
            foreach ($item->getTags() as $tag) {
                $tagIds[$tag->getId()] = true;
            }
        }

        return [
            array_values(array_filter($folders, fn (VaultFolder $folder): bool => isset($folderIds[$folder->getId()]) || $this->accessGuard->canAccessFolder($user, $folder, VaultAccessAction::Share))),
            array_values(array_filter($tags, static fn (VaultTag $tag): bool => isset($tagIds[$tag->getId()]))),
        ];
    }

    private function denied(): HttpException|AccessDeniedException
    {
        return $this->denyStatus === Response::HTTP_NOT_FOUND
            ? $this->createNotFoundException('Vault scope not found.')
            : $this->createAccessDeniedException();
    }

    /**
     * @param list<VaultFolder> $folders
     */
    private function scopeFolder(array $folders, string $folderId): ?VaultFolder
    {
        foreach ($folders as $folder) {
            if ($folder->getId() === $folderId) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * Trash forms for the items the member may delete (scope admin).
     *
     * @param list<VaultItem> $items
     * @param array<string, VaultPermission|null> $permissions
     *
     * @return array<string, FormView>
     */
    private function trashForms(array $items, array $permissions): array
    {
        $forms = [];
        $route = $this->routes()['item_trash']['name'];
        foreach ($items as $item) {
            $id = $item->getId();
            if (($permissions[$id] ?? null)?->allowsAdmin() !== true) {
                continue;
            }
            $forms[$id] = $this->createForm(VaultActionType::class, null, [
                'action'        => $this->generateUrl($route, ['id' => $id]),
                'csrf_token_id' => 'vault_trash_' . $id,
            ])->createView();
        }

        return $forms;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function withAttachment(array $payload, VaultItemType $type, Request $request): array
    {
        $file = $request->files->get('attachment');
        if (!in_array($type, VaultItemType::documentTypes(), true) || !$file instanceof UploadedFile) {
            return $payload;
        }

        if ($file->getSize() > (int) $this->runtimeConfig->get()['max_attachment_bytes']) {
            throw $this->createAccessDeniedException('Attachment too large.');
        }

        $payload['attachmentName']    = $file->getClientOriginalName();
        $payload['attachmentContent'] = base64_encode($file->getContent());

        return $payload;
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
     * @return array<string, string>
     */
    private function templates(): array
    {
        /* @var array<string, string> */
        return $this->runtimeConfig->get()['templates'];
    }
}
