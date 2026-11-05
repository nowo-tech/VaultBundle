<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function array_sum;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Dry check before rolling the 1.16.0 schema back to 1.15.x (counts only, no write): a rollback is safe only while no
 * scope-owned row (`owner_scope` set / `creator_id` NULL) and no temporary grant exists. Exit 0 when safe, 1 when not.
 */
#[AsCommand(
    name: 'nowo:vault:schema:check-downgrade',
    description: 'Count the rows that would block a rollback of the 1.16.0 vault schema to 1.15.x (read-only).',
)]
final class CheckDowngradeCommand extends Command
{
    /**
     * Stores the Doctrine registry and the vault entity manager name.
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ?string $managerName = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $em = $this->registry->getManager($this->managerName);
        if (!$em instanceof EntityManagerInterface) {
            $output->writeln('{"error":"no ORM entity manager"}');

            return Command::FAILURE;
        }

        // @igor-ignore - Console command; not executed in FrankenPHP worker HTTP requests.
        $counts = [
            'scope_items'      => $this->count($em, VaultItem::class, 'r.ownerScope IS NOT NULL OR r.creator IS NULL'),
            'scope_folders'    => $this->count($em, VaultFolder::class, 'r.ownerScope IS NOT NULL OR r.creator IS NULL'),
            'scope_tags'       => $this->count($em, VaultTag::class, 'r.ownerScope IS NOT NULL OR r.creator IS NULL'),
            'temporary_grants' => $this->count($em, VaultGrant::class, 'r.expiresAt IS NOT NULL'),
        ];
        $safe = array_sum($counts) === 0;

        $output->writeln(json_encode(['safe' => $safe] + $counts, JSON_THROW_ON_ERROR));

        return $safe ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param class-string $class
     */
    private function count(EntityManagerInterface $em, string $class, string $condition): int
    {
        return (int) $em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from($class, 'r')
            ->where($condition)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
