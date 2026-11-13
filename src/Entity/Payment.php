<?php

declare(strict_types=1);

namespace App\Entity;

use App\Payment\PaymentStatus;
use App\Repository\PaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payment')]
// The unique index is what really guarantees idempotency, even under concurrent requests.
#[ORM\UniqueConstraint(name: 'uniq_payment_idempotency_key', columns: ['idempotency_key'])]
#[ORM\Index(name: 'idx_payment_provider_reference', columns: ['provider_code', 'provider_reference'])]
class Payment
{
    /** UUID v7 is time-ordered: good for B-tree indexes and keyset pagination. */
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $idempotencyKey;

    /** sha256 of the request body: detects reuse of an idempotency key with different data. */
    #[ORM\Column(length: 64)]
    private string $requestFingerprint;

    /**
     * Integer minor units (kopecks/cents). Never float.
     * DBAL 4 hydrates BIGINT as int when the value fits into PHP_INT_MAX.
     */
    #[ORM\Column(type: Types::BIGINT)]
    private int $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 32)]
    private string $providerCode;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $providerReference = null;

    /** Managed by the "payment" state machine only - there is deliberately no public setter for business code. */
    #[ORM\Column(length: 16)]
    private string $status = 'new';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $idempotencyKey,
        string $requestFingerprint,
        int $amount,
        string $currency,
        string $providerCode,
    ) {
        $this->id = Uuid::v7();
        $this->idempotencyKey = $idempotencyKey;
        $this->requestFingerprint = $requestFingerprint;
        $this->amount = $amount;
        $this->currency = strtoupper($currency);
        $this->providerCode = $providerCode;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getRequestFingerprint(): string
    {
        return $this->requestFingerprint;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getProviderCode(): string
    {
        return $this->providerCode;
    }

    public function getProviderReference(): ?string
    {
        return $this->providerReference;
    }

    public function assignProviderReference(string $providerReference): void
    {
        if (null !== $this->providerReference && $this->providerReference !== $providerReference) {
            throw new \LogicException(\sprintf('Payment %s already has provider reference "%s".', $this->id, $this->providerReference));
        }

        $this->providerReference = $providerReference;
        $this->touch();
    }

    /** Used by the workflow marking store. */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Used by the workflow marking store. Business code applies transitions
     * through WorkflowInterface instead of calling this directly.
     *
     * @param array<string, mixed> $context
     */
    public function setStatus(string $status, array $context = []): void
    {
        $this->status = PaymentStatus::from($status)->value;
        $this->touch();
    }

    public function getPaymentStatus(): PaymentStatus
    {
        return PaymentStatus::from($this->status);
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function setFailureReason(?string $failureReason): void
    {
        $this->failureReason = null === $failureReason ? null : mb_substr($failureReason, 0, 255);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
