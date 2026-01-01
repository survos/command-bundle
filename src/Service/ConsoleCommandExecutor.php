<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Service;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;

final class ConsoleCommandExecutor
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $options
     * @return array{exitCode:int, durationMs:int, output:string}
     */
    public function run(string $commandName, array $arguments = [], array $options = []): array
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $command = $application->find($commandName);

        $input = new ArrayInput(
            $this->buildPayload($command, $arguments, $options)
        );
        $input->setInteractive(false);

        $output = new BufferedOutput();

        $start = microtime(true);
        $exitCode = $application->run($input, $output);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        return [
            'exitCode' => (int) $exitCode,
            'durationMs' => $durationMs,
            'output' => $output->fetch(),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildPayload(Command $command, array $arguments, array $options): array
    {
        $payload = ['command' => $command->getName()];

        foreach ($arguments as $name => $value) {
            if ($value !== null) {
                $payload[$name] = $value;
            }
        }

        foreach ($options as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            // flags
            if ($value === true) {
                $payload['--' . $name] = true;
                continue;
            }

            // array options
            if (is_array($value)) {
                foreach ($value as $v) {
                    $payload['--' . $name][] = $v;
                }
                continue;
            }

            $payload['--' . $name] = $value;
        }

        return $payload;
    }
}
