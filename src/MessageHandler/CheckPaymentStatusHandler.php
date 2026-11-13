<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Payment;
use App\Message\CheckPaymentStatus;
use App\Payment\PaymentStatusUpdater;
use App\Provider\ProviderPaymentStatus;
use App\Provider\ProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Polls the provider for payments whose outcome is unknown.
 *
 * If the provider itself is down, ProviderOutcomeUnknownException bubbles up
 * and Messenger retries with exponential backoff (see messenger.yaml).
 */
#[AsMessageHandler]
final class CheckPaymentStatusHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProviderRegistry $providers,
        private readonly PaymentStatusUpdater $statusUpdater,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(CheckPaymentStatus $message): void
    {
        $payment = $this->entityManager->find(Payment::class, Uuid::fromString($message->paymentId));

        // Already settled, e.g. a webhook arrived in the meantime.
        if (null === $payment || $payment->getPaymentStatus()->isFinal()) {
            return;
        }

        $result = $this->providers->get($payment->getProviderCode())->fetchStatus($payment->getId());
        $payment = $this->statusUpdater->apply($payment->getId(), $result->status, $result->providerReference, $result->failureReason);

        if (ProviderPaymentStatus::Pending !== $result->status) {
            return;
        }

        if ($message->attempt >= CheckPaymentStatus::MAX_ATTEMPTS) {
            // In production: alert + manual reconciliation with the provider's report.
            $this->logger->error('Payment {payment} is still pending after {attempts} status checks.', [
                'payment' => (string) $payment->getId(),
                'attempts' => $message->attempt,
            ]);

            return;
        }

        $this->bus->dispatch(
            new CheckPaymentStatus($message->paymentId, $message->attempt + 1),
            [new DelayStamp(60_000 * $message->attempt)],
        );
    }
}
