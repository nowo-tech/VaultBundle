<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Dto;

use Nowo\VaultBundle\Dto\VaultItemFormData;
use Nowo\VaultBundle\Enum\VaultItemType;
use PHPUnit\Framework\TestCase;

/**
 * 1.15.2: secret fields per type, never rendered, kept when submitted empty, cleared on request.
 */
final class VaultItemFormDataSecretsTest extends TestCase
{
    private const MARK = 'placeholder-marker';

    public function testSecretFieldsPerType(): void
    {
        self::assertSame(['password', 'note'], VaultItemFormData::secretPayloadFields(VaultItemType::Login));
        self::assertSame(['content', 'note'], VaultItemFormData::secretPayloadFields(VaultItemType::SecureNote));
        self::assertSame(['cardNumber', 'cvv', 'cardPin', 'note'], VaultItemFormData::secretPayloadFields(VaultItemType::CreditCard));
        self::assertSame(['note'], VaultItemFormData::secretPayloadFields(VaultItemType::Contact));
        foreach ([VaultItemType::IdCard, VaultItemType::DriversLicense, VaultItemType::Passport, VaultItemType::Document] as $type) {
            self::assertSame(['documentNumber', 'note'], VaultItemFormData::secretPayloadFields($type));
        }
    }

    public function testClearSecretsEmptiesEverySecretOfEachType(): void
    {
        $stored = [
            'note'           => self::MARK, 'password' => self::MARK, 'username' => 'user', 'content' => self::MARK,
            'cardNumber'     => self::MARK, 'cvv' => self::MARK, 'cardPin' => self::MARK, 'cardholderName' => 'Holder',
            'documentNumber' => self::MARK, 'fullName' => 'Name',
        ];

        foreach (VaultItemType::cases() as $type) {
            $data = VaultItemFormData::fromPayload($type, $stored);
            $data->clearSecrets();
            $payload = $data->toPayload();

            foreach (VaultItemFormData::secretPayloadFields($type) as $key) {
                self::assertSame('', $payload[$key], $type->value . '.' . $key);
            }
            self::assertNotContains(self::MARK, $payload, $type->value);
        }

        $login = VaultItemFormData::fromPayload(VaultItemType::Login, $stored);
        $login->clearSecrets();
        self::assertSame('user', $login->username);
    }

    public function testStoredSecretFieldsAndKeep(): void
    {
        $stored = ['username' => 'u', 'password' => self::MARK, 'note' => '', 'websites' => []];

        self::assertSame(['password'], VaultItemFormData::storedSecretFields(VaultItemType::Login, $stored));
        self::assertSame([], VaultItemFormData::storedSecretFields(VaultItemType::Login, ['password' => 42]));

        // Empty submit keeps the stored value; a typed value replaces it; an explicit clear empties it.
        $kept = VaultItemFormData::keepStoredSecrets(VaultItemType::Login, ['username' => 'u', 'password' => '', 'note' => ''], $stored, []);
        self::assertSame(self::MARK, $kept['password']);
        self::assertSame('', $kept['note']);

        $typed    = 'typed-' . bin2hex(random_bytes(4));
        $replaced = VaultItemFormData::keepStoredSecrets(VaultItemType::Login, ['password' => $typed, 'note' => ''], $stored, []);
        self::assertSame($typed, $replaced['password']);

        $cleared = VaultItemFormData::keepStoredSecrets(VaultItemType::Login, ['password' => '', 'note' => ''], $stored, ['password']);
        self::assertSame('', $cleared['password']);
    }
}
