<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Dto;

/**
 * Login credential returned to the browser extension for autofill.
 */
final readonly class VaultBrowserExtensionLoginMatch
{
    /**
     * Creates a login match returned to the browser extension.
     *
     * @param list<string> $websites
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $username,
        public string $password,
        public array $websites,
        public int $matchScore,
    ) {
    }

    /**
     * Returns the match as a JSON-serialisable array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'title'      => $this->title,
            'username'   => $this->username,
            'password'   => $this->password,
            'websites'   => $this->websites,
            'matchScore' => $this->matchScore,
        ];
    }
}
