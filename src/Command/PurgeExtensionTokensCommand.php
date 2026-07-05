<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Command;

use Nowo\VaultBundle\Repository\VaultExtensionTokenRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand(
    name: 'nowo:vault:extension-tokens:purge',
    description: 'Remove expired browser extension Bearer tokens from the database.',
)]
final class PurgeExtensionTokensCommand extends Command
{
    public function __construct(
        private readonly VaultExtensionTokenRepositoryInterface $tokenRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $removed = $this->tokenRepository->removeExpired();

        $io->success(sprintf('Removed %d expired extension token(s).', $removed));

        return Command::SUCCESS;
    }
}
