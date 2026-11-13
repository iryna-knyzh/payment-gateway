<?php

declare(strict_types=1);

namespace App\Provider;

/**
 * Normalised payment status. Every provider maps its own codes
 * ("APPROVED", "000", "DECLINED", "051", ...) to one of these.
 */
enum ProviderPaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
