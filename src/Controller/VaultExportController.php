<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Controller;

use DateTimeImmutable;
use LogicException;
use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemsExportedEvent;
use Nowo\VaultBundle\EventListener\VaultNoStoreResponseListener;
use Nowo\VaultBundle\Exception\VaultDecryptionFailedException;
use Nowo\VaultBundle\Form\VaultPersonalExportType;
use Nowo\VaultBundle\Routing\VaultRouteLoader;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultReauthenticationCheckerInterface;
use Nowo\VaultBundle\Security\VaultReauthProof;
use Nowo\VaultBundle\Security\VaultReauthProofValidator;
use Nowo\VaultBundle\Service\VaultPersonalExportStreamer;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Ui\VaultPageFactory;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function hash;
use function is_int;
use function max;

/**
 * Decrypted export of the user's personal vault behind a fresh re-authentication (1.16.0, contract §9). Registered only
 * with `export.enabled` (which requires `export.reauth_checker`).
 *
 * - GET: warning page (what is exported, the item count, the format); without a valid proof it redirects to the host
 *   re-authentication URL.
 * - POST + CSRF `vault_personal_export`: proof checks (§9.2), limits (413), cooldown (429), a decrypt pre-flight for
 *   small exports (503), the proof consumed, `ITEMS_EXPORTED` (a throwing listener refuses with 503), then the stream.
 *   Every refusal answers without saying which check failed; the log carries the reason code only.
 */
final class VaultExportController extends AbstractController
{
    use VaultPagesTrait;
    use VaultRevealResponsesTrait;

    /** Cache key prefix of the per-user cooldown. */
    private const COOLDOWN_KEY = 'nowo_vault_export_cooldown_';

    /**
     * Stores the collaborators and the `export.*` limits.
     *
     * @param list<string> $formats export.formats
     */
    public function __construct(
        private readonly VaultAccessCheckerInterface $accessChecker,
        private readonly VaultPersonalExportStreamer $streamer,
        private readonly VaultReauthProofValidator $proofValidator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly VaultRuntimeConfigProvider $runtimeConfig,
        private readonly CacheItemPoolInterface $cooldownPool,
        private readonly ?VaultReauthenticationCheckerInterface $reauthChecker = null,
        private readonly array $formats = ['json', 'csv'],
        private readonly int $maxItems = 5000,
        private readonly int $maxBytes = 52428800,
        private readonly int $cooldownSeconds = 600,
        private readonly bool $includeAttachments = true,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?Profiler $profiler = null,
        private readonly ?VaultPageFactory $pageFactory = null,
    ) {
    }

    /**
     * Warning page with the format choice and Download, or the re-authentication redirect.
     */
    #[Route(path: '/tools/vault/export', name: 'nowo_vault_personal_export', options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'personal_export'], methods: ['GET'])]
    public function page(Request $request): Response
    {
        $user    = $this->user();
        $checker = $this->checker();
        $routes  = $this->routes();
        $reason  = $this->proofValidator->validate($checker->getProof($user, $request, VaultReauthProof::PURPOSE_PERSONAL_EXPORT), UserIdResolver::getId($user), VaultReauthProof::PURPOSE_PERSONAL_EXPORT, $request);
        if ($reason !== null) {
            $this->refused($user, $reason);

            return $this->redirect($checker->getReauthenticationUrl($request, VaultReauthProof::PURPOSE_PERSONAL_EXPORT, $this->generateUrl($routes['personal_export']['name'])));
        }

        $form  = $this->form($routes);
        $pages = $this->pages();
        $count = $this->streamer->measure($user)['count'];

        return $this->render($this->templates()['export'], $pages->context([
            'page'               => $pages->export($routes, $form->createView()),
            'count'              => $count,
            'formats'            => $this->formats,
            'includeAttachments' => $this->includeAttachments,
            'routes'             => $routes,
        ]));
    }

    /**
     * Checks everything, audits, then streams the file.
     */
    #[Route(path: '/tools/vault/export', name: 'nowo_vault_personal_export_download', options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'personal_export_download'], methods: ['POST'])]
    public function download(Request $request): Response
    {
        $user = $this->user();
        $this->profiler?->disable();
        $userId = UserIdResolver::getId($user);

        $form = $this->form($this->routes());
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->refusal($user, 'invalid_request', Response::HTTP_FORBIDDEN);
        }
        $format = (string) $form->get('format')->getData();

        $checker = $this->checker();
        $proof   = $checker->getProof($user, $request, VaultReauthProof::PURPOSE_PERSONAL_EXPORT);
        $reason  = $this->proofValidator->validate($proof, $userId, VaultReauthProof::PURPOSE_PERSONAL_EXPORT, $request);
        if ($reason !== null || !$proof instanceof VaultReauthProof) {
            return $this->refusal($user, $reason ?? VaultReauthProofValidator::REASON_MISSING, Response::HTTP_FORBIDDEN);
        }

