<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Command;

use InvalidArgumentException;
use Nowo\VaultBundle\Config\VaultRuntimeConfigWriter;
use Nowo\VaultBundle\Security\SodiumVaultPayloadCryptographer;
use Nowo\VaultBundle\Service\VaultPayloadReencryptionService;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function file_get_contents;
use function is_readable;
use function is_string;
use function sprintf;
use function trim;

#[AsCommand(
    name: 'nowo:vault:reencrypt',
    description: 'Re-encrypt all vault item payloads from an old encryption key to a new one.',
)]
final class ReencryptVaultPayloadsCommand extends Command
{
    public function __construct(
        private readonly VaultPayloadReencryptionService $reencryptionService,
        private readonly VaultRuntimeConfigWriter $configWriter,
        private readonly bool $databaseEnabled,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('old-key', null, InputOption::VALUE_REQUIRED, 'Previous base64-encoded 32-byte libsodium key (discouraged: visible in process argv / shell history). Prefer --old-key-file.')
            ->addOption('old-key-file', null, InputOption::VALUE_REQUIRED, 'Read the previous key from a file (trimmed). Preferred over --old-key; wins when both are set.')
            ->addOption('new-key', null, InputOption::VALUE_REQUIRED, 'Target key via argv (discouraged). Defaults to the effective nowo_vault.encryption_key from YAML/DB. Prefer --new-key-file.')
            ->addOption('new-key-file', null, InputOption::VALUE_REQUIRED, 'Read the target key from a file (trimmed). Preferred over --new-key; wins when both are set.')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Number of items processed per flush.', '50')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Verify decryption with the old key without writing ciphertext.')
            ->addOption('skip-trash', null, InputOption::VALUE_NONE, 'Skip soft-deleted items in trash.')
            ->addOption('persist-new-key', null, InputOption::VALUE_NONE, 'After success, store the new key in database runtime config (requires config_storage.enabled).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation (required for non-interactive runs that write ciphertext).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $oldKey = $this->resolveKeyMaterial($input, 'old-key', 'old-key-file');
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        if ($oldKey === null || $oldKey === '') {
            $io->error('Provide the previous key via --old-key-file (preferred) or --old-key.');

            return Command::INVALID;
        }

        try {
            $newKey = $this->resolveKeyMaterial($input, 'new-key', 'new-key-file');
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }
        $batchSize    = (int) $input->getOption('batch-size');
        $dryRun       = (bool) $input->getOption('dry-run');
        $persistKey   = (bool) $input->getOption('persist-new-key');
        $force        = (bool) $input->getOption('force');

        if ($persistKey && ($dryRun || $newKey === null)) {
            $io->error('Option --persist-new-key requires --new-key or --new-key-file and cannot be combined with --dry-run.');

            return Command::INVALID;
        }

        if ($persistKey && !$this->databaseEnabled) {
            $io->error('Option --persist-new-key requires nowo_vault.config_storage.enabled.');

            return Command::INVALID;
        }

        try {
            new SodiumVaultPayloadCryptographer($oldKey);
            if ($newKey !== null) {
                new SodiumVaultPayloadCryptographer($newKey);
            }
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        if ($dryRun) {
            $io->note('Dry run: no ciphertext will be written.');
        } elseif (!$this->confirmExecution($io, $input, $force)) {
            return $input->isInteractive() ? Command::SUCCESS : Command::INVALID;
        }

        $progress = null;
        if ($output->isVerbose() && $io->isDecorated()) {
            $progress = $io->createProgressBar();
            $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
            $progress->start();
        }

        try {
            $result = $this->reencryptionService->reencrypt(
                oldKeyBase64: $oldKey,
                newKeyBase64: $newKey,
                batchSize: $batchSize,
                includeDeleted: !$input->getOption('skip-trash'),
                dryRun: $dryRun,
                onProgress: static function (int $processed, int $total) use ($progress): void {
                    if (!$progress instanceof ProgressBar) {
                        return;
                    }

                    $progress->setMaxSteps(max(1, $total));
                    $progress->setProgress($processed);
                },
            );
        } catch (InvalidArgumentException $exception) {
            $progress?->finish();
            $io->newLine(2);
            $io->error($exception->getMessage());

            return Command::INVALID;
        } catch (RuntimeException $exception) {
            $progress?->finish();
            $io->newLine(2);
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $progress?->finish();
        $io->newLine(2);

        if ($result->totalItems === 0) {
            $io->warning('No vault items found.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%s %d of %d vault item payload(s).',
            $dryRun ? 'Verified' : 'Re-encrypted',
            $result->processedItems,
            $result->totalItems,
        ));

        if ($persistKey) {
            $this->configWriter->update(['encryption_key' => $newKey]);
            $io->success('Stored the new encryption key in database runtime config.');
        } elseif (!$dryRun) {
            $io->writeln('Remember to update nowo_vault.encryption_key (YAML/env or runtime config) if it still points to the old key.');
        }

        return Command::SUCCESS;
    }


    /**
     * Resolves key material from a file option (preferred) or argv option.
     *
     * @throws InvalidArgumentException when the key file is missing or unreadable
     */
    private function resolveKeyMaterial(InputInterface $input, string $argvOption, string $fileOption): ?string
    {
        $fileOptionValue = $input->getOption($fileOption);
        if (is_string($fileOptionValue) && $fileOptionValue !== '') {
            if (!is_readable($fileOptionValue)) {
                throw new InvalidArgumentException(sprintf('Key file "%s" is not readable.', $fileOptionValue));
            }

            $contents = file_get_contents($fileOptionValue);
            if ($contents === false) {
                throw new InvalidArgumentException(sprintf('Unable to read key file "%s".', $fileOptionValue));
            }

            return trim($contents);
        }

        $argvValue = $input->getOption($argvOption);
        if (is_string($argvValue) && trim($argvValue) !== '') {
            return trim($argvValue);
        }

        return null;
    }

    private function confirmExecution(SymfonyStyle $io, InputInterface $input, bool $force): bool
    {
        if ($force) {
            return true;
        }

        if ($input->isInteractive()) {
            return $io->confirm(
                'This will re-encrypt every vault item payload in the database. Continue?',
                false,
            );
        }

        $io->error('Non-interactive re-encryption requires --force (or use --dry-run to verify only).');

        return false;
    }
}
