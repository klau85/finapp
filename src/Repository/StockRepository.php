<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Stock;
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
    public function findOwnedOrWatchedStocks(): array
    {
        $ownedRows = $this->getEntityManager()->createQuery(
            'SELECT DISTINCT stock.id AS id FROM App\Entity\Transaction transaction JOIN transaction.stock stock'
        )->getArrayResult();
        $watchedRows = $this->getEntityManager()->createQuery(
            'SELECT DISTINCT stock.id AS id FROM App\Entity\WatchlistItem item JOIN item.stock stock'
        )->getArrayResult();

        $ids = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['id'],
            array_merge($ownedRows, $watchedRows),
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
}
