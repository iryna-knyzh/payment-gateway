<?php

declare(strict_types=1);

namespace App\Provider\Exception;

/** Signature is missing or wrong: the request may be forged and must be rejected. */
final class InvalidWebhookSignatureException extends ProviderException
{
}
