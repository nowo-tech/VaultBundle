<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:vault-demo:seed',
    description: 'Create demo vault logins when the demo user exists but vault_items is empty.',
)]
final class SeedVaultDemoItemsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VaultItemCreator $itemCreator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'demo@example.com']);
        if (!$user instanceof User) {
            $output->writeln('<error>Demo user demo@example.com not found. Run doctrine:fixtures:load first.</error>');

            return Command::FAILURE;
        }

        $this->itemCreator->create(
            VaultItemType::Login,
            'Demo broad login',
            $user,
            [
                'username' => 'broad-user',
                'password' => 'broad-pass',
                'websites' => ['https://example.com'],
            ],
        );
        $this->itemCreator->create(
            VaultItemType::Login,
            'Demo specific login',
            $user,
            [
                'username' => 'specific-user',
                'password' => 'specific-pass',
                'websites' => ['https://login.example.com'],
            ],
        );

        $output->writeln('Seeded 2 demo vault login item(s).');

        return Command::SUCCESS;
    }
}
