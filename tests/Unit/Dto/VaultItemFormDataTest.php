<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Dto;

use Nowo\VaultBundle\Dto\VaultItemFormData;
use Nowo\VaultBundle\Enum\VaultItemType;
use PHPUnit\Framework\TestCase;

final class VaultItemFormDataTest extends TestCase
{
    public function testLoginPayloadRoundTrip(): void
    {
        $data           = new VaultItemFormData(VaultItemType::Login);
        $data->title    = 'GitHub';
        $data->username = 'user';
        $data->password = 'secret';
        $data->websites = ['https://github.com'];

        $restored = VaultItemFormData::fromPayload(VaultItemType::Login, $data->toPayload());

        self::assertSame('user', $restored->username);
        self::assertSame('secret', $restored->password);
    }

    public function testAllItemTypePayloadsRoundTrip(): void
    {
        $login           = new VaultItemFormData(VaultItemType::Login);
        $login->username = 'u';
        $login->password = 'p';
        self::assertSame('u', VaultItemFormData::fromPayload(VaultItemType::Login, $login->toPayload())->username);

        $note             = new VaultItemFormData(VaultItemType::SecureNote);
        $note->secureNote = 'hidden';
        self::assertSame('hidden', VaultItemFormData::fromPayload(VaultItemType::SecureNote, $note->toPayload())->secureNote);

        $card             = new VaultItemFormData(VaultItemType::CreditCard);
        $card->cardNumber = '4111';
        self::assertSame('4111', VaultItemFormData::fromPayload(VaultItemType::CreditCard, $card->toPayload())->cardNumber);

        $contact        = new VaultItemFormData(VaultItemType::Contact);
        $contact->email = 'a@b.c';
        self::assertSame('a@b.c', VaultItemFormData::fromPayload(VaultItemType::Contact, $contact->toPayload())->email);

        $doc                 = new VaultItemFormData(VaultItemType::Passport);
        $doc->documentNumber = 'X123';
        self::assertSame('X123', VaultItemFormData::fromPayload(VaultItemType::Passport, $doc->toPayload())->documentNumber);
    }

    public function testLoginWebsitesDefaultWhenEmpty(): void
    {
        $data = VaultItemFormData::fromPayload(VaultItemType::Login, ['username' => 'u']);
        self::assertSame([''], $data->websites);
    }
}
