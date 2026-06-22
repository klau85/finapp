<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BrokerAccount;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrokerAccount>
 */
class BrokerAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrokerAccount::class);
    }

    /**
     * @return list<BrokerAccount>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('brokerAccount')
            ->andWhere('brokerAccount.user = :user')
            ->setParameter('user', $user)
            ->orderBy('brokerAccount.displayName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForUser(User $user, int $id): ?BrokerAccount
    {
        return $this->createQueryBuilder('brokerAccount')
            ->andWhere('brokerAccount.user = :user')
            ->andWhere('brokerAccount.id = :id')
            ->setParameter('user', $user)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
