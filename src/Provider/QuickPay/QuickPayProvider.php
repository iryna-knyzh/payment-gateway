<?php

declare(strict_types=1);

namespace App\Provider\QuickPay;

use App\Payment\Money\MinorUnits;
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

/**
 * "QuickPay": a typical legacy acquirer API - deliberately different from FakeBank.
 *
 *  - form-encoded requests, amounts as decimal strings in major units ("10.50")
 *  - every request and webhook is signed: HMAC-SHA256 over fields joined with "|"
 *  - HTTP 200 even for declined payments; the result is in "result_code":
 *      000 = approved, 100 = pending, 404 = order not found, anything else = declined
 */
final class QuickPayProvider implements PaymentProviderInterface
{
    private const CODE_APPROVED = '000';
    private const CODE_PENDING = '100';
    private const CODE_NOT_FOUND = '404';

    public function __construct(
        #[Target('quickpay.client')]
        private readonly HttpClientInterface $client,
        #[Autowire(env: 'QUICKPAY_MERCHANT_ID')]
        private readonly string $merchantId,
        #[Autowire(env: 'QUICKPAY_SECRET')]
        private readonly string $secret,
    ) {
    }

    public static function getCode(): string
    {
        return 'quickpay';
    }

    public function createPayment(ProviderPaymentRequest $request): ProviderPaymentResult
    {
        $fields = [
            'merchant_id' => $this->merchantId,
            'order_id' => $request->paymentId->toRfc4122(),
            'amount' => MinorUnits::toDecimalString($request->amount, $request->currency),
            'currency' => $request->currency,
        ];
        $fields['signature'] = $this->sign($fields);
        $fields['callback_url'] = $request->callbackUrl;

        return $this->send('api/pay', $fields);
    }

    public function fetchStatus(Uuid $paymentId): ProviderPaymentResult
    {
        $fields = [
            'merchant_id' => $this->merchantId,
            'order_id' => $paymentId->toRfc4122(),
        ];
        $fields['signature'] = $this->sign($fields);

        return $this->send('api/status', $fields, notFoundMeansNeverCreated: true);
    }

    public function parseWebhook(Request $request): WebhookNotification
    {
        $fields = [
            'notification_id' => $request->request->getString('notification_id'),
            'order_id' => $request->request->getString('order_id'),
            'transaction_id' => $request->request->getString('transaction_id'),
            'result_code' => $request->request->getString('result_code'),
        ];
        $signature = $request->request->getString('signature');

        if ('' === $this->secret || '' === $signature || !hash_equals($this->sign($fields), $signature)) {
            throw new InvalidWebhookSignatureException('Invalid QuickPay webhook signature.');
        }

        if ('' === $fields['notification_id'] || !Uuid::isValid($fields['order_id'])) {
            throw new InvalidWebhookException('QuickPay webhook misses notification_id or order_id.');
        }

        $result = $this->mapResult($fields['result_code'], $fields['transaction_id'], $request->request->getString('message'));

        return new WebhookNotification(
            $fields['notification_id'],
            Uuid::fromString($fields['order_id']),
            $result->providerReference,
            $result->status,
            $result->failureReason,
        );
    }

    /**
     * @param array<string, string> $fields
     */
    private function send(string $url, array $fields, bool $notFoundMeansNeverCreated = false): ProviderPaymentResult
    {
        try {
            $response = $this->client->request('POST', $url, ['body' => $fields]);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 500) {
                throw ProviderOutcomeUnknownException::create(self::getCode(), 'HTTP '.$statusCode);
            }

            if ($statusCode >= 400) {
                throw new ProviderRejectedException('QuickPay rejected the request with HTTP '.$statusCode);
            }

            $data = $response->toArray();
        } catch (HttpClientException $e) {
            throw ProviderOutcomeUnknownException::create(self::getCode(), $e->getMessage(), $e);
        }

        $code = $data['result_code'] ?? null;

        if (!\is_string($code)) {
            throw ProviderOutcomeUnknownException::create(self::getCode(), 'Response misses result_code.');
        }

        if (self::CODE_NOT_FOUND === $code && $notFoundMeansNeverCreated) {
            return new ProviderPaymentResult(ProviderPaymentStatus::Failed, null, 'Payment was never created at QuickPay.');
        }

        $transactionId = \is_string($data['transaction_id'] ?? null) ? $data['transaction_id'] : '';
        $message = \is_string($data['message'] ?? null) ? $data['message'] : '';

        return $this->mapResult($code, $transactionId, $message);
    }

    private function mapResult(string $code, string $transactionId, string $message): ProviderPaymentResult
    {
        $reference = '' === $transactionId ? null : $transactionId;

        return match ($code) {
            self::CODE_APPROVED => new ProviderPaymentResult(ProviderPaymentStatus::Succeeded, $reference),
            self::CODE_PENDING => new ProviderPaymentResult(ProviderPaymentStatus::Pending, $reference),
            default => new ProviderPaymentResult(
                ProviderPaymentStatus::Failed,
                $reference,
                \sprintf('QuickPay code %s: %s', $code, '' === $message ? 'declined' : $message),
            ),
        };
    }

    /**
     * @param array<string, string> $fields signed in the given order
     */
    private function sign(array $fields): string
    {
        return hash_hmac('sha256', implode('|', $fields), $this->secret);
    }
}
