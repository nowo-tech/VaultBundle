<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function is_string;
use function str_starts_with;

/**
 * Marks every response of a bundle route (manage UI, field reveal, runtime config, browser extension API) as not
 * storable: `Cache-Control: no-store, private` and `Pragma: no-cache` (1.15.2). Vault pages and JSON carry item data
 * that must not be kept by browsers (including the back/forward cache), proxies or CDNs.
 *
 * Runs after Symfony's session listener (priority -1000), which would otherwise rewrite Cache-Control.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -1100)]
final readonly class VaultNoStoreResponseListener
{
    private const CONTROLLER_NAMESPACE = 'Nowo\\VaultBundle\\Controller\\';

    /**
     * Adds the no-store headers when the request was handled by a bundle controller.
     */
    public function __invoke(ResponseEvent $event): void
    {
        $controller = $event->getRequest()->attributes->get('_controller');
        if (!is_string($controller) || !str_starts_with($controller, self::CONTROLLER_NAMESPACE)) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('Pragma', 'no-cache');
    }
}
