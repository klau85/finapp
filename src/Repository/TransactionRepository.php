<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Transaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Transaction> */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /**
     * @return list<Transaction>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('transaction')
            ->andWhere('transaction.user = :user')
            ->setParameter('user', $user)
            ->orderBy('transaction.transactionDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForUser(User $user, int $id): ?Transaction
    {
        return $this->createQueryBuilder('transaction')
            ->addSelect('brokerAccount', 'stock')
            ->join('transaction.brokerAccount', 'brokerAccount')
            ->join('transaction.stock', 'stock')
            ->andWhere('transaction.user = :user')
            ->andWhere('transaction.id = :id')
            ->setParameter('user', $user)
            ->setParameter('id', $id)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param array{
     *     symbol?: string|null,
     *     brokerAccountId?: int|null,
     *     type?: string|null,
     *     dateFrom?: \DateTimeImmutable|null,
     *     dateTo?: \DateTimeImmutable|null
     * } $filters
     * @return list<Transaction>
     */
    public function findFilteredForUser(User $user, array $filters): array
    {
        $queryBuilder = $this->createQueryBuilder('transaction')
            ->addSelect('brokerAccount', 'stock')
            ->join('transaction.brokerAccount', 'brokerAccount')
            ->join('transaction.stock', 'stock')
            ->andWhere('transaction.user = :user')
            ->setParameter('user', $user)
            ->orderBy('transaction.transactionDate', 'DESC')
            ->addOrderBy('transaction.id', 'DESC');

        if (($filters['symbol'] ?? null) !== null && $filters['symbol'] !== '') {
            $queryBuilder
                ->andWhere('stock.symbol LIKE :symbol')
                ->setParameter('symbol', strtoupper($filters['symbol']).'%');
        }

        if (($filters['brokerAccountId'] ?? null) !== null) {
            $queryBuilder
                ->andWhere('brokerAccount.id = :brokerAccountId')
                ->setParameter('brokerAccountId', $filters['brokerAccountId']);
        }

        if (($filters['type'] ?? null) !== null && $filters['type'] !== '') {
            $queryBuilder
                ->andWhere('transaction.type = :type')
                ->setParameter('type', strtoupper($filters['type']));
        }

        if (($filters['dateFrom'] ?? null) instanceof \DateTimeImmutable) {
            $queryBuilder
                ->andWhere('transaction.transactionDate >= :dateFrom')
                ->setParameter('dateFrom', $filters['dateFrom']);
        }

        if (($filters['dateTo'] ?? null) instanceof \DateTimeImmutable) {
            $queryBuilder
                ->andWhere('transaction.transactionDate <= :dateTo')
                ->setParameter('dateTo', $filters['dateTo']);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * @return list<Transaction>
     */
    public function findForFifoRecalculation(User $user): array
    {
        return $this->createQueryBuilder('transaction')
            ->addSelect('brokerAccount', 'stock')
            ->join('transaction.brokerAccount', 'brokerAccount')
            ->join('transaction.stock', 'stock')
            ->andWhere('transaction.user = :user')
            ->setParameter('user', $user)
            ->orderBy('transaction.transactionDate', 'ASC')
            ->addOrderBy('transaction.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Transaction>
     */
    public function findForUserAndSymbol(User $user, string $symbol): array
    {
        return $this->createQueryBuilder('transaction')
            ->addSelect('brokerAccount', 'stock')
            ->join('transaction.brokerAccount', 'brokerAccount')
            ->join('transaction.stock', 'stock')
            ->andWhere('transaction.user = :user')
            ->andWhere('stock.symbol = :symbol')
            ->setParameter('user', $user)
            ->setParameter('symbol', strtoupper($symbol))
            ->orderBy('transaction.transactionDate', 'DESC')
            ->addOrderBy('transaction.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<string>
     */
    public function findSymbolsForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('transaction')
            ->select('DISTINCT stock.symbol AS symbol')
            ->join('transaction.stock', 'stock')
            ->andWhere('transaction.user = :user')
            ->setParameter('user', $user)
            ->orderBy('stock.symbol', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => (string) $row['symbol'], $rows);
    }

    /**
     * @return list<Transaction>
     */
    public function findRecentForUser(User $user, int $limit = 8): array
    {
        return $this->createQueryBuilder('transaction')
            ->addSelect('brokerAccount', 'stock')
            ->join('transaction.brokerAccount', 'brokerAccount')
            ->join('transaction.stock', 'stock')
            ->andWhere('transaction.user = :user')
            ->setParameter('user', $user)
            ->orderBy('transaction.transactionDate', 'DESC')
            ->addOrderBy('transaction.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('transaction')
            ->select('COUNT(transaction.id)')
            ->andWhere('transaction.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<string, string>
     */
    public function getCashInvestedByCurrencyForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('transaction')
            ->select('transaction.currency AS currency')
            ->addSelect("SUM(CASE WHEN transaction.type = 'BUY' THEN (transaction.quantity * transaction.price + transaction.fees) ELSE 0 END) AS invested")
            ->andWhere('transaction.user = :user')
            ->setParameter('user', $user)
            ->groupBy('transaction.currency')
            ->getQuery()
            ->getArrayResult();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['currency']] = (string) $row['invested'];
        }

        return $indexed;
    }
}
