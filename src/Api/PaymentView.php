<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Payment;
use App\Payment\Money\MinorUnits;

/** Public JSON representation of a payment. The entity itself is never serialised directly. */
final class PaymentView
{
    /**
     * @return array<string, mixed>
     */
    public static function fromEntity(Payment $payment): array
    {
        return [
            'id' => $payment->getId()->toRfc4122(),
            'status' => $payment->getStatus(),
            'amount' => $payment->getAmount(),
            'amount_formatted' => MinorUnits::toDecimalString($payment->getAmount(), $payment->getCurrency()),
            'currency' => $payment->getCurrency(),
            'provider' => $payment->getProviderCode(),
            'provider_reference' => $payment->getProviderReference(),
            'failure_reason' => $payment->getFailureReason(),
            'created_at' => $payment->getCreatedAt()->format(\DATE_ATOM),
            'updated_at' => $payment->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
