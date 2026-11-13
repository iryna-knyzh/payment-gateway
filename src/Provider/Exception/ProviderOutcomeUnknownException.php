<?php

declare(strict_types=1);

namespace App\Provider\Exception;

/**
 * Timeout, connection error, 5xx or an unreadable response.
 *
 * The most dangerous case in payments: the money MAY have been charged.
 * Never treat it as a failure - keep the payment in "processing" and ask the
 * provider for the real status later.
 */
final class ProviderOutcomeUnknownException extends ProviderException
{
    public static function create(string $providerCode, string $reason, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('Outcome of the request to "%s" is unknown: %s', $providerCode, $reason), 0, $previous);
    }
}
