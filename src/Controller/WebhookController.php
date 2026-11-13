<?php

declare(strict_types=1);

namespace App\Controller;

use App\Provider\Exception\InvalidWebhookException;
use App\Provider\Exception\InvalidWebhookSignatureException;
use App\Provider\ProviderRegistry;
use App\Webhook\WebhookInbox;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Entry point for provider callbacks. Must be fast and must never lose a
 * notification: verify -> store -> queue -> 200. Real work happens in a worker.
 */
#[AsController]
final class WebhookController
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly WebhookInbox $inbox,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/webhooks/{provider}', name: 'webhook_receive', methods: ['POST'])]
    public function __invoke(string $provider, Request $request): JsonResponse
    {
        if (!$this->providers->has($provider)) {
            return new JsonResponse(['error' => 'unknown_provider'], Response::HTTP_NOT_FOUND);
        }

        try {
            $notification = $this->providers->get($provider)->parseWebhook($request);
        } catch (InvalidWebhookSignatureException $e) {
            $this->logger->warning('Rejected webhook from {provider}: {reason}', ['provider' => $provider, 'reason' => $e->getMessage()]);

            return new JsonResponse(['error' => 'invalid_signature'], Response::HTTP_UNAUTHORIZED);
        } catch (InvalidWebhookException $e) {
            return new JsonResponse(['error' => 'invalid_payload', 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $isNew = $this->inbox->receive($provider, $notification, $request->getContent());

        // A duplicate also gets 200: otherwise the provider would keep retrying it.
        return new JsonResponse(['status' => $isNew ? 'accepted' : 'duplicate']);
    }
}
