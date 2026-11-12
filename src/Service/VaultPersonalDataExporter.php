<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VaultBundle\Doctrine\VaultExtensionTokenTable;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Repository\VaultFolderRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Support\VaultUserLocatorInterface;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_map;
use function preg_replace;

use const DATE_ATOM;

/**
 * Personal-data export of one user (1.16.0, contract §8.1, GDPR access requests): metadata only, never a payload
 * value, a ciphertext or a hash. Scalar DQL selects, so no `VaultItem` (and no ciphertext) is hydrated, except for the
 * received grants: their resource title is included only when the user can View it now, which the access guard
 * decides on the loaded resource (the title is the only field read from it).
 *
 * The user id is a string so the export also works while an account is being deleted; when the user row is gone,
 * received-grant titles are left out.
 */
final readonly class VaultPersonalDataExporter implements VaultPersonalDataExporterInterface
{
    /**
     * Stores the Doctrine registry and the optional collaborators of the received-grant titles.
     */
    public function __construct(
        private ManagerRegistry $registry,
        private ?VaultUserLocatorInterface $userLocator = null,
        private ?VaultAccessGuard $accessGuard = null,
        private ?VaultItemRepositoryInterface $itemRepository = null,
        private ?VaultFolderRepositoryInterface $folderRepository = null,
        private ?string $managerName = null,
        private ?VaultUserReferenceResolverInterface $users = null,
        /** 1.18.0 (NC-1): token rows through raw SQL while the entity is not mapped. */
        private ?VaultExtensionTokenTable $tokenTable = null,
    ) {
    }

    /**
     * JSON-serialisable sections: items, folders, tags, grants_received, grants_given, scope_items_created,
     * extension_tokens.
     *
     * @return array{user_id: string, items: list<array<string, mixed>>, folders: list<array<string, mixed>>, tags: list<array<string, mixed>>, grants_received: list<array<string, mixed>>, grants_given: list<array<string, mixed>>, scope_items_created: list<array<string, mixed>>, extension_tokens: list<array<string, mixed>>}
     */
    public function export(string $userId): array
    {
        $em   = $this->em();
        $user = $this->userLocator?->find($userId);
        // 1.17.0: with the id mapping the user columns hold the id itself.
        $who = $this->users?->mapsById() === true ? $userId : ($user ?? $userId);

        return [
            'user_id'             => $userId,
            'items'               => $this->items($em, $who, $userId),
            'folders'             => $this->rows($em, 'SELECT f.id AS id, f.name AS name, f.createdAt AS created_at, f.updatedAt AS updated_at, f.deletedAt AS deleted_at FROM ' . VaultFolder::class . ' f WHERE f.creator = :user AND f.ownerScope IS NULL ORDER BY f.createdAt ASC, f.id ASC', ['user' => $who], 'f.creator', $userId),
            'tags'                => $this->rows($em, 'SELECT t.id AS id, t.name AS name, t.createdAt AS created_at FROM ' . VaultTag::class . ' t WHERE t.creator = :user AND t.ownerScope IS NULL ORDER BY t.name ASC', ['user' => $who], 't.creator', $userId),
            'grants_received'     => $this->grantsReceived($em, $userId, $user),
            'grants_given'        => $this->rows($em, 'SELECT g.resourceType AS resource_type, g.resourceId AS resource_id, g.granteeType AS grantee_type, g.granteeId AS grantee_id, g.permission AS permission, g.expiresAt AS expires_at, g.createdAt AS created_at FROM ' . VaultGrant::class . ' g WHERE g.createdBy = :user ORDER BY g.createdAt ASC, g.id ASC', ['user' => $who], 'g.createdBy', $userId),
            'scope_items_created' => $this->rows($em, 'SELECT i.id AS id, i.ownerScope AS owner_scope, i.createdAt AS created_at FROM ' . VaultItem::class . ' i WHERE i.createdBy = :user AND i.ownerScope IS NOT NULL ORDER BY i.createdAt ASC, i.id ASC', ['user' => $who], 'i.createdBy', $userId),
            // 1.18.0 (NC-1): when the entity is not mapped (database.conditional_mapping, extension off), the rows an
            // earlier release left are read from the table.
            'extension_tokens' => $em->getMetadataFactory()->isTransient(VaultExtensionToken::class) ? $this->unmappedTokens($em, $userId) : $this->rows($em, 'SELECT t.createdAt AS created_at, t.expiresAt AS expires_at, t.lastUsedAt AS last_used_at FROM ' . VaultExtensionToken::class . ' t WHERE t.user = :user ORDER BY t.createdAt ASC', ['user' => $who], 't.user', $userId),
        ];
    }

    /**
     * Personal items (trash included) with their folder name and tag names.
     *
     * @return list<array<string, mixed>>
     */
    private function items(EntityManagerInterface $em, object|string $who, string $userId): array
    {
        $items = $this->rows($em, 'SELECT i.id AS id, i.itemType AS type, i.title AS title, f.name AS folder, i.createdAt AS created_at, i.updatedAt AS updated_at, i.secretChangedAt AS secret_changed_at, i.deletedAt AS deleted_at FROM ' . VaultItem::class . ' i LEFT JOIN i.folder f WHERE i.creator = :user AND i.ownerScope IS NULL ORDER BY i.createdAt ASC, i.id ASC', ['user' => $who], 'i.creator', $userId);
        $tags  = [];
        foreach ($this->rows($em, 'SELECT i.id AS item_id, t.name AS name FROM ' . VaultItem::class . ' i JOIN i.tags t WHERE i.creator = :user AND i.ownerScope IS NULL ORDER BY t.name ASC', ['user' => $who], 'i.creator', $userId) as $row) {
            $tags[(string) $row['item_id']][] = (string) $row['name'];
        }

        $out = [];
        foreach ($items as $item) {
            $out[] = [
                'id'                => $item['id'],
                'type'              => $item['type'],
                'title'             => $item['title'],
                'folder'            => $item['folder'],
                'tags'              => $tags[(string) $item['id']] ?? [],
                'created_at'        => $item['created_at'],
                'updated_at'        => $item['updated_at'],
                'secret_changed_at' => $item['secret_changed_at'],
                'deleted_at'        => $item['deleted_at'],
            ];
        }

        return $out;
    }

    /**
     * Direct grants to the user (not via teams); the title only when the user can View the resource now.
     *
     * @return list<array<string, mixed>>
     */
    private function grantsReceived(EntityManagerInterface $em, string $userId, ?UserInterface $user): array
    {
        $rows = $this->rows($em, 'SELECT g.resourceType AS resource_type, g.resourceId AS resource_id, g.permission AS permission, g.expiresAt AS expires_at, g.createdAt AS created_at FROM ' . VaultGrant::class . ' g WHERE g.granteeType = :type AND g.granteeId = :id ORDER BY g.createdAt ASC, g.id ASC', ['type' => GranteeType::User, 'id' => $userId], 'g.granteeId', $userId, true);

        $out = [];
        foreach ($rows as $row) {
            $title = $user instanceof UserInterface ? $this->visibleTitle($user, (string) $row['resource_type'], (string) $row['resource_id']) : null;
            $out[] = $row + ['title' => $title];
        }

        return $out;
    }

    private function visibleTitle(UserInterface $user, string $type, string $resourceId): ?string
    {
        if (!$this->accessGuard instanceof VaultAccessGuard) {
            return null;
        }

        if ($type === VaultResourceType::Item->value) {
            $item = $this->itemRepository?->findById($resourceId);

            return $item instanceof VaultItem && !$item->isDeleted() && $this->accessGuard->canAccessItem($user, $item, VaultAccessAction::View) ? $item->getTitle() : null;
        }

        $folder = $this->folderRepository?->findById($resourceId);

        return $folder instanceof VaultFolder && !$folder->isDeleted() && $this->accessGuard->canAccessFolder($user, $folder, VaultAccessAction::View) ? $folder->getName() : null;
    }

    /**
     * Runs a scalar DQL select and normalises the values (enums to their value, dates to ATOM strings).
     *
     * @param array<string, mixed> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private function rows(EntityManagerInterface $em, string $dql, array $parameters, ?string $ownerField = null, ?string $userId = null, bool $exactInEveryMapping = false): array
    {
        // 1.17.0 (LQ-V-M1): with the id mapping (and always for the grantee id, LQ-V-P2-M1), also read the stored id and
        // keep the rows where it equals the requested id byte for byte, whatever the column collation.
        $strict = $ownerField !== null && $userId !== null && ($exactInEveryMapping || $this->users?->mapsById() === true);
        if ($strict) {
            $dql = preg_replace('/ FROM /', ', ' . $ownerField . ' AS vault_owner FROM ', $dql, 1) ?? $dql;
        }

        $query = $em->createQuery($dql);
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $query->getArrayResult();
        $out  = [];
        foreach ($rows as $row) {
            if ($strict) {
                if (($row['vault_owner'] ?? null) !== $userId) {
                    continue;
                }
                unset($row['vault_owner']);
            }
            $out[] = array_map($this->normalise(...), $row);
        }

        return $out;
    }

    /**
     * Token rows read from the table while the entity is not mapped (same columns as the mapped export).
     *
     * @return list<array<string, mixed>>
     */
    private function unmappedTokens(EntityManagerInterface $em, string $userId): array
    {
        $out = [];
        foreach (($this->tokenTable ?? new VaultExtensionTokenTable())->rowsForUser($em, $userId, $this->users?->mapsById() === true) as $row) {
            $out[] = array_map($this->normalise(...), ['created_at' => $row['created_at'], 'expires_at' => $row['expires_at'], 'last_used_at' => $row['last_used_at']]);
        }

        return $out;
    }

    private function normalise(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum        => $value->value,
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)->format(DATE_ATOM),
            default                             => $value,
        };
    }

    private function em(): EntityManagerInterface
    {
        $em = $this->registry->getManager($this->managerName);
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('The personal-data exporter needs a Doctrine ORM entity manager.');
        }

        return $em;
    }
}
