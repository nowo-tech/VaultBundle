<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Controller;

use LogicException;
use Nowo\VaultBundle\Ui\VaultPageFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Page view-model factory of the vault controllers (REQ-UI-003): the injected service, or — for a controller built by
 * hand — a factory on the router of its container.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Controller\AbstractController
 */
trait VaultPagesTrait
{
    private function pages(): VaultPageFactory
    {
        if ($this->pageFactory instanceof VaultPageFactory) {
            return $this->pageFactory;
        }

        $router = $this->container->get('router');
        if (!$router instanceof UrlGeneratorInterface) {
            throw new LogicException('The vault controllers need the router (or a VaultPageFactory) to build their pages.');
        }

        return new VaultPageFactory($router);
    }
}
