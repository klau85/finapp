<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Stock;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Stock> */
class StockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Stock::class);
    }

    /**
     * @return list<Stock>
     */
    public function findOwnedStocks(): array
    {
        $rows = $this->getEntityManager()->createQuery(
            'SELECT DISTINCT stock.id AS id FROM App\Entity\Transaction transaction JOIN transaction.stock stock'
        )->getArrayResult();

        $ids = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['id'],
            $rows,
        )));

        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('stock')
            ->andWhere('stock.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('stock.symbol', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneBySymbol(string $symbol): ?Stock
    {
        return $this->createQueryBuilder('stock')
            ->andWhere('stock.symbol = :symbol')
            ->setParameter('symbol', strtoupper($symbol))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<Stock>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('stock')
            ->join('App\Entity\Transaction', 'transaction', 'WITH', 'transaction.stock = stock')
            ->andWhere('transaction.user = :user')
            ->setParameter('user', $user)
            ->groupBy('stock.id')
            ->orderBy('stock.symbol', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForUserBySymbol(User $user, string $symbol): ?Stock
    {
        return $this->createQueryBuilder('stock')
            ->join('App\Entity\Transaction', 'transaction', 'WITH', 'transaction.stock = stock')
            ->andWhere('transaction.user = :user')
            ->andWhere('stock.symbol = :symbol')
            ->setParameter('user', $user)
            ->setParameter('symbol', strtoupper($symbol))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
