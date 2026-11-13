<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Message\CheckPaymentStatus;
use App\Payment\PaymentService;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class CreatePaymentTest extends ApiTestCase
{
    public function testCreatesPaymentAtProvider(): void
    {
        $this->providerApi()->willReturnJson(['id' => 'fb_1', 'status' => 'PENDING'], 201);

        $payment = $this->createPayment(['amount' => 1050, 'currency' => 'UAH', 'provider' => 'fakebank']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('processing', $payment['status']);
        self::assertSame(1050, $payment['amount']);
        self::assertSame('10.50', $payment['amount_formatted']);
        self::assertSame('fb_1', $payment['provider_reference']);

        $request = $this->providerApi()->lastRequest();
        self::assertSame('POST', $request['method']);
        // Base URI path ("/api/") must be preserved - see the note in http_client.yaml.
        self::assertSame('https://fakebank.test/api/v1/charges', $request['url']);
        self::assertSame(
            ['amount' => 1050, 'currency' => 'UAH', 'merchant_reference' => $payment['id'], 'callback_url' => 'https://gateway.test/webhooks/fakebank'],
            json_decode($request['options']['body'], true),
        );
    }

    public function testProviderApprovesImmediately(): void
    {
        $this->providerApi()->willReturnJson(['transaction_id' => 'QP1', 'result_code' => '000', 'message' => 'Approved']);

        $payment = $this->createPayment(['amount' => 1050, 'currency' => 'UAH', 'provider' => 'quickpay']);

        self::assertSame('succeeded', $payment['status']);
        self::assertSame('https://quickpay.test/gw/api/pay', $this->providerApi()->lastRequest()['url']);
    }

    public function testProviderDeclines(): void
    {
        $this->providerApi()->willReturnJson(['id' => 'fb_1', 'status' => 'DECLINED', 'decline_reason' => 'insufficient_funds'], 201);

        $payment = $this->createPayment(['amount' => 1013, 'currency' => 'UAH', 'provider' => 'fakebank']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('failed', $payment['status']);
        self::assertSame('insufficient_funds', $payment['failure_reason']);
    }

    public function testRetryWithSameIdempotencyKeyDoesNotChargeTwice(): void
    {
        $this->providerApi()->willReturnJson(['id' => 'fb_1', 'status' => 'PENDING'], 201);
        $body = ['amount' => 1050, 'currency' => 'UAH', 'provider' => 'fakebank'];

        $first = $this->createPayment($body, 'order-42');
        $second = $this->createPayment($body, 'order-42');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Idempotent-Replayed', 'true');
        self::assertSame($first['id'], $second['id']);
        self::assertCount(1, $this->providerApi()->requests, 'The provider must be called only once.');
    }

    public function testSameIdempotencyKeyWithDifferentBodyIsRejected(): void
    {
        $this->providerApi()->willReturnJson(['id' => 'fb_1', 'status' => 'PENDING'], 201);

        $this->createPayment(['amount' => 1050, 'currency' => 'UAH', 'provider' => 'fakebank'], 'order-42');
        $response = $this->createPayment(['amount' => 99999, 'currency' => 'UAH', 'provider' => 'fakebank'], 'order-42');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('idempotency_key_reused', $response['error']['code']);
    }

    public function testIdempotencyKeyIsRequired(): void
    {
        $response = $this->createPayment(['amount' => 1050, 'currency' => 'UAH', 'provider' => 'fakebank'], null);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('idempotency_key_required', $response['error']['code']);
        self::assertSame([], $this->providerApi()->requests);
    }

    /**
     * @dataProvider provideInvalidBodies
     *
     * @param array<string, mixed> $body
     */
    public function testValidatesRequest(array $body): void
    {
        $this->createPayment($body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->providerApi()->requests);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function provideInvalidBodies(): iterable
    {
        yield 'negative amount' => [['amount' => -100, 'currency' => 'UAH', 'provider' => 'fakebank']];
        yield 'zero amount' => [['amount' => 0, 'currency' => 'UAH', 'provider' => 'fakebank']];
        yield 'bad currency' => [['amount' => 100, 'currency' => 'hryvnia', 'provider' => 'fakebank']];
        yield 'missing provider' => [['amount' => 100, 'currency' => 'UAH']];
        yield 'unknown provider' => [['amount' => 100, 'currency' => 'UAH', 'provider' => 'paypal']];
    }

    public function testTimeoutKeepsPaymentProcessingAndSchedulesStatusCheck(): void
    {
        $this->providerApi()->willTimeOut();

        $payment = $this->createPayment(['amount' => 1042, 'currency' => 'UAH', 'provider' => 'fakebank']);

        // NOT "failed": the provider may have charged the card before the connection dropped.
        self::assertResponseStatusCodeSame(201);
        self::assertSame('processing', $payment['status']);

        $messages = $this->queuedMessages();
        self::assertCount(1, $messages);
        self::assertInstanceOf(CheckPaymentStatus::class, $messages[0]->getMessage());
        self::assertSame($payment['id'], $messages[0]->getMessage()->paymentId);
        self::assertSame(PaymentService::STATUS_CHECK_DELAY_MS, $messages[0]->last(DelayStamp::class)?->getDelay());
    }

    public function testStatusCheckResolvesTimedOutPayment(): void
    {
        $this->providerApi()->willTimeOut();
        $payment = $this->createPayment(['amount' => 1042, 'currency' => 'UAH', 'provider' => 'fakebank']);

        // The charge did go through at the provider.
        $this->providerApi()->willReturnJson(['id' => 'fb_77', 'status' => 'APPROVED']);
        $this->runWorker();

        self::assertSame(
            'https://fakebank.test/api/v1/charges/by-reference/'.$payment['id'],
            $this->providerApi()->lastRequest()['url'],
        );
        $stored = $this->findPayment($payment['id']);
        self::assertSame('succeeded', $stored->getStatus());
        self::assertSame('fb_77', $stored->getProviderReference());
    }

    public function testStatusCheckFailsPaymentProviderNeverReceived(): void
    {
        $this->providerApi()->willTimeOut();
        $payment = $this->createPayment(['amount' => 1042, 'currency' => 'UAH', 'provider' => 'fakebank']);

        $this->providerApi()->willReturnJson(['error' => ['message' => 'not found']], 404);
        $this->runWorker();

        self::assertSame('failed', $this->findPayment($payment['id'])->getStatus());
    }

    public function testStillPendingPaymentIsCheckedAgain(): void
    {
        $this->providerApi()->willTimeOut();
        $payment = $this->createPayment(['amount' => 1042, 'currency' => 'UAH', 'provider' => 'fakebank']);

        $this->providerApi()->willReturnJson(['id' => 'fb_1', 'status' => 'PENDING']);
        $this->runWorker(maxRounds: 1);

        $messages = $this->queuedMessages();
        self::assertCount(1, $messages);
        self::assertSame(2, $messages[0]->getMessage()->attempt);
        self::assertSame('processing', $this->findPayment($payment['id'])->getStatus());
    }
}
