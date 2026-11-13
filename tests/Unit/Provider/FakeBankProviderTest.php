<?php

declare(strict_types=1);

namespace App\Tests\Unit\Provider;

use App\Provider\Exception\InvalidWebhookException;
use App\Provider\Exception\InvalidWebhookSignatureException;
use App\Provider\Exception\ProviderOutcomeUnknownException;
use App\Provider\Exception\ProviderRejectedException;
use App\Provider\FakeBank\FakeBankProvider;
use App\Provider\ProviderPaymentRequest;
use App\Provider\ProviderPaymentStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class FakeBankProviderTest extends TestCase
{
    private const SECRET = 'whsec_test';

    public function testCreatePaymentSendsMinorUnitsAndMapsPending(): void
    {
        $paymentId = Uuid::v7();
        $http = new MockHttpClient(new MockResponse('{"id":"fb_1","status":"PENDING"}', ['http_code' => 201]), 'https://fakebank.test/api/');

        $result = $this->provider($http)->createPayment(new ProviderPaymentRequest($paymentId, 1050, 'UAH', 'https://gw.test/webhooks/fakebank'));

        self::assertSame(ProviderPaymentStatus::Pending, $result->status);
        self::assertSame('fb_1', $result->providerReference);
        self::assertSame(1, $http->getRequestsCount());
    }

    /**
     * @dataProvider provideStatuses
     */
    public function testMapsProviderStatuses(string $providerStatus, ProviderPaymentStatus $expected): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode(['id' => 'fb_1', 'status' => $providerStatus, 'decline_reason' => 'insufficient_funds'], \JSON_THROW_ON_ERROR)));

        $result = $this->provider($http)->fetchStatus(Uuid::v7());

        self::assertSame($expected, $result->status);
    }

    /** @return iterable<array{string, ProviderPaymentStatus}> */
    public static function provideStatuses(): iterable
    {
        yield ['PENDING', ProviderPaymentStatus::Pending];
        yield ['APPROVED', ProviderPaymentStatus::Succeeded];
        yield ['DECLINED', ProviderPaymentStatus::Failed];
    }

    public function testUnknownStatusIsNotTreatedAsFailure(): void
    {
        $http = new MockHttpClient(new MockResponse('{"id":"fb_1","status":"UNDER_REVIEW"}'));

        $this->expectException(ProviderOutcomeUnknownException::class);

        $this->provider($http)->fetchStatus(Uuid::v7());
    }

    public function testTimeoutMeansOutcomeUnknown(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['error' => 'Idle timeout reached']));

        $this->expectException(ProviderOutcomeUnknownException::class);

        $this->provider($http)->createPayment(new ProviderPaymentRequest(Uuid::v7(), 100, 'UAH', 'https://gw.test/cb'));
    }

    public function testServerErrorMeansOutcomeUnknown(): void
    {
        $http = new MockHttpClient(new MockResponse('Bad gateway', ['http_code' => 502]));

        $this->expectException(ProviderOutcomeUnknownException::class);

        $this->provider($http)->createPayment(new ProviderPaymentRequest(Uuid::v7(), 100, 'UAH', 'https://gw.test/cb'));
    }

    public function testClientErrorIsADefiniteRejection(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":{"message":"currency not supported"}}', ['http_code' => 422]));

        $this->expectException(ProviderRejectedException::class);
        $this->expectExceptionMessage('currency not supported');

        $this->provider($http)->createPayment(new ProviderPaymentRequest(Uuid::v7(), 100, 'XXX', 'https://gw.test/cb'));
    }

    public function testStatusOfPaymentTheProviderNeverSawIsFailed(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":{"message":"not found"}}', ['http_code' => 404]));

        $result = $this->provider($http)->fetchStatus(Uuid::v7());

        self::assertSame(ProviderPaymentStatus::Failed, $result->status);
    }

    public function testParsesCorrectlySignedWebhook(): void
    {
        $paymentId = Uuid::v7();
        $body = json_encode(['event_id' => 'evt_1', 'charge' => ['id' => 'fb_1', 'merchant_reference' => $paymentId->toRfc4122(), 'status' => 'APPROVED']], \JSON_THROW_ON_ERROR);

        $notification = $this->provider()->parseWebhook($this->webhookRequest($body, hash_hmac('sha256', $body, self::SECRET)));

        self::assertSame('evt_1', $notification->eventId);
        self::assertTrue($paymentId->equals($notification->paymentId));
        self::assertSame(ProviderPaymentStatus::Succeeded, $notification->status);
    }

    public function testRejectsWebhookWithWrongSignature(): void
    {
        $body = '{"event_id":"evt_1","charge":{"id":"fb_1","merchant_reference":"x","status":"APPROVED"}}';

        $this->expectException(InvalidWebhookSignatureException::class);

        $this->provider()->parseWebhook($this->webhookRequest($body, hash_hmac('sha256', $body, 'attacker-guess')));
    }

    public function testRejectsTamperedWebhookBody(): void
    {
        $original = '{"event_id":"evt_1","charge":{"id":"fb_1","status":"DECLINED"}}';
        $tampered = str_replace('DECLINED', 'APPROVED', $original);

        $this->expectException(InvalidWebhookSignatureException::class);

        $this->provider()->parseWebhook($this->webhookRequest($tampered, hash_hmac('sha256', $original, self::SECRET)));
    }

    public function testRejectsEverythingWhenSecretIsNotConfigured(): void
    {
        $body = '{"event_id":"evt_1"}';

        $this->expectException(InvalidWebhookSignatureException::class);

        // With an empty secret anyone could compute hash_hmac('sha256', $body, '').
        (new FakeBankProvider(new MockHttpClient(), ''))->parseWebhook($this->webhookRequest($body, hash_hmac('sha256', $body, '')));
    }

    public function testRejectsSignedWebhookWithoutPaymentReference(): void
    {
        $body = '{"event_id":"evt_1","charge":{"id":"fb_1","status":"APPROVED"}}';

        $this->expectException(InvalidWebhookException::class);

        $this->provider()->parseWebhook($this->webhookRequest($body, hash_hmac('sha256', $body, self::SECRET)));
    }

    private function provider(?MockHttpClient $http = null): FakeBankProvider
    {
        return new FakeBankProvider($http ?? new MockHttpClient(), self::SECRET);
    }

    private function webhookRequest(string $body, string $signature): Request
    {
        return Request::create('/webhooks/fakebank', 'POST', server: ['HTTP_X_FAKEBANK_SIGNATURE' => $signature], content: $body);
    }
}
