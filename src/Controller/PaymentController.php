<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\CreatePaymentRequest;
use App\Api\PaymentView;
use App\Entity\Payment;
use App\Payment\IdempotencyKeyReusedException;
use App\Payment\PaymentService;
use App\Provider\ProviderRegistry;
use App\Repository\PaymentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

#[Route('/api/payments')]
final class PaymentController extends AbstractController
{
    private const MAX_PAGE_SIZE = 100;

    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly PaymentRepository $payments,
        private readonly ProviderRegistry $providers,
    ) {
    }

    #[Route('', name: 'payment_create', methods: ['POST'])]
    public function create(Request $request, #[MapRequestPayload] CreatePaymentRequest $body): JsonResponse
    {
        $idempotencyKey = (string) $request->headers->get('Idempotency-Key', '');

        if ('' === $idempotencyKey || \strlen($idempotencyKey) > 255) {
            return $this->error('idempotency_key_required', 'Header "Idempotency-Key" (1-255 chars) is required.', Response::HTTP_BAD_REQUEST);
        }

        \assert(null !== $body->amount && null !== $body->currency && null !== $body->provider);

        if (!$this->providers->has($body->provider)) {
            return $this->error('unknown_provider', \sprintf('Unknown provider "%s". Available: %s.', $body->provider, implode(', ', $this->providers->codes())), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->paymentService->create($idempotencyKey, $body->amount, $body->currency, $body->provider);
        } catch (IdempotencyKeyReusedException $e) {
            return $this->error('idempotency_key_reused', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $response = $this->json(PaymentView::fromEntity($result->payment), $result->created ? Response::HTTP_CREATED : Response::HTTP_OK);

        if (!$result->created) {
            $response->headers->set('Idempotent-Replayed', 'true');
        }

        return $response;
    }

    #[Route('/{id}', name: 'payment_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $payment = $this->payments->find(Uuid::fromString($id));

        if (null === $payment) {
            return $this->error('not_found', 'Payment not found.', Response::HTTP_NOT_FOUND);
        }

        return $this->json(PaymentView::fromEntity($payment));
    }

    /** GET /api/payments?limit=20&after=<id of the last item from the previous page> */
    #[Route('', name: 'payment_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = max(1, min(self::MAX_PAGE_SIZE, $request->query->getInt('limit', 20)));
        $after = $request->query->getString('after');

        if ('' !== $after && !Uuid::isValid($after)) {
            return $this->error('invalid_cursor', 'Parameter "after" must be a payment id.', Response::HTTP_BAD_REQUEST);
        }

        $rows = $this->payments->findPageAfter('' === $after ? null : Uuid::fromString($after), $limit);
        $hasMore = \count($rows) > $limit;
        $page = \array_slice($rows, 0, $limit);
        $last = end($page);

        return $this->json([
            'data' => array_map(PaymentView::fromEntity(...), $page),
            'next_cursor' => $hasMore && $last instanceof Payment ? $last->getId()->toRfc4122() : null,
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return $this->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
