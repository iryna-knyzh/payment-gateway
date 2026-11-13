<?php

declare(strict_types=1);

namespace App\Provider;

use App\Provider\Exception\InvalidWebhookException;
use App\Provider\Exception\InvalidWebhookSignatureException;
use App\Provider\Exception\ProviderOutcomeUnknownException;
use App\Provider\Exception\ProviderRejectedException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * The platform's single contract for every payment provider (Adapter pattern).
 *
 * Each provider has its own API format, amount format, status codes and
 * signature scheme; an implementation translates all of that into the
 * platform's language. Adding a provider = adding one class (Open/Closed).
 *
 * Every implementation is tagged automatically and collected by ProviderRegistry.
 */
#[AutoconfigureTag('app.payment_provider')]
interface PaymentProviderInterface
{
    /** Unique provider code used in API requests and webhook URLs, e.g. "fakebank". */
    public static function getCode(): string;

    /**
     * @throws ProviderRejectedException       the provider definitely refused the request (payment failed)
     * @throws ProviderOutcomeUnknownException timeout / 5xx / garbage: the payment MAY have been created
     */
    public function createPayment(ProviderPaymentRequest $request): ProviderPaymentResult;

    /**
     * Looks the payment up by OUR payment id, so it works even when the
     * create call timed out and we never received the provider's reference.
     *
     * @throws ProviderOutcomeUnknownException
     */
    public function fetchStatus(Uuid $paymentId): ProviderPaymentResult;

    /**
     * Verifies the signature and normalises the provider's webhook.
     *
     * @throws InvalidWebhookSignatureException
     * @throws InvalidWebhookException
     */
    public function parseWebhook(Request $request): WebhookNotification;
}
