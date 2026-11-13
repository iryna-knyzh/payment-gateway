<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\WebhookEvent;
use App\Message\ProcessWebhookEvent;

final class WebhookTest extends ApiTestCase
{
    private string $paymentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->providerApi()->willReturnJson(['id' => 'fb_charge_1', 'status' => 'PENDING'], 201);
        $this->paymentId = $this->createPayment(['amount' => 1050, 'currency' => 'UAH', 'provider' => 'fakebank'])['id'];
    }

    public function testWebhookIsStoredAndProcessedAsynchronously(): void
    {
        $this->sendFakeBankWebhook('evt_1', $this->paymentId, 'APPROVED');

        self::assertResponseIsSuccessful();
        self::assertSame('accepted', $this->responseJson()['status']);

        // Accepted, but not processed yet: the HTTP request only stores and queues it.
        self::assertSame('processing', $this->findPayment($this->paymentId)->getStatus());
        self::assertInstanceOf(ProcessWebhookEvent::class, $this->queuedMessages()[0]->getMessage());

        $this->runWorker();

        self::assertSame('succeeded', $this->findPayment($this->paymentId)->getStatus());
    }

    public function testDuplicateWebhookIsAcceptedButProcessedOnce(): void
    {
        $this->sendFakeBankWebhook('evt_1', $this->paymentId, 'APPROVED');
        self::assertSame('accepted', $this->responseJson()['status']);
        $this->runWorker();

        $this->sendFakeBankWebhook('evt_1', $this->paymentId, 'APPROVED');

        self::assertResponseIsSuccessful(); // otherwise the provider keeps retrying
        self::assertSame('duplicate', $this->responseJson()['status']);
        self::assertSame([], $this->queuedMessages(), 'A duplicate must not be queued again.');
        self::assertCount(1, $this->entityManager()->getRepository(WebhookEvent::class)->findAll());
    }

    public function testLateFailureDoesNotOverrideSuccess(): void
    {
        $this->sendFakeBankWebhook('evt_1', $this->paymentId, 'APPROVED');
        $this->runWorker();
        $this->sendFakeBankWebhook('evt_2', $this->paymentId, 'DECLINED');
        $this->runWorker();

        $payment = $this->findPayment($this->paymentId);
        self::assertSame('succeeded', $payment->getStatus());
        self::assertNull($payment->getFailureReason());
    }

    public function testDeclineWebhookFailsPayment(): void
    {
        $this->sendFakeBankWebhook('evt_1', $this->paymentId, 'DECLINED');
        $this->runWorker();

        self::assertSame('failed', $this->findPayment($this->paymentId)->getStatus());
    }

    public function testInvalidSignatureIsRejected(): void
    {
        $this->sendFakeBankWebhook('evt_1', $this->paymentId, 'APPROVED', signature: str_repeat('0', 64));

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $this->queuedMessages());
        self::assertSame('processing', $this->findPayment($this->paymentId)->getStatus());
    }

    public function testUnknownProvider(): void
    {
        $this->client->request('POST', '/webhooks/paypal', [], [], [], '{}');

        self::assertResponseStatusCodeSame(404);
    }

    public function testQuickPayFormWebhook(): void
    {
        $this->providerApi()->willReturnJson(['transaction_id' => 'QP1', 'result_code' => '100', 'message' => 'Pending']);
        $paymentId = $this->createPayment(['amount' => 2500, 'currency' => 'UAH', 'provider' => 'quickpay'], 'key-qp')['id'];

        $fields = ['notification_id' => 'n_1', 'order_id' => $paymentId, 'transaction_id' => 'QP1', 'result_code' => '000'];
        $fields['signature'] = hash_hmac('sha256', implode('|', $fields), self::QUICKPAY_SECRET);
        $this->client->request('POST', '/webhooks/quickpay', $fields);
        self::assertResponseIsSuccessful();

        $this->runWorker();

        self::assertSame('succeeded', $this->findPayment($paymentId)->getStatus());
    }
}