        $measure = $this->streamer->measure($user);
        if ($measure['count'] > $this->maxItems || $measure['bytes'] > $this->maxBytes) {
            return $this->refusal($user, 'too_large', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $now        = $this->now();
        $retryAfter = $this->cooldownRemaining($userId, $now);
        if ($retryAfter > 0) {
            return $this->refusal($user, 'cooldown', Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $retryAfter]);
        }

        if ($measure['count'] <= VaultPersonalExportStreamer::PREFLIGHT_MAX_ITEMS) {
            try {
                $this->streamer->preflight($user);
            } catch (VaultDecryptionFailedException) {
                return $this->refusal($user, 'decrypt_failed', Response::HTTP_SERVICE_UNAVAILABLE);
            }
        }

        // Single use, before the first byte: the host's consume() and the bundle's own replay list.
        $checker->consume($proof);
        $this->proofValidator->markConsumed($proof);

        $attachments = $format === 'json' && $this->includeAttachments;
        try {
            $this->eventDispatcher->dispatch(new VaultItemsExportedEvent($userId, $measure['count'], $format, $attachments, $proof->factors, $now), VaultEvents::ITEMS_EXPORTED);
        } catch (Throwable) {
            // No audit, no export.
            return $this->refusal($user, 'audit_failed', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $this->startCooldown($userId, $now);
        $this->logger()->info('Vault items exported.', ['bundle' => 'nowo_vault', 'action' => 'items_exported', 'user_id' => $userId, 'count' => $measure['count'], 'format' => $format]);

        $request->attributes->set(VaultNoStoreResponseListener::MAX_AGE_ZERO_ATTRIBUTE, true);
        $streamer = $this->streamer;
        $response = new StreamedResponse(static function () use ($streamer, $user, $format, $attachments, $now, $userId): void {
            $streamer->stream($user, $format === 'csv' ? 'csv' : 'json', $attachments, $now, $userId);
        });
        $response->headers->set('Content-Type', $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="vault-export-' . $now->format('Ymd') . '.' . $format . '"');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $this->sensitive($response);
    }

    /**
     * @param array<string, array{path: string, name: string}> $routes
     *
     * @return FormInterface<array<string, mixed>>
     */
    private function form(array $routes): FormInterface
    {
        return $this->createForm(VaultPersonalExportType::class, ['format' => $this->formats[0] ?? 'json'], [
            'formats' => $this->formats,
            'action'  => $this->generateUrl($routes['personal_export_download']['name']),
        ]);
    }

    private function user(): UserInterface
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface || !$this->accessChecker->canAccess($user)) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function checker(): VaultReauthenticationCheckerInterface
    {
        return $this->reauthChecker ?? throw new LogicException('The vault export needs export.reauth_checker.');
    }

    /**
     * Logs the refusal (reason code only) and answers without detail.
     *
     * @param array<string, string> $headers
     */
    private function refusal(UserInterface $user, string $reason, int $status, array $headers = []): Response
    {
        $this->refused($user, $reason);

        return $this->sensitive(new Response('', $status, $headers));
    }

    private function refused(UserInterface $user, string $reason): void
    {
        $this->logger()->notice('Vault export refused.', ['bundle' => 'nowo_vault', 'action' => 'export_refused', 'user_id' => UserIdResolver::getId($user), 'reason' => $reason]);
    }

    private function cooldownRemaining(?string $userId, DateTimeImmutable $now): int
    {
        $item  = $this->cooldownPool->getItem($this->cooldownKey($userId));
        $until = $this->cooldownSeconds > 0 && $item->isHit() ? $item->get() : null;

        return is_int($until) ? max(0, $until - $now->getTimestamp()) : 0;
    }

    private function startCooldown(?string $userId, DateTimeImmutable $now): void
    {
        if ($this->cooldownSeconds > 0) {
            $item = $this->cooldownPool->getItem($this->cooldownKey($userId));
            $item->set($now->getTimestamp() + $this->cooldownSeconds);
            $item->expiresAfter($this->cooldownSeconds);
            $this->cooldownPool->save($item);
        }
    }

    private function cooldownKey(?string $userId): string
    {
        return self::COOLDOWN_KEY . hash('sha256', (string) $userId);
    }

    /**
     * @return array<string, array{path: string, name: string}>
     */
    private function routes(): array
    {
        /* @var array<string, array{path: string, name: string}> */
        return $this->runtimeConfig->get()['routes'];
    }

    /**
     * @return array<string, string>
     */
    private function templates(): array
    {
        /* @var array<string, string> */
        return $this->runtimeConfig->get()['templates'];
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }

    private function logger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }
}
