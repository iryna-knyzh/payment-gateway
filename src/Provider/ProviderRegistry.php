<?php

declare(strict_types=1);

namespace App\Provider;

use App\Provider\Exception\UnknownProviderException;
use Symfony\Component\DependencyInjection\Attribute\TaggedLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Picks the provider implementation at runtime (Strategy pattern).
 *
 * The service locator is lazy: only the provider actually requested is
 * instantiated. #[TaggedLocator] works in Symfony 5.3+; in 6.4+/7 the newer
 * equivalent is #[AutowireLocator].
 */
final class ProviderRegistry
{
    /**
     * @param ServiceProviderInterface<PaymentProviderInterface> $providers
     */
    public function __construct(
        #[TaggedLocator('app.payment_provider', defaultIndexMethod: 'getCode')]
        private readonly ServiceProviderInterface $providers,
    ) {
    }

    public function has(string $code): bool
    {
        return $this->providers->has($code);
    }

    public function get(string $code): PaymentProviderInterface
    {
        if (!$this->providers->has($code)) {
            throw new UnknownProviderException(\sprintf('Unknown payment provider "%s".', $code));
        }

        return $this->providers->get($code);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->providers->getProvidedServices());
    }
}
