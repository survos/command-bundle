<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Controller;

use Survos\CommandBundle\Service\ConsoleCommandExecutor;
use Symfony\Bundle\FrameworkBundle\Console\Application as FrameworkConsoleApplication;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class CommandController extends AbstractController
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ConsoleCommandExecutor $executor,
        private readonly ?MessageBusInterface $bus,
        private readonly array $namespaces,
        private readonly array $config,
    ) {
    }

    #[Route('/_command', name: 'survos_commands')]
    public function index(): Response
    {
        $application = $this->createConsoleApplication();

        /** @var array<string, Command[]> $groups */
        $groups = [];

        foreach ($application->all() as $command) {
            if (!$this->isAllowed($command)) {
                continue;
            }

            $name = (string) $command->getName();
            $prefix = $this->commandPrefix($name);

            $groups[$prefix] ??= [];
            $groups[$prefix][] = $command;
        }

        // sort commands within each group
        foreach ($groups as $prefix => $cmds) {
            usort(
                $cmds,
                fn (Command $a, Command $b) => strcmp((string) $a->getName(), (string) $b->getName())
            );
            $groups[$prefix] = $cmds;
        }

        // sort groups by name, but keep "app" first if present
        $groupNames = array_keys($groups);
        usort($groupNames, static function (string $a, string $b): int {
            if ($a === 'app' && $b !== 'app') {
                return -1;
            }
            if ($b === 'app' && $a !== 'app') {
                return 1;
            }
            return strcmp($a, $b);
        });

        $sortedGroups = [];
        foreach ($groupNames as $g) {
            $sortedGroups[$g] = $groups[$g];
        }

        return $this->render('@SurvosCommand/command/index.html.twig', [
            'groups' => $sortedGroups,
            'base_layout' => $this->config['base_layout'] ?? '@SurvosCommand/layout/tabler.html.twig',
        ]);
    }

    #[Route('/_command/run/{name}', name: 'survos_command_run', requirements: ['name' => '.+'])]
    public function run(Request $request, string $name): Response
    {
        $application = $this->createConsoleApplication();
        $command = $application->find($name);

        if (!$this->isAllowed($command)) {
            throw $this->createNotFoundException();
        }

        $definition = $command->getDefinition();

        $result = null;
        if ($request->isMethod('POST')) {
            $args = $request->request->all('args');
            $opts = $this->normalizePostedOptions($request->request->all('opts'), $definition->getOptions());

            $dispatch = $request->request->get('dispatch') === '1';

            if ($dispatch && $this->bus) {
                $cli = $this->buildCli($name, $args, $opts);
                $this->bus->dispatch(new RunCommandMessage($cli));

                $result = [
                    'mode' => 'async',
                    'cli' => $cli,
                    'message' => 'Dispatched to Messenger. Check your logs for output (commands should log via LoggerInterface).',
                ];
            } else {
                $exec = $this->executor->run($name, $args, $opts);

                $result = [
                    'mode' => 'sync',
                    'cli' => $this->buildCli($name, $args, $opts),
                    ...$exec,
                ];
            }
        }

        return $this->render('@SurvosCommand/command/run.html.twig', [
            'command' => $command,
            'definition' => $definition,
            'result' => $result,
            'has_bus' => (bool) $this->bus,
            'base_layout' => $this->config['base_layout'] ?? '@SurvosCommand/layout/tabler.html.twig',
        ]);
    }

    private function createConsoleApplication(): FrameworkConsoleApplication
    {
        $app = new FrameworkConsoleApplication($this->kernel);
        $app->setAutoExit(false);

        return $app;
    }

    private function isAllowed(Command $command): bool
    {
        if ($command->isHidden()) {
            return false;
        }

        if (empty($this->namespaces)) {
            return true;
        }

        $name = (string) $command->getName();
        foreach ($this->namespaces as $ns) {
            $ns = (string) $ns;
            if ($ns !== '' && str_starts_with($name, $ns . ':')) {
                return true;
            }
        }

        return false;
    }

    private function commandPrefix(string $commandName): string
    {
        $pos = strpos($commandName, ':');
        if ($pos === false) {
            return 'other';
        }

        $prefix = substr($commandName, 0, $pos);
        return $prefix !== '' ? $prefix : 'other';
    }

    /**
     * @param array<string, mixed> $postedOpts
     * @param array<string, \Symfony\Component\Console\Input\InputOption> $definedOptions
     * @return array<string, mixed>
     */
    private function normalizePostedOptions(array $postedOpts, array $definedOptions): array
    {
        $normalized = [];

        foreach ($definedOptions as $name => $opt) {
            if (!array_key_exists($name, $postedOpts)) {
                continue;
            }

            $value = $postedOpts[$name];

            if (!$opt->acceptValue()) {
                $normalized[$name] = true;
                continue;
            }

            if ($value === '' || $value === null) {
                continue;
            }

            $normalized[$name] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $options
     */
    private function buildCli(string $name, array $arguments, array $options): string
    {
        $parts = [$name];

        foreach ($arguments as $argName => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = $this->escapeToken((string) $value);
        }

        foreach ($options as $optName => $value) {
            $flag = '--' . $optName;

            if ($value === true) {
                $parts[] = $flag;
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $parts[] = $flag . '=' . $this->escapeToken((string) $value);
        }

        return implode(' ', $parts);
    }

    private function escapeToken(string $value): string
    {
        if ($value === '' || preg_match('/\s|["\'\\\\]/', $value)) {
            $value = str_replace(['\\', '"'], ['\\\\', '\"'], $value);
            return '"' . $value . '"';
        }

        return $value;
    }
}
