<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Controller;

use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Exception\VaultDecryptionFailedException;
use Nowo\VaultBundle\Exception\VaultRevealRefusedException;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Routing\VaultRouteLoader;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Service\VaultAccessGuard;
use Nowo\VaultBundle\Service\VaultFieldRevealer;
use Nowo\VaultBundle\Support\VaultAttachmentFilename;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;

/**
 * Attachment download of a document item (1.16.0, gap 4; the field reveal stays on VaultManageController for BC of
 * its route). POST + CSRF `vault_reveal_<id>`, View access, field veto `attachment`, rate limited,
 * ITEM_ATTACHMENT_DOWNLOADED before the bytes leave; always `application/octet-stream` with a sanitised file name.
 */
final class VaultRevealController extends AbstractController
{
    use VaultCsrfTrait;
    use VaultRevealResponsesTrait;

    /**
     * Stores the collaborators.
     */
    public function __construct(
        private readonly VaultAccessCheckerInterface $accessChecker,
        private readonly VaultAccessGuard $accessGuard,
        private readonly VaultItemRepositoryInterface $itemRepository,
        private readonly VaultFieldRevealer $fieldRevealer,
        private readonly bool $allowUnauthenticated = false,
        private readonly int $denyStatus = Response::HTTP_FORBIDDEN,
        private readonly ?Profiler $profiler = null,
    ) {
    }

    /**
     * Streams the decrypted attachment as a download.
     */
    #[Route(path: '/tools/vault/items/{id}/attachment', name: 'nowo_vault_item_attachment', requirements: ['id' => VaultRouteLoader::UUID_REQUIREMENT], options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'item_attachment'], methods: ['POST'])]
    public function attachment(Request $request, string $id): Response
    {
        $user = $this->getUser();
        if (!$this->allowUnauthenticated && !$this->accessChecker->canAccess($user instanceof UserInterface ? $user : null)) {
            throw $this->createAccessDeniedException();
        }
        $this->denyUnlessValidCsrf('vault_reveal_' . $id, $request);
        $this->profiler?->disable();

        $item = $this->itemRepository->findById($id);
        if (!$item instanceof VaultItem || $item->isDeleted()) {
            throw $this->createNotFoundException('Vault item not found.');
        }
        if (!$user instanceof UserInterface || !$this->accessGuard->canAccessItem($user, $item, VaultAccessAction::View)) {
            throw $this->denyStatus === Response::HTTP_NOT_FOUND ? $this->createNotFoundException('Vault item not found.') : $this->createAccessDeniedException();
        }

        try {
            $attachment = $this->fieldRevealer->attachment($user, $item);
        } catch (VaultRevealRefusedException $exception) {
            return $this->revealRefused($exception);
        } catch (VaultDecryptionFailedException $exception) {
            return $this->sensitive(new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE));
        }

        $content  = $attachment['content']->get();
        $response = new Response(is_string($content) ? $content : '', Response::HTTP_OK, ['Content-Type' => 'application/octet-stream']);
        $response->headers->set('Content-Disposition', VaultAttachmentFilename::contentDisposition($attachment['name']));

        return $this->sensitive($response);
    }
}
