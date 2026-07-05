<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixtures extends Fixture
{
    public const DEMO_USER_REFERENCE = 'demo-user';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $demo = new User();
        $demo
            ->setEmail('demo@example.com')
            ->setPassword($this->passwordHasher->hashPassword($demo, 'demo'))
            ->setRoles(['ROLE_USER']);

        $manager->persist($demo);
        $this->addReference(self::DEMO_USER_REFERENCE, $demo);
        $manager->flush();
    }
}
