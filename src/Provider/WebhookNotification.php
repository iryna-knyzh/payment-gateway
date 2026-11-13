<?php

declare(strict_types=1);

namespace App\Provider;

use Symfony\Component\Uid\Uuid;

/** A provider webhook after signature check and normalisation. */
final class WebhookNotification
{
    public function __construct(
        /** Provider's unique event id - used for de-duplication. */
        public readonly string $eventId,
        public readonly Uuid $paymentId,
        public readonly ?string $providerReference,
        public readonly ProviderPaymentStatus $status,
        public readonly ?string $failureReason = null,
    ) {
    }
}
