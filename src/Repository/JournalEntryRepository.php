<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\JournalEntry;
use App\Entity\Stock;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<JournalEntry> */
class JournalEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JournalEntry::class);
    }

    /**
     * @return list<JournalEntry>
     */
    public function findForUser(User $user, ?string $search = null, ?string $filter = null): array
    {
        $queryBuilder = $this->createUserQueryBuilder($user)
            ->orderBy('entry.entryDate', 'DESC')
            ->addOrderBy('entry.createdAt', 'DESC');

        if ($search !== null && $search !== '') {
            $queryBuilder
                ->andWhere($queryBuilder->expr()->orX(
                    'entry.title LIKE :search',
                    'entry.content LIKE :search',
                    'stock.symbol LIKE :search',
                    'transactionStock.symbol LIKE :search',
                ))
                ->setParameter('search', '%'.$search.'%');
        }

        if (in_array($filter, JournalEntry::TARGET_TYPES, true)) {
            $queryBuilder
                ->andWhere('entry.targetType = :targetType')
                ->setParameter('targetType', $filter);
        } elseif (in_array($filter, JournalEntry::ENTRY_TYPES, true)) {
            $queryBuilder
                ->andWhere('entry.entryType = :entryType')
                ->setParameter('entryType', $filter);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    public function findOneForUser(User $user, int $id): ?JournalEntry
    {
        return $this->createUserQueryBuilder($user)
            ->andWhere('entry.id = :id')
            ->setParameter('id', $id)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<JournalEntry>
     */
    public function findPortfolioEntriesForUser(User $user): array
    {
        return $this->createUserQueryBuilder($user)
            ->andWhere('entry.targetType = :targetType')
            ->setParameter('targetType', JournalEntry::TARGET_PORTFOLIO)
            ->orderBy('entry.entryDate', 'DESC')
            ->addOrderBy('entry.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<JournalEntry>
     */
    public function findForUserAndStock(User $user, Stock $stock): array
    {
        return $this->createUserQueryBuilder($user)
            ->andWhere('entry.stock = :stock')
            ->setParameter('stock', $stock)
            ->orderBy('entry.entryDate', 'DESC')
            ->addOrderBy('entry.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<JournalEntry>
     */
    public function findForStockJournal(User $user, Stock $stock): array
    {
        $queryBuilder = $this->createUserQueryBuilder($user);

        return $queryBuilder
            ->andWhere($queryBuilder->expr()->orX(
                'entry.stock = :stock',
                'transactionStock = :stock',
            ))
            ->setParameter('stock', $stock)
            ->orderBy('entry.entryDate', 'DESC')
            ->addOrderBy('entry.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<JournalEntry>
     */
    public function findRecentForUser(User $user, int $limit = 3): array
    {
        return $this->createUserQueryBuilder($user)
            ->orderBy('entry.entryDate', 'DESC')
            ->addOrderBy('entry.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<int> $transactionIds
     * @return array<int, list<JournalEntry>>
     */
    public function findByTransactionIdsForUser(User $user, array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $entries = $this->createUserQueryBuilder($user)
            ->andWhere('transaction.id IN (:transactionIds)')
            ->setParameter('transactionIds', array_values(array_unique($transactionIds)))
            ->orderBy('entry.entryDate', 'DESC')
            ->addOrderBy('entry.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $entriesByTransactionId = [];
        foreach ($entries as $entry) {
            $transactionId = $entry->getTransaction()?->getId();
            if ($transactionId !== null) {
                $entriesByTransactionId[$transactionId][] = $entry;
            }
        }

        return $entriesByTransactionId;
    }

    private function createUserQueryBuilder(User $user): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('entry')
            ->addSelect('stock', 'transaction', 'transactionStock', 'brokerAccount')
            ->leftJoin('entry.stock', 'stock')
            ->leftJoin('entry.transaction', 'transaction')
            ->leftJoin('transaction.stock', 'transactionStock')
            ->leftJoin('transaction.brokerAccount', 'brokerAccount')
            ->andWhere('entry.user = :user')
            ->setParameter('user', $user);
    }
}
