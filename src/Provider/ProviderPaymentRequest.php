<?php

declare(strict_types=1);

namespace App\Provider;

use Symfony\Component\Uid\Uuid;

final class ProviderPaymentRequest
{
    public function __construct(
        /** Sent to the provider as the merchant reference / order id. */
        public readonly Uuid $paymentId,
        /** Minor units. */
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $callbackUrl,
    ) {
    }
}
