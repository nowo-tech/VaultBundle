<?php

declare(strict_types=1);

namespace App\Command;

use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function count;
use function sprintf;

#[AsCommand(
    name: 'app:vault-demo:verify',
    description: 'Verify every vault item decrypts with the effective encryption key.',
)]
final class VerifyVaultPayloadsCommand extends Command
{
    public function __construct(
        private readonly VaultItemRepositoryInterface $items,
        private readonly VaultPayloadCryptographerInterface $cryptographer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $total    = $this->items->countAll(true);
        $offset   = 0;
        $verified = 0;

        while ($offset < $total) {
            $batch = $this->items->findBatch($offset, 50, true);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $item) {
                try {
                    $this->cryptographer->decrypt($item->getCiphertext());
                    ++$verified;
                    $output->writeln(sprintf('OK  %s (%s)', $item->getTitle(), $item->getId()));
                } catch (Throwable $exception) {
                    $output->writeln(sprintf(
                        'FAIL %s (%s): %s',
                        $item->getTitle(),
                        $item->getId(),
                        $exception->getMessage(),
                    ));

                    return Command::FAILURE;
                }
            }

            $offset += count($batch);
        }

        if ($verified === 0) {
            $output->writeln('No vault items to verify.');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Verified %d vault item payload(s).', $verified));

        return Command::SUCCESS;
    }
}
