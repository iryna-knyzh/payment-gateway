<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\Payment;

final class CreatePaymentResult
{
    public function __construct(
        public readonly Payment $payment,
        /** false = an earlier request with the same Idempotency-Key is being replayed */
        public readonly bool $created,
    ) {
    }
}
