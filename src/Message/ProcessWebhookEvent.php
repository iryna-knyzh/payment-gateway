<?php

declare(strict_types=1);

namespace App\Message;

/** Process a stored webhook (App\Entity\WebhookEvent) asynchronously. */
final class ProcessWebhookEvent
{
    public function __construct(
        public readonly string $webhookEventId,
    ) {
    }
}
