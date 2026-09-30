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

    /**
     * Agent (MCP) calls, newest first.
     *
     * @return list<CommandProcess>
     */
    public function findAgentCalls(?string $command = null, ?string $caller = null, bool $failedOnly = false, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.mode = :mode')->setParameter('mode', \Survos\CommandBundle\Enum\RunMode::Agent)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit);
        if (null !== $command) {
            $qb->andWhere('p.command = :command')->setParameter('command', $command);
        }
        if (null !== $caller) {
            $qb->andWhere('p.caller = :caller')->setParameter('caller', $caller);
        }
        if ($failedOnly) {
            $qb->andWhere('p.status = :failed')->setParameter('failed', RunStatus::Failed);
        }

        return $qb->getQuery()->getResult();
    }
}
