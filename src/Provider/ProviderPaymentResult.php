<?php

declare(strict_types=1);

namespace App\Provider;

final class ProviderPaymentResult
{
    public function __construct(
        public readonly ProviderPaymentStatus $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $failureReason = null,
    ) {
    }
}
