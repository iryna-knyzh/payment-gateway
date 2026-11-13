<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Payment;
use App\Tests\Support\MockProviderApi;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

abstract class ApiTestCase extends WebTestCase
{
    protected const FAKEBANK_WEBHOOK_SECRET = 'test_fakebank_webhook_secret';
    protected const QUICKPAY_SECRET = 'test_quickpay_secret';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Keep the same container between requests so the mocked provider API
        // survives. Note: services tagged "kernel.reset" (EntityManager, the
        // in-memory transport) are STILL reset before every request, so tests
        // call runWorker() before sending the next request.
        $this->client->disableReboot();

        $em = $this->entityManager();
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function providerApi(): MockProviderApi
    {
        return static::getContainer()->get(MockProviderApi::class);
    }

    protected function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    protected function createPayment(array $body, ?string $idempotencyKey = 'key-1'): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if (null !== $idempotencyKey) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        $this->client->request('POST', '/api/payments', [], [], $headers, json_encode($body, \JSON_THROW_ON_ERROR));

        return $this->responseJson();
    }

    /** @return array<string, mixed> */
    protected function getJson(string $url): array
    {
        $this->client->request('GET', $url);

        return $this->responseJson();
    }

    /** @return array<string, mixed> */
    protected function responseJson(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    protected function sendFakeBankWebhook(string $eventId, string $paymentId, string $status, ?string $signature = null): void
    {
        $body = json_encode([
            'event_id' => $eventId,
            'type' => 'charge.updated',
            'charge' => ['id' => 'fb_charge_1', 'merchant_reference' => $paymentId, 'status' => $status],
        ], \JSON_THROW_ON_ERROR);

        $this->client->request('POST', '/webhooks/fakebank', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FAKEBANK_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, self::FAKEBANK_WEBHOOK_SECRET),
        ], $body);
    }

    /**
     * Messages waiting in the "async" queue, INCLUDING delayed ones.
     *
     * InMemoryTransport::get() (Symfony 6.4) hides messages with a DelayStamp
     * until the real delay has passed, so pending = sent - acknowledged.
     *
     * @return list<Envelope>
     */
    protected function queuedMessages(): array
    {
        $transport = $this->asyncTransport();
        $acknowledged = array_map(self::messageId(...), [...$transport->getAcknowledged()]);

        return array_values(array_filter(
            [...$transport->getSent()],
            static fn (Envelope $e): bool => !\in_array(self::messageId($e), $acknowledged, true),
        ));
    }

    /**
     * Acts as `messenger:consume async` with time fast-forwarded: handles every
     * queued message (delays ignored), including messages dispatched by handlers.
     */
    protected function runWorker(int $maxRounds = 10): void
    {
        $transport = $this->asyncTransport();
        $bus = static::getContainer()->get(MessageBusInterface::class);

        for ($round = 0; $round < $maxRounds; ++$round) {
            $envelopes = $this->queuedMessages();

            if ([] === $envelopes) {
                return;
            }

            foreach ($envelopes as $envelope) {
                $transport->ack($envelope);
                $bus->dispatch($envelope->with(new ReceivedStamp('async')));
            }
        }
    }

    private static function messageId(Envelope $envelope): mixed
    {
        return $envelope->last(TransportMessageIdStamp::class)?->getId();
    }

    protected function findPayment(string $id): Payment
    {
        $em = $this->entityManager();
        $em->clear();

        return $em->find(Payment::class, $id) ?? throw new \LogicException('Payment not found: '.$id);
    }

    private function asyncTransport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }
}
