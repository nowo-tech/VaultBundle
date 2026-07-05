<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Service\VaultItemCreator;

final class VaultBrowserExtensionFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private readonly VaultItemCreator $itemCreator,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        /** @var User $user */
        $user = $this->getReference('demo-user', User::class);

        $this->itemCreator->create(
            VaultItemType::Login,
            'Demo broad login',
            $user,
            [
                'username' => 'broad-user',
                'password' => 'broad-pass',
                'websites' => ['https://example.com'],
            ],
        );
        $this->itemCreator->create(
            VaultItemType::Login,
            'Demo specific login',
            $user,
            [
                'username' => 'specific-user',
                'password' => 'specific-pass',
                'websites' => ['https://login.example.com'],
            ],
        );
    }

    public function getDependencies(): array
    {
        return [AppFixtures::class];
    }
}
