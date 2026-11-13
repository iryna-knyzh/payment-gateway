<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Payment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    public function findOneByIdempotencyKey(string $idempotencyKey): ?Payment
    {
        return $this->findOneBy(['idempotencyKey' => $idempotencyKey]);
    }

    /**
     * Keyset (cursor) pagination, newest first.
     *
     * Unlike LIMIT/OFFSET, the database jumps straight to the cursor via the
     * primary key index, so page 10 000 is as fast as page 1.
     * UUID v7 ids are time-ordered, so ordering by id == ordering by creation time.
     *
     * @return list<Payment> up to $limit + 1 rows; the extra row tells the caller there is a next page
     */
    public function findPageAfter(?Uuid $after, int $limit): array
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.id', 'DESC')
            ->setMaxResults($limit + 1);

        if (null !== $after) {
            $qb->andWhere('p.id < :after')
                ->setParameter('after', $after, UuidType::NAME);
        }

        /** @var list<Payment> */
        return $qb->getQuery()->getResult();
    }
}
