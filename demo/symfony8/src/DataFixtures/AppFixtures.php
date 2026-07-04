<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $demo = new User();
        $demo
            ->setEmail('demo@example.com')
            ->setPassword('')
            ->setRoles(['ROLE_USER']);

        $manager->persist($demo);
        $manager->flush();
    }
}
