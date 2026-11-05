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
        /** Owner key of the item (`user:<id>` / `scope:<key>`), for the audit event; never serialized (1.16.0). */
        public ?string $ownerKey = null,
        /** True when ITEM_FIELD_REVEAL_CHECK vetoed the password: it is sent empty (1.16.0, SEC-004 FQ-L2). */
        public bool $passwordWithheld = false,
    ) {
    }

    /**
     * Returns the match as a JSON-serialisable array. `passwordWithheld: true` is added only when the password was
     * vetoed (SEC-004 FQ-I14), so clients can show "withheld" instead of filling an empty value.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id'         => $this->id,
            'title'      => $this->title,
            'username'   => $this->username,
            'password'   => $this->password,
            'websites'   => $this->websites,
            'matchScore' => $this->matchScore,
        ];
        if ($this->passwordWithheld) {
            $data['passwordWithheld'] = true;
        }

        return $data;
    }

    /**
     * Payload fields this match discloses (the audit event's field list): the password only when it was not withheld.
     *
     * @return list<string>
     */
    public function revealedFields(): array
    {
        return $this->passwordWithheld ? ['username', 'websites'] : ['username', 'password', 'websites'];
    }
}
