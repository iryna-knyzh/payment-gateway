<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\Payment;
use App\Message\CheckPaymentStatus;
use App\Provider\Exception\ProviderOutcomeUnknownException;
use App\Provider\Exception\ProviderRejectedException;
use App\Provider\ProviderPaymentRequest;
use App\Provider\ProviderPaymentStatus;
use App\Provider\ProviderRegistry;
use App\Repository\PaymentRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class PaymentService
{
    /** First status check after a timeout. Providers usually settle within seconds. */
    public const STATUS_CHECK_DELAY_MS = 30_000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $doctrine,
        private readonly PaymentRepository $payments,
        private readonly ProviderRegistry $providers,
        private readonly PaymentStatusUpdater $statusUpdater,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'APP_PUBLIC_URL')]
        private readonly string $publicUrl,
    ) {
    }

    public function create(string $idempotencyKey, int $amount, string $currency, string $providerCode): CreatePaymentResult
    {
        $currency = strtoupper($currency);
        $fingerprint = hash('sha256', json_encode([$amount, $currency, $providerCode], \JSON_THROW_ON_ERROR));

        // 1. Replay: the client retried a request we have already seen.
        $existing = $this->payments->findOneByIdempotencyKey($idempotencyKey);

        if (null !== $existing) {
            return $this->replay($existing, $fingerprint);
        }

        $provider = $this->providers->get($providerCode);
        $payment = new Payment($idempotencyKey, $fingerprint, $amount, $currency, $providerCode);

        // 2. Save the payment BEFORE calling the provider: if we crash mid-way,
        //    there is a record to reconcile instead of money charged for nothing.
        try {
            $this->entityManager->persist($payment);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Two concurrent requests with the same key: both passed step 1, the unique
            // index let only one insert through. After a DB error Doctrine closes the
            // EntityManager, so it must be reset before it can be used again.
            $this->doctrine->resetManager();

            $winner = $this->payments->findOneByIdempotencyKey($idempotencyKey)
                ?? throw new \LogicException('Unique violation on idempotency key, but no payment found.');

            return $this->replay($winner, $fingerprint);
        }

        // 3. Call the provider OUTSIDE of any DB transaction: holding row locks
        //    during a network call of up to 10 s would kill throughput.
        $request = new ProviderPaymentRequest(
            $payment->getId(),
            $amount,
            $currency,
            rtrim($this->publicUrl, '/').'/webhooks/'.$providerCode,
        );

        try {
            $result = $provider->createPayment($request);
            $payment = $this->statusUpdater->apply($payment->getId(), $result->status, $result->providerReference, $result->failureReason);
        } catch (ProviderRejectedException $e) {
            $payment = $this->statusUpdater->apply($payment->getId(), ProviderPaymentStatus::Failed, null, $e->getMessage());
        } catch (ProviderOutcomeUnknownException $e) {
            // The money MAY have been charged. Do not fail the payment - find out later.
            $this->logger->warning('Payment {payment}: outcome unknown, scheduling a status check.', [
                'payment' => (string) $payment->getId(),
                'exception' => $e,
            ]);

            $payment = $this->statusUpdater->apply($payment->getId(), ProviderPaymentStatus::Pending);
            $this->bus->dispatch(
                new CheckPaymentStatus($payment->getId()->toRfc4122()),
                [new DelayStamp(self::STATUS_CHECK_DELAY_MS)],
            );
        }

        return new CreatePaymentResult($payment, true);
    }

    private function replay(Payment $payment, string $fingerprint): CreatePaymentResult
    {
        if (!hash_equals($payment->getRequestFingerprint(), $fingerprint)) {
            throw new IdempotencyKeyReusedException('This Idempotency-Key was already used with a different request body.');
        }

        return new CreatePaymentResult($payment, false);
    }
}
