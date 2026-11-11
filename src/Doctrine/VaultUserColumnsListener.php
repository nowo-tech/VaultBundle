<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Doctrine;

use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\ValueObject\VaultUserId;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function in_array;
use function is_object;
use function is_string;

/**
 * Normalises the host user held by a new vault row before Doctrine stores it (1.17.0).
 *
 * Relation mapping: a `user_class` instance is left as is (the standard path does not change); a security user of a
 * class listed in `accepted_user_classes` (no Doctrine entity, a wrapper, a read model) becomes a reference of
 * `user_class` with its id, and so does a user id given as a string. Id mapping: the user becomes its string id. Any
 * other user is refused (fail closed).
 * Stateless: safe in a kept-alive worker.
 */
final readonly class VaultUserColumnsListener
{
    private const ENTITIES = [VaultItem::class, VaultFolder::class, VaultGrant::class, VaultTag::class, VaultExtensionToken::class];

    public function __construct(
        private VaultUserReferenceResolverInterface $users,
    ) {
    }

    /**
     * @throws AccessDeniedException when a user column holds a user the vault does not accept
     */
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!in_array($entity::class, self::ENTITIES, true)) {
            return;
        }

        $metadata = $args->getObjectManager()->getClassMetadata($entity::class);
        foreach (VaultMetadataListener::USER_FIELDS as $field) {
            if (!$metadata->hasField($field) && !$metadata->hasAssociation($field)) {
                continue;
            }

            $value = $metadata->getFieldValue($entity, $field);
            if (is_string($value) && !$this->users->mapsById()) {
                // A host passed the user id: turn it into a reference of user_class.
                $value = new VaultUserId($value);
            }
            if (!is_object($value) || $this->isRelatedUser($metadata, $field, $value)) {
                continue;
            }

            $metadata->setFieldValue($entity, $field, $this->users->reference($value));
        }
    }

    /**
     * Relation mapping: whether the value already is an instance of the related `user_class` (left unchanged).
     *
     * @param ClassMetadata<object> $metadata
     */
    private function isRelatedUser(ClassMetadata $metadata, string $field, object $value): bool
    {
        if (!$metadata->hasAssociation($field)) {
            return false;
        }

        $target = $metadata->getAssociationTargetClass($field);

        return $value instanceof $target;
    }
}
