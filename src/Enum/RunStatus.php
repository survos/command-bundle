<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Enum;

/**
 * Lifecycle state of a {@see \Survos\CommandBundle\Entity\CommandProcess}.
 */
enum RunStatus: string
{
    case Pending   = 'pending';   // recorded, not yet started (e.g. queued to a worker)
    case Running   = 'running';   // currently executing
    case Succeeded = 'succeeded'; // finished with exit code 0
    case Failed    = 'failed';    // finished with a non-zero exit code or threw
    case Canceled  = 'canceled';  // stopped before completion

    public function isFinished(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Canceled => true,
            self::Pending, self::Running                  => false,
        };
    }
}
