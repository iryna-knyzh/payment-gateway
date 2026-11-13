<?php

declare(strict_types=1);

namespace App\Payment;

/**
 * Places of the "payment" state machine (config/packages/workflow.yaml).
 */
enum PaymentStatus: string
{
    case New = 'new';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return self::Succeeded === $this || self::Failed === $this;
    }
}
