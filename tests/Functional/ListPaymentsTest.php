<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class ListPaymentsTest extends ApiTestCase
{
    public function testKeysetPaginationWalksThroughAllPaymentsWithoutDuplicates(): void
    {
        $created = [];
        for ($i = 1; $i <= 5; ++$i) {
            $this->providerApi()->willReturnJson(['id' => 'fb_'.$i, 'status' => 'PENDING'], 201);
            $created[] = $this->createPayment(['amount' => 100 * $i, 'currency' => 'UAH', 'provider' => 'fakebank'], 'key-'.$i)['id'];
        }

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $this->getJson('/api/payments?limit=2'.(null === $cursor ? '' : '&after='.$cursor));
            $seen = [...$seen, ...array_column($page['data'], 'id')];
            $cursor = $page['next_cursor'];
            ++$pages;
        } while (null !== $cursor && $pages < 10);

        self::assertSame(3, $pages); // 2 + 2 + 1
        self::assertCount(5, $seen);
        self::assertEqualsCanonicalizing($created, $seen);

        $sorted = $seen;
        rsort($sorted);
        self::assertSame($sorted, $seen, 'Newest (largest UUID v7) first.');
    }

    public function testRejectsInvalidCursor(): void
    {
        $this->getJson('/api/payments?after=1 OR 1=1');

        self::assertResponseStatusCodeSame(400);
    }

    public function testShowAndNotFound(): void
    {
        $this->providerApi()->willReturnJson(['id' => 'fb_1', 'status' => 'PENDING'], 201);
        $id = $this->createPayment(['amount' => 100, 'currency' => 'UAH', 'provider' => 'fakebank'])['id'];

        self::assertSame($id, $this->getJson('/api/payments/'.$id)['id']);

        $this->getJson('/api/payments/01a1d464-0000-7000-8000-000000000000');
        self::assertResponseStatusCodeSame(404);
    }
}
