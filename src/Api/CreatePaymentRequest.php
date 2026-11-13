<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** Body of POST /api/payments, mapped and validated by #[MapRequestPayload]. */
final class CreatePaymentRequest
{
    public function __construct(
        /** Minor units: 1050 = 10.50 UAH. Integers only - no floats for money. */
        #[Assert\NotNull]
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(10_000_000_00)]
        public readonly ?int $amount = null,

        #[Assert\NotBlank]
        #[Assert\Regex('/^[A-Z]{3}$/', message: 'Currency must be an ISO 4217 code, e.g. "UAH".')]
        public readonly ?string $currency = null,

        #[Assert\NotBlank]
        public readonly ?string $provider = null,
    ) {
    }
}
