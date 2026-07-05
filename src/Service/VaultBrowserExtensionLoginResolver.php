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
use Symfony\Component\Security\Core\User\UserInterface;

use function usort;

final readonly class VaultBrowserExtensionLoginResolver
{
    public function __construct(
        private VaultItemRepositoryInterface $itemRepository,
        private VaultSharedItemResolver $sharedItemResolver,
        private VaultAccessGuard $accessGuard,
        private VaultPayloadCryptographerInterface $cryptographer,
        private VaultLoginDomainMatcher $domainMatcher,
    ) {
    }

    /**
     * @return list<VaultBrowserExtensionLoginMatch>
     */
    public function resolveForDomain(UserInterface $user, string $domain): array
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
            $score   = $this->domainMatcher->bestMatchScore($host, $data->websites);

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

        return array_values($byId);
    }
}
