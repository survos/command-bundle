<?php

declare(strict_types=1);

namespace Survos\CommandBundle\EventListener;

use Survos\CommandBundle\Enum\RunMode;
use Survos\CommandBundle\Service\CommandProcessRecorder;
use Symfony\Component\Console\Messenger\RunCommandContext;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Records ONLY background command runs — those dispatched via `bg:run` as a RunCommandMessage and
 * executed by a Messenger worker.
 *
 * Lifecycle rides the worker events (received → handled/failed), so there's no per-command console
 * coupling and no nested-command ambiguity: one message = one CommandProcess. Output comes straight
 * from the handler's {@see RunCommandContext}. Slots still work because the process is "current"
 * between received and handled, so the Monolog slot handler writes to it.
 */
final class BackgroundRunListener
{
    public function __construct(
        private readonly CommandProcessRecorder $recorder,
    ) {
    }

    #[AsEventListener(event: WorkerMessageReceivedEvent::class)]
    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof RunCommandMessage) {
            return;
        }

        $cli  = trim($message->input);
        $name = $cli === '' ? '(unknown)' : explode(' ', $cli, 2)[0];

        $this->recorder->start($name, $cli, RunMode::Async);
    }

    #[AsEventListener(event: WorkerMessageHandledEvent::class)]
    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        if (!$event->getEnvelope()->getMessage() instanceof RunCommandMessage) {
            return;
        }

        $result = $event->getEnvelope()->last(HandledStamp::class)?->getResult();
        if ($result instanceof RunCommandContext) {
            $this->recorder->append($result->output);
            $this->recorder->finish($result->exitCode);

            return;
        }

        $this->recorder->finish(0);
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        if (!$event->getEnvelope()->getMessage() instanceof RunCommandMessage) {
            return;
        }

        $this->recorder->fail($event->getThrowable());
        $this->recorder->finish(1);
    }
}
