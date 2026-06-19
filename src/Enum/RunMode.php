<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Enum;

/**
 * How a {@see \Survos\CommandBundle\Entity\CommandProcess} was launched.
 */
enum RunMode: string
{
    case Sync  = 'sync';  // ran in-process (web request / direct method call)
    case Async = 'async'; // dispatched to a Messenger worker (survives container teardown)
    case Cli   = 'cli';   // launched directly from the console
}
