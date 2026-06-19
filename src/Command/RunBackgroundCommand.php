<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatch a console command to run on the always-on `command` Messenger worker.
 *
 * This is the robust way to start a long job (e.g. provider:nara:raw, ~hours) that must outlive the
 * terminal: nohup/setsid/screen all die when a dokku one-off `run` container is torn down on exit,
 * because the whole container/cgroup goes with it. A Messenger worker is a separate, always-on
 * process, so the work continues regardless of the dispatching session.
 *
 * One message per command — dispatch several (e.g. one per unit, `--only=<unit>`) to fan out.
 *
 * Pairs with this bundle's process registry: {@see \Survos\CommandBundle\EventListener\BackgroundRunListener}
 * records each dispatched run, and `bg:monitor` / the web monitor watch it live.
 *
 * The app must route RunCommandMessage to an async transport and run a worker, e.g.:
 *   # config/packages/messenger.yaml
 *   framework.messenger.transports.command: 'doctrine://default?queue_name=command'
 *   framework.messenger.routing.'Symfony\Component\Console\Messenger\RunCommandMessage': command
 */
#[AsCommand(
    name: 'bg:run',
    description: 'Run a console command on the always-on worker (survives logout)',
    help: <<<'HELP'
        Dispatch a console command to the <info>command</info> Messenger worker so it runs
        independently of this terminal — the robust way to start a long job (e.g.
        <info>provider:nara:raw</info>, which can run for hours).

        Why not nohup / setsid / screen? On a dokku one-off <comment>run</comment> container the whole
        container is destroyed when you disconnect, taking any in-process job with it. A Messenger
        worker is a separate, always-on process, so the work continues.

        Examples:
          <info>%command.full_name% "provider:nara:raw"</info>
          <info>%command.full_name% "provider:smith:raw --only=nmaahc"</info>    one message per unit (fan out)

        Prod: enable the worker once —  <comment>dokku ps:scale <app> command=1</comment>
              logs:  <comment>dokku logs <app> -p command -t</comment>
        Local worker:  <comment>php bin/console messenger:consume command -vv</comment>
        HELP
)]
final class RunBackgroundCommand
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The console command line to run on the worker, e.g. "provider:nara:raw" (quote it)')]
        string $commandLine,
    ): int {
        // throwOnFailure:false → a non-zero command is handled once and logged, not retried (a
        // multi-hour job shouldn't auto-replay). catchExceptions:true → the command's own errors
        // become an exit code instead of crashing the worker.
        $this->bus->dispatch(new RunCommandMessage($commandLine, throwOnFailure: false, catchExceptions: true));

        $io->success(sprintf('Queued on the `command` worker: %s', $commandLine));
        $io->note([
            'Runs independently of this terminal — safe to log out / close the dokku run session.',
            'Prod worker:  dokku ps:scale <app> command=1     logs: dokku logs <app> -p command -t',
            'Local worker: php bin/console messenger:consume command -vv',
        ]);

        return Command::SUCCESS;
    }
}
