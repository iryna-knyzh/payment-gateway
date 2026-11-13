<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\Payment;
use App\Provider\ProviderPaymentStatus;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * The ONLY place where a payment status changes.
 *
 * The same payment can be updated concurrently by:
 *  - the API request that created it (provider's synchronous answer),
 *  - a webhook worker,
 *  - a status-check worker.
 * Each update runs in a transaction and takes a row lock (SELECT ... FOR UPDATE),
 * so updates are serialised and every one of them sees fresh data.
 * The state machine then decides whether the change is allowed at all.
 */
final class PaymentStatusUpdater
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowInterface $paymentStateMachine,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function apply(
        Uuid $paymentId,
        ProviderPaymentStatus $status,
        ?string $providerReference = null,
        ?string $failureReason = null,
    ): Payment {
        return $this->entityManager->wrapInTransaction(function () use ($paymentId, $status, $providerReference, $failureReason): Payment {
            $payment = $this->entityManager->find(Payment::class, $paymentId)
                ?? throw new PaymentNotFoundException(\sprintf('Payment %s not found.', $paymentId));

            // refresh() with a lock re-reads the row: find() alone could return a stale
            // object from the identity map that another process has already changed.
            $this->entityManager->refresh($payment, LockMode::PESSIMISTIC_WRITE);

            if (null !== $providerReference && null === $payment->getProviderReference()) {
                $payment->assignProviderReference($providerReference);
            }

            match ($status) {
                ProviderPaymentStatus::Pending => $this->transition($payment, 'submit'),
                ProviderPaymentStatus::Succeeded => $this->transition($payment, 'submit', 'succeed'),
                ProviderPaymentStatus::Failed => $this->fail($payment, $failureReason),
            };

            return $payment;
        });
    }

    private function fail(Payment $payment, ?string $reason): void
    {
        if ($this->paymentStateMachine->can($payment, 'fail')) {
            $payment->setFailureReason($reason ?? 'unknown');
        }

        $this->transition($payment, 'fail');
    }

    private function transition(Payment $payment, string ...$transitions): void
    {
        foreach ($transitions as $transition) {
            if ($this->paymentStateMachine->can($payment, $transition)) {
                $this->paymentStateMachine->apply($payment, $transition);

                continue;
            }

            // Not an error: duplicates and out-of-order updates are normal with providers.
            // E.g. "failed" arrives after "succeeded" - the final status must not change.
            $this->logger->info('Ignored transition "{transition}" for payment {payment} in status "{status}".', [
                'transition' => $transition,
                'payment' => (string) $payment->getId(),
                'status' => $payment->getStatus(),
            ]);
        }
    }
}
