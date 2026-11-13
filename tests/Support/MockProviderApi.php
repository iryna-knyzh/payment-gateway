<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Replaces the network in tests (framework.http_client.mock_response_factory).
 *
 * Tests queue the responses the "provider" will return; every outgoing
 * request is recorded so tests can assert what was sent.
 */
final class MockProviderApi
{
    /** @var list<MockResponse> */
    private array $queue = [];

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $requests = [];

    public function willReturn(MockResponse ...$responses): void
    {
        array_push($this->queue, ...$responses);
    }

    /** @param array<string, mixed> $body */
    public function willReturnJson(array $body, int $status = 200): void
    {
        $this->willReturn(new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]));
    }

    public function willTimeOut(): void
    {
        // Simulates a transport-level failure: no HTTP status at all.
        $this->willReturn(new MockResponse('', ['error' => 'Idle timeout reached for "https://provider.test".']));
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

        if ([] === $this->queue) {
            throw new \LogicException(\sprintf('Unexpected HTTP request %s %s: no mock response queued.', $method, $url));
        }

        return array_shift($this->queue);
    }

    /** @return array{method: string, url: string, options: array<string, mixed>} */
    public function lastRequest(): array
    {
        return $this->requests[array_key_last($this->requests)] ?? throw new \LogicException('No request was sent.');
    }
}
