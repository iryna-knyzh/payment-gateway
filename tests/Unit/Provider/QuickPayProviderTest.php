<?php

declare(strict_types=1);

namespace App\Tests\Unit\Provider;

use App\Provider\Exception\InvalidWebhookSignatureException;
use App\Provider\Exception\ProviderOutcomeUnknownException;
use App\Provider\ProviderPaymentRequest;
use App\Provider\ProviderPaymentStatus;
use App\Provider\QuickPay\QuickPayProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class QuickPayProviderTest extends TestCase
{
    private const MERCHANT = 'm_1';
    private const SECRET = 'qp_secret';

    public function testCreatePaymentSendsSignedFormWithDecimalAmount(): void
    {
        $paymentId = Uuid::v7();
        $sent = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = $options['body'];

            return new MockResponse('{"transaction_id":"QP1","result_code":"100","message":"Pending"}');
        });

        $result = $this->provider($http)->createPayment(new ProviderPaymentRequest($paymentId, 1050, 'UAH', 'https://gw.test/cb'));

        self::assertIsString($sent);
        parse_str($sent, $form);
        self::assertSame('10.50', $form['amount'], 'QuickPay expects major units as a decimal string.');
        self::assertSame(
            hash_hmac('sha256', implode('|', [self::MERCHANT, $paymentId->toRfc4122(), '10.50', 'UAH']), self::SECRET),
            $form['signature'],
        );
        self::assertSame(ProviderPaymentStatus::Pending, $result->status);
        self::assertSame('QP1', $result->providerReference);
    }

    /**
     * @dataProvider provideResultCodes
     */
    public function testMapsResultCodes(string $code, ProviderPaymentStatus $expected): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode(['transaction_id' => 'QP1', 'result_code' => $code, 'message' => 'x'], \JSON_THROW_ON_ERROR)));

        self::assertSame($expected, $this->provider($http)->fetchStatus(Uuid::v7())->status);
    }

    /** @return iterable<array{string, ProviderPaymentStatus}> */
    public static function provideResultCodes(): iterable
    {
        yield 'approved' => ['000', ProviderPaymentStatus::Succeeded];
        yield 'pending' => ['100', ProviderPaymentStatus::Pending];
        yield 'insufficient funds' => ['051', ProviderPaymentStatus::Failed];
        yield 'never created' => ['404', ProviderPaymentStatus::Failed];
    }

    public function testDeclineComesWithHttp200(): void
    {
        $http = new MockHttpClient(new MockResponse('{"transaction_id":"QP1","result_code":"051","message":"Insufficient funds"}', ['http_code' => 200]));

        $result = $this->provider($http)->createPayment(new ProviderPaymentRequest(Uuid::v7(), 1013, 'UAH', 'https://gw.test/cb'));

        self::assertSame(ProviderPaymentStatus::Failed, $result->status);
        self::assertSame('QuickPay code 051: Insufficient funds', $result->failureReason);
    }

    public function testMissingResultCodeMeansOutcomeUnknown(): void
    {
        $http = new MockHttpClient(new MockResponse('{"transaction_id":"QP1"}'));

        $this->expectException(ProviderOutcomeUnknownException::class);

        $this->provider($http)->createPayment(new ProviderPaymentRequest(Uuid::v7(), 100, 'UAH', 'https://gw.test/cb'));
    }

    public function testParsesSignedWebhook(): void
    {
        $paymentId = Uuid::v7();
        $fields = ['notification_id' => 'n_1', 'order_id' => $paymentId->toRfc4122(), 'transaction_id' => 'QP1', 'result_code' => '000'];
        $fields['signature'] = hash_hmac('sha256', implode('|', $fields), self::SECRET);

        $notification = $this->provider()->parseWebhook(Request::create('/webhooks/quickpay', 'POST', $fields));

        self::assertSame('n_1', $notification->eventId);
        self::assertSame(ProviderPaymentStatus::Succeeded, $notification->status);
        self::assertTrue($paymentId->equals($notification->paymentId));
    }

    public function testRejectsWebhookWithChangedResultCode(): void
    {
        $fields = ['notification_id' => 'n_1', 'order_id' => Uuid::v7()->toRfc4122(), 'transaction_id' => 'QP1', 'result_code' => '005'];
        $fields['signature'] = hash_hmac('sha256', implode('|', $fields), self::SECRET);
        $fields['result_code'] = '000'; // attacker flips "declined" to "approved"

        $this->expectException(InvalidWebhookSignatureException::class);

        $this->provider()->parseWebhook(Request::create('/webhooks/quickpay', 'POST', $fields));
    }

    private function provider(?MockHttpClient $http = null): QuickPayProvider
    {
        return new QuickPayProvider($http ?? new MockHttpClient(), self::MERCHANT, self::SECRET);
    }
}
