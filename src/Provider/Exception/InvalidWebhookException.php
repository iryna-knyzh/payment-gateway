<?php

declare(strict_types=1);

namespace App\Provider\Exception;

/** The webhook is signed correctly but its body cannot be understood. */
class InvalidWebhookException extends ProviderException
{
}
