<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Dto;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Enum\VaultItemType;

use function is_array;

final class VaultItemFormData
{
    public string $title        = '';
    public ?VaultFolder $folder = null;
    public string $note         = '';

    // Login
    public string $username = '';
    public string $password = '';
    /** @var list<string> */
    public array $websites = [''];

    // Secure note
    public string $secureNote = '';

    // Credit card
    public string $cardholderName = '';
    public string $cardNumber     = '';
    public string $expiry         = '';
    public string $cvv            = '';
    public string $cardPin        = '';
    public string $postalCode     = '';

    // Contact
    public string $fullName     = '';
    public string $email        = '';
    public string $phone        = '';
    public string $addressLine1 = '';
    public string $addressLine2 = '';
    public string $city         = '';
    public string $state        = '';
    public string $country      = '';

    // Document
    public string $documentNumber = '';
    public string $issuedBy       = '';
    public string $issuedDate     = '';
    public string $expiryDate     = '';

    public function __construct(
        public VaultItemType $itemType = VaultItemType::Login,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $base = ['note' => $this->note];

        return match ($this->itemType) {
            VaultItemType::Login => array_merge($base, [
                'username' => $this->username,
                'password' => $this->password,
                'websites' => array_values(array_filter($this->websites)),
            ]),
            VaultItemType::SecureNote => array_merge($base, [
                'content' => $this->secureNote,
            ]),
            VaultItemType::CreditCard => array_merge($base, [
                'cardholderName' => $this->cardholderName,
                'cardNumber'     => $this->cardNumber,
                'expiry'         => $this->expiry,
                'cvv'            => $this->cvv,
                'cardPin'        => $this->cardPin,
                'postalCode'     => $this->postalCode,
            ]),
            VaultItemType::Contact => array_merge($base, [
                'fullName'     => $this->fullName,
                'email'        => $this->email,
                'phone'        => $this->phone,
                'addressLine1' => $this->addressLine1,
                'addressLine2' => $this->addressLine2,
                'city'         => $this->city,
                'state'        => $this->state,
                'country'      => $this->country,
                'postalCode'   => $this->postalCode,
            ]),
            VaultItemType::IdCard,
            VaultItemType::DriversLicense,
            VaultItemType::Passport,
            VaultItemType::Document => array_merge($base, [
                'documentNumber' => $this->documentNumber,
                'fullName'       => $this->fullName,
                'issuedBy'       => $this->issuedBy,
                'issuedDate'     => $this->issuedDate,
                'expiryDate'     => $this->expiryDate,
            ]),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(VaultItemType $type, array $payload): self
    {
        $data       = new self($type);
        $data->note = (string) ($payload['note'] ?? '');

        match ($type) {
            VaultItemType::Login      => self::fillLogin($data, $payload),
            VaultItemType::SecureNote => $data->secureNote = (string) ($payload['content'] ?? ''),
            VaultItemType::CreditCard => self::fillCreditCard($data, $payload),
            VaultItemType::Contact    => self::fillContact($data, $payload),
            default                   => self::fillDocument($data, $payload),
        };

        return $data;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function fillLogin(self $data, array $payload): void
    {
        $data->username = (string) ($payload['username'] ?? '');
        $data->password = (string) ($payload['password'] ?? '');
        /** @var mixed $rawWebsites */
        $rawWebsites = $payload['websites'] ?? [''];
        if (!is_array($rawWebsites) || $rawWebsites === []) {
            $data->websites = [''];
        } else {
            $data->websites = array_values(array_map(static fn (mixed $value): string => (string) $value, $rawWebsites));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function fillCreditCard(self $data, array $payload): void
    {
        $data->cardholderName = (string) ($payload['cardholderName'] ?? '');
        $data->cardNumber     = (string) ($payload['cardNumber'] ?? '');
        $data->expiry         = (string) ($payload['expiry'] ?? '');
        $data->cvv            = (string) ($payload['cvv'] ?? '');
        $data->cardPin        = (string) ($payload['cardPin'] ?? '');
        $data->postalCode     = (string) ($payload['postalCode'] ?? '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function fillContact(self $data, array $payload): void
    {
        $data->fullName     = (string) ($payload['fullName'] ?? '');
        $data->email        = (string) ($payload['email'] ?? '');
        $data->phone        = (string) ($payload['phone'] ?? '');
        $data->addressLine1 = (string) ($payload['addressLine1'] ?? '');
        $data->addressLine2 = (string) ($payload['addressLine2'] ?? '');
        $data->city         = (string) ($payload['city'] ?? '');
        $data->state        = (string) ($payload['state'] ?? '');
        $data->country      = (string) ($payload['country'] ?? '');
        $data->postalCode   = (string) ($payload['postalCode'] ?? '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function fillDocument(self $data, array $payload): void
    {
        $data->documentNumber = (string) ($payload['documentNumber'] ?? '');
        $data->fullName       = (string) ($payload['fullName'] ?? '');
        $data->issuedBy       = (string) ($payload['issuedBy'] ?? '');
        $data->issuedDate     = (string) ($payload['issuedDate'] ?? '');
        $data->expiryDate     = (string) ($payload['expiryDate'] ?? '');
    }
}
