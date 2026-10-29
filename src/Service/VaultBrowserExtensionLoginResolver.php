<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\BrowserExtension\VaultLoginDomainMatcher;
use Nowo\VaultBundle\Dto\VaultBrowserExtensionLoginMatch;
use Nowo\VaultBundle\Dto\VaultItemFormData;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Support\UserIdResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_slice;
use function count;
use function usort;

/**
 * Finds the login items (own and shared) whose websites match the domain requested by the browser extension.
 */
final readonly class VaultBrowserExtensionLoginResolver
{
    /**
     * Stores the collaborators; $maxLoginItems bounds how many login payloads one lookup decrypts (REQ-PERF-001).
     */
    public function __construct(
        private VaultItemRepositoryInterface $itemRepository,
        private VaultSharedItemResolver $sharedItemResolver,
        private VaultAccessGuard $accessGuard,
        private VaultPayloadCryptographerInterface $cryptographer,
        private VaultLoginDomainMatcher $domainMatcher,
        private int $maxLoginItems = 1000,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Returns the matching logins for $domain, best match first.
     *
     * @return list<VaultBrowserExtensionLoginMatch>
     */
    public function resolveForDomain(UserInterface $user, string $domain, string $scheme = 'https'): array
    {
        $host = VaultLoginDomainMatcher::normalizeHost($domain);
        if ($host === '') {
            return [];
        }

        $items   = $this->collectLoginItems($user);
        $matches = [];

        foreach ($items as $item) {
            if (!$this->accessGuard->canAccessItem($user, $item, VaultAccessAction::View)) {
                continue;
            }

            $payload = $this->cryptographer->decrypt($item->getCiphertext());
            $data    = VaultItemFormData::fromPayload(VaultItemType::Login, $payload);
            $score   = $this->domainMatcher->bestMatchScore($host, $data->websites, $scheme);

            if ($score === null) {
                continue;
            }

            $matches[] = new VaultBrowserExtensionLoginMatch(
                $item->getId(),
                $item->getTitle(),
                $data->username,
                $data->password,
                array_values(array_filter($data->websites)),
                $score,
            );
        }

        usort($matches, static fn (VaultBrowserExtensionLoginMatch $a, VaultBrowserExtensionLoginMatch $b): int => $b->matchScore <=> $a->matchScore);

        return $matches;
    }

    /**
     * @return list<VaultItem>
     */
    private function collectLoginItems(UserInterface $user): array
    {
        $own    = $this->itemRepository->findByCreatorAndItemType($user, VaultItemType::Login);
        $shared = $this->sharedItemResolver->resolveByItemType($user, VaultItemType::Login);
        $byId   = [];

        foreach ([...$own, ...$shared] as $item) {
            $byId[$item->getId()] = $item;
        }

        $items = array_values($byId);
        if (count($items) > $this->maxLoginItems) {
            // Own items come first, most recently updated first; the rest are not decrypted for this lookup.
            ($this->logger ?? new NullLogger())->warning('Vault browser extension login lookup truncated.', [
                'bundle'    => 'nowo_vault',
                'action'    => 'extension_logins',
                'user_id'   => UserIdResolver::getId($user),
                'available' => count($items),
                'scanned'   => $this->maxLoginItems,
            ]);
            $items = array_slice($items, 0, $this->maxLoginItems);
        }

        return $items;
    }
}
