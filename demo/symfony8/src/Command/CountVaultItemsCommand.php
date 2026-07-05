<?php

declare(strict_types=1);

namespace App\Command;

use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:vault-demo:count',
    description: 'Print the number of vault items (including trash).',
)]
final class CountVaultItemsCommand extends Command
{
    public function __construct(
        private readonly VaultItemRepositoryInterface $items,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln((string) $this->items->countAll(true));

        return Command::SUCCESS;
    }
}
