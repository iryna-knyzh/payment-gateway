<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\WebhookEvent;
use App\Message\ProcessWebhookEvent;
use App\Payment\PaymentNotFoundException;
use App\Payment\PaymentStatusUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class ProcessWebhookEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentStatusUpdater $statusUpdater,
    ) {
    }

    public function __invoke(ProcessWebhookEvent $message): void
    {
        $event = $this->entityManager->find(WebhookEvent::class, Uuid::fromString($message->webhookEventId));

        // Messages can be delivered more than once (at-least-once delivery).
        if (null === $event || $event->isProcessed()) {
            return;
        }

        try {
            $this->statusUpdater->apply(
                $event->getPaymentId(),
                $event->getProviderStatus(),
                $event->getProviderReference(),
                $event->getFailureReason(),
            );
        } catch (PaymentNotFoundException $e) {
            // Retrying will not help - send straight to the "failed" transport for a human to look at.
            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }

        $event->markProcessed();
        $this->entityManager->flush();
    }
}
