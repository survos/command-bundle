<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Survos\CommandBundle\Service\CommandProcessRecorder;

/**
 * Routes log records carrying a `tui.slot` context key into the current CommandProcess's slots,
 * so a command can push a styled status fragment with plain PSR-3:
 *
 *     $logger->info($institution, ['tui.slot' => 'header']);
 *
 * The record's message becomes the slot value. Bubbles (never swallows records) and no-ops unless
 * a process is currently being recorded — so it's harmless on every other log line in the app.
 */
final class CommandSlotLogHandler extends AbstractProcessingHandler
{
    public function __construct(private readonly CommandProcessRecorder $recorder)
    {
        parent::__construct(Level::Debug, bubble: true);
    }

    protected function write(LogRecord $record): void
    {
        $slot = $record->context['tui.slot'] ?? null;
        if (\is_string($slot) && $slot !== '') {
            $this->recorder->setSlot($slot, $record->message);
        }
    }
}
