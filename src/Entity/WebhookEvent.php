<?php

declare(strict_types=1);

namespace App\Entity;

use App\Provider\ProviderPaymentStatus;
use App\Provider\WebhookNotification;
use App\Repository\WebhookEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * "Inbox" of incoming provider webhooks.
 *
 * The webhook is stored first and processed asynchronously. The unique key
 * (provider_code, external_event_id) makes redelivered webhooks harmless.
 */
#[ORM\Entity(repositoryClass: WebhookEventRepository::class)]
#[ORM\Table(name: 'webhook_event')]
#[ORM\UniqueConstraint(name: 'uniq_webhook_event_provider_event', columns: ['provider_code', 'external_event_id'])]
class WebhookEvent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 32)]
    private string $providerCode;

    #[ORM\Column(length: 128)]
    private string $externalEventId;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $paymentId;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $providerReference;

    #[ORM\Column(length: 16)]
    private string $providerStatus;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $failureReason;

    /** Raw body exactly as received - useful for audits and debugging disputes with providers. */
    #[ORM\Column(type: Types::TEXT)]
    private string $rawPayload;

    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    public function __construct(string $providerCode, WebhookNotification $notification, string $rawPayload)
    {
        $this->id = Uuid::v7();
        $this->providerCode = $providerCode;
        $this->externalEventId = $notification->eventId;
        $this->paymentId = $notification->paymentId;
        $this->providerReference = $notification->providerReference;
        $this->providerStatus = $notification->status->value;
        $this->failureReason = $notification->failureReason;
        $this->rawPayload = $rawPayload;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProviderCode(): string
    {
        return $this->providerCode;
    }

    public function getExternalEventId(): string
    {
        return $this->externalEventId;
    }

    public function getPaymentId(): Uuid
    {
        return $this->paymentId;
    }

    public function getProviderReference(): ?string
    {
        return $this->providerReference;
    }

    public function getProviderStatus(): ProviderPaymentStatus
    {
        return ProviderPaymentStatus::from($this->providerStatus);
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getRawPayload(): string
    {
        return $this->rawPayload;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function isProcessed(): bool
    {
        return null !== $this->processedAt;
    }

    public function markProcessed(): void
    {
        $this->processedAt = new \DateTimeImmutable();
    }
}
