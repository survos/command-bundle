<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\CommandBundle\Entity\CommandProcess;
use Survos\CommandBundle\Enum\RunMode;
use Survos\CommandBundle\Enum\RunStatus;

/**
 * Owns the lifecycle of {@see CommandProcess} rows. {@see \Survos\CommandBundle\EventListener\BackgroundRunListener}
 * drives start/finish/fail (and feeds append() with the worker's captured output) for bg:run jobs;
 * {@see \Survos\CommandBundle\Monolog\CommandSlotLogHandler} feeds setSlot() from `tui.slot` logs.
 *
 * Keeps a stack so nested command invocations (a command that runs another) each get their
 * own record and output goes to the innermost one.
 */
final class CommandProcessRecorder
{
    /** @var list<CommandProcess> */
    private array $stack = [];

    /** Mode hint set by a caller (e.g. the web executor) before the command runs. */
    private ?RunMode $nextMode = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** The web/async caller declares how the next started process was launched. */
    public function expectMode(RunMode $mode): void
    {
        $this->nextMode = $mode;
    }

    public function current(): ?CommandProcess
    {
        return $this->stack[array_key_last($this->stack)] ?? null;
    }

    public function start(string $command, ?string $cli = null, ?RunMode $mode = null): CommandProcess
    {
        $process = new CommandProcess(
            command: $command,
            cli: $cli,
            mode: $mode ?? $this->nextMode ?? RunMode::Cli,
            host: gethostname() ?: null,
            pid: getmypid() ?: null,
        );
        $this->nextMode = null;

        $process->status    = RunStatus::Running;
        $process->startedAt = new \DateTimeImmutable();

        $this->em->persist($process);
        $this->em->flush();

        $this->stack[] = $process;

        return $process;
    }

    /** Append a chunk of console output to the innermost running process. */
    public function append(string $chunk): void
    {
        $process = $this->current();
        if ($process === null) {
            return;
        }
        $process->output = ($process->output ?? '') . $chunk;
    }

    /**
     * Set a named status slot on the innermost running process and persist it immediately, so a
     * live monitor sees the current step (e.g. ['tui.slot' => 'header'] → slots['header']). Flushed
     * per call by design — slot updates are rare and need to be visible mid-run.
     */
    public function setSlot(string $name, string $value): void
    {
        $process = $this->current();
        if ($process === null) {
            return;
        }

        $slots = $process->slots ?? [];
        $slots[$name] = $value;
        $process->slots = $slots;

        $this->em->flush();
    }

    public function finish(int $exitCode): void
    {
        $process = array_pop($this->stack);
        if ($process === null) {
            return;
        }

        $process->finishedAt  = new \DateTimeImmutable();
        $process->exitCode    = $exitCode;
        $process->status      = 0 === $exitCode ? RunStatus::Succeeded : RunStatus::Failed;
        $process->memoryBytes = memory_get_peak_usage(true);
        $process->output      = self::collapseCarriageReturns($process->output);

        $this->em->flush();
    }

    public function fail(\Throwable $error): void
    {
        $process = $this->current();
        if ($process === null) {
            return;
        }

        $process->status         = RunStatus::Failed;
        $process->failureMessage = $error::class . ': ' . $error->getMessage();

        $this->em->flush();
    }

    /**
     * Collapse carriage-return overwrites (progress bars rewrite the current line with `\r`):
     * drop everything from a line start up to the last `\r` on that line, keeping the final state.
     */
    private static function collapseCarriageReturns(?string $output): ?string
    {
        if ($output === null || !str_contains($output, "\r")) {
            return $output;
        }

        return preg_replace('/[^\n]*\r/', '', $output);
    }
}
