<?php

declare(strict_types=1);

namespace App\Webhook;

use App\Entity\WebhookEvent;
use App\Message\ProcessWebhookEvent;
use App\Provider\WebhookNotification;
use App\Repository\WebhookEventRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Stores a verified webhook and queues it for processing.
 *
 * Why not process it right in the HTTP request?
 *  - providers wait only a few seconds and retry on timeout;
 *  - a slow DB or a bug must not make us lose the notification:
 *    once it is stored, processing can be retried as many times as needed.
 */
final class WebhookInbox
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $doctrine,
        private readonly WebhookEventRepository $events,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * @return bool false if this event was already received (provider redelivery)
     */
    public function receive(string $providerCode, WebhookNotification $notification, string $rawPayload): bool
    {
        if ($this->events->exists($providerCode, $notification->eventId)) {
            return false;
        }

        $event = new WebhookEvent($providerCode, $notification, $rawPayload);

        try {
            $this->entityManager->persist($event);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // The same webhook was delivered twice at the same moment.
            $this->doctrine->resetManager();

            return false;
        }

        // Note: if the process dies between flush() and dispatch(), the event stays
        // unprocessed. The production-grade fix is a transactional outbox or a
        // periodic job re-queuing events with processed_at IS NULL.
        $this->bus->dispatch(new ProcessWebhookEvent($event->getId()->toRfc4122()));

        return true;
    }
}
