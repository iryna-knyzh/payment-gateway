<?php

declare(strict_types=1);

namespace App\Provider\Exception;

/**
 * The provider definitely refused the request (4xx, validation error, declined).
 * It is safe to mark the payment as failed.
 */
final class ProviderRejectedException extends ProviderException
{
}
