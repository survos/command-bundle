<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\CommandBundle\Entity\CommandProcess;
use Survos\CommandBundle\Enum\RunStatus;

/**
 * @extends ServiceEntityRepository<CommandProcess>
 */
final class CommandProcessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommandProcess::class);
    }

    /**
     * Most recent processes first, optionally filtered by status (e.g. the monitor's
     * "failed" view).
     *
     * @return list<CommandProcess>
     */
    public function findRecent(?RunStatus $status = null, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($status !== null) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }
}
