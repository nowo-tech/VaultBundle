<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Command;

use Nowo\VaultBundle\Service\VaultGrantExpiryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function is_numeric;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Console command that purges expired temporary grants (1.16.0). Run it from a scheduler (e.g. every 5 minutes).
 */
#[AsCommand(
    name: 'nowo:vault:grants:purge-expired',
    description: 'Delete expired temporary vault grants and emit GRANT_EXPIRED (access already ends at expiresAt).',
)]
final class PurgeExpiredGrantsCommand extends Command
{
    /**
     * Stores the expiry service.
     */
    public function __construct(
        private readonly VaultGrantExpiryService $expiryService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rows selected per batch.', '500')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many deletions.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count the expired grants without deleting them.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = $input->getOption('batch-size');
        $limit     = $input->getOption('limit');

        // @igor-ignore - Console command; not executed in FrankenPHP worker HTTP requests.
        $result = $this->expiryService->purgeExpired(
            is_numeric($batchSize) ? (int) $batchSize : 500,
            is_numeric($limit) ? (int) $limit : null,
            (bool) $input->getOption('dry-run'),
        );

        $output->writeln(json_encode($result->toArray(), JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
