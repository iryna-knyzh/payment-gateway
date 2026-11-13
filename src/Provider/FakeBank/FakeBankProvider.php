<?php

declare(strict_types=1);

namespace App\Provider\FakeBank;

use App\Provider\Exception\InvalidWebhookException;
use App\Provider\Exception\InvalidWebhookSignatureException;
use App\Provider\Exception\ProviderOutcomeUnknownException;
use App\Provider\Exception\ProviderRejectedException;
use App\Provider\PaymentProviderInterface;
use App\Provider\ProviderPaymentRequest;
use App\Provider\ProviderPaymentResult;
use App\Provider\ProviderPaymentStatus;
use App\Provider\WebhookNotification;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * "FakeBank": a modern JSON API.
 *
 *  - amounts in minor units (1050 = 10.50)
 *  - Bearer token auth (configured on the scoped HTTP client)
 *  - statuses: PENDING / APPROVED / DECLINED
 *  - webhook signature: X-FakeBank-Signature = hex(HMAC-SHA256(raw body, webhook secret))
 */
final class FakeBankProvider implements PaymentProviderInterface
{
    private const SIGNATURE_HEADER = 'X-FakeBank-Signature';

    public function __construct(
        #[Target('fakebank.client')]
        private readonly HttpClientInterface $client,
        #[Autowire(env: 'FAKEBANK_WEBHOOK_SECRET')]
        private readonly string $webhookSecret,
    ) {
    }

    public static function getCode(): string
    {
        return 'fakebank';
    }

    public function createPayment(ProviderPaymentRequest $request): ProviderPaymentResult
    {
        return $this->send('POST', 'v1/charges', [
            'json' => [
                'amount' => $request->amount,
                'currency' => $request->currency,
                'merchant_reference' => $request->paymentId->toRfc4122(),
                'callback_url' => $request->callbackUrl,
            ],
        ]);
    }

    public function fetchStatus(Uuid $paymentId): ProviderPaymentResult
    {
        return $this->send('GET', 'v1/charges/by-reference/'.$paymentId->toRfc4122(), [], notFoundMeansNeverCreated: true);
    }

    public function parseWebhook(Request $request): WebhookNotification
    {
        $rawBody = $request->getContent();
        $signature = (string) $request->headers->get(self::SIGNATURE_HEADER, '');

        // An empty secret would let anyone compute a "valid" signature.
        if ('' === $this->webhookSecret || '' === $signature) {
            throw new InvalidWebhookSignatureException('Missing webhook signature or secret.');
        }

        // hash_equals() compares in constant time - no timing attacks on the signature.
        if (!hash_equals(hash_hmac('sha256', $rawBody, $this->webhookSecret), $signature)) {
            throw new InvalidWebhookSignatureException('Invalid FakeBank webhook signature.');
        }

        try {
            $data = json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidWebhookException('FakeBank webhook is not valid JSON.', 0, $e);
        }

        $eventId = $data['event_id'] ?? null;
        $charge = $data['charge'] ?? null;
        $reference = \is_array($charge) ? ($charge['merchant_reference'] ?? null) : null;

        if (!\is_string($eventId) || !\is_array($charge) || !\is_string($reference) || !Uuid::isValid($reference)) {
            throw new InvalidWebhookException('FakeBank webhook misses event_id or charge.merchant_reference.');
        }

        $result = $this->mapCharge($charge);

        return new WebhookNotification($eventId, Uuid::fromString($reference), $result->providerReference, $result->status, $result->failureReason);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $url, array $options, bool $notFoundMeansNeverCreated = false): ProviderPaymentResult
    {
        try {
            $response = $this->client->request($method, $url, $options);
            $statusCode = $response->getStatusCode();

            if (404 === $statusCode && $notFoundMeansNeverCreated) {
                return new ProviderPaymentResult(ProviderPaymentStatus::Failed, null, 'Payment was never created at FakeBank.');
            }

            if ($statusCode >= 500) {
                throw ProviderOutcomeUnknownException::create(self::getCode(), 'HTTP '.$statusCode);
            }

            if ($statusCode >= 400) {
                throw new ProviderRejectedException($this->errorMessage($response, $statusCode));
            }

            return $this->mapCharge($response->toArray());
        } catch (HttpClientException $e) {
            // Timeout, DNS, connection reset, invalid JSON...
            throw ProviderOutcomeUnknownException::create(self::getCode(), $e->getMessage(), $e);
        }
    }

    /**
     * @param array<mixed> $charge
     */
    private function mapCharge(array $charge): ProviderPaymentResult
    {
        $id = $charge['id'] ?? null;
        $status = $charge['status'] ?? null;

        if (!\is_string($id) || !\is_string($status)) {
            throw ProviderOutcomeUnknownException::create(self::getCode(), 'Response misses charge id or status.');
        }

        return match ($status) {
            'PENDING' => new ProviderPaymentResult(ProviderPaymentStatus::Pending, $id),
            'APPROVED' => new ProviderPaymentResult(ProviderPaymentStatus::Succeeded, $id),
            'DECLINED' => new ProviderPaymentResult(
                ProviderPaymentStatus::Failed,
                $id,
                \is_string($charge['decline_reason'] ?? null) ? $charge['decline_reason'] : 'declined',
            ),
            // A status we do not know is NOT a failure - we just don't know what happened.
            default => throw ProviderOutcomeUnknownException::create(self::getCode(), \sprintf('Unexpected status "%s".', $status)),
        };
    }

    private function errorMessage(ResponseInterface $response, int $statusCode): string
    {
        try {
            $data = $response->toArray(false);
            $message = $data['error']['message'] ?? $data['error'] ?? null;

            if (\is_string($message)) {
                return 'FakeBank rejected the request: '.$message;
            }
        } catch (HttpClientException) {
        }

        return 'FakeBank rejected the request with HTTP '.$statusCode;
    }
}
