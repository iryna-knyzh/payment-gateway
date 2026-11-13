<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Ask the provider for the real status of a payment whose outcome is unknown
 * (timeout on create, or still pending without a webhook).
 */
final class CheckPaymentStatus
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        public readonly string $paymentId,
        public readonly int $attempt = 1,
    ) {
    }
}
