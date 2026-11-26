<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Bridge\UserKit;

use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;
use Nowo\VaultBundle\Service\VaultPersonalDataExporter;
use Nowo\VaultBundle\Service\VaultPersonalDataExporterInterface;

/**
 * UserKit personal-data export section `vault` (2.5.0, Beacon spec 185 FR-023; registered like
 * {@see VaultUserKitEraser}): the metadata of {@see VaultPersonalDataExporterInterface::export()}, one sub-section per
 * erasure step ({@see VaultPersonalDataExporter::ERASURE_SECTIONS}). Never a payload value, a ciphertext, a token or a
 * hash.
 */
final readonly class VaultUserKitExportProvider implements PersonalDataExportProviderInterface
{
    public const SECTION = 'vault';

    /** After host sections, before UserKit's built-ins (-100 and below). */
    public const PRIORITY = -50;

    public function __construct(
        private VaultPersonalDataExporterInterface $exporter,
    ) {
    }

    public function getSectionKey(): string
    {
        return self::SECTION;
    }

    public function supports(object $user): bool
    {
        return VaultUserKitEraser::userId($user) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function export(object $user): array
    {
        $userId = VaultUserKitEraser::userId($user);
        if ($userId === null) {
            return [];
        }

        $section = $this->exporter->export($userId);
        // The archive already says whose data it is.
        unset($section['user_id']);

        return $section;
    }
}
