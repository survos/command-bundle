<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Mcp;

use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use Survos\CommandBundle\Entity\CommandProcess;
use Survos\CommandBundle\Enum\RunMode;
use Survos\CommandBundle\Service\CommandProcessRecorder;
use Survos\CommandBundle\Service\ConsoleCommandExecutor;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\Suggestion;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * Registers each opted-in console command as an MCP tool (symfony/mcp-bundle's `mcp.loader`).
 * The input schema is derived from the command's native InputDefinition, so the console
 * attributes are the single declaration; a call runs the command in-process with
 * --format=json and returns the decoded JSON (or {output: "…"} when it isn't JSON).
 *
 * With a recorder (survos_command.track), every call, refused ones included, is a CommandProcess
 * row in mode "agent": caller, the equivalent CLI line, exit code, timings, output. `agent:calls`
 * lists them.
 */
final class CommandToolLoader implements LoaderInterface
{
    /** Options the bridge owns; never part of a tool's schema. */
    private const array RESERVED = ['format'];

    private const string DEFAULT_ROLE = 'ROLE_ADMIN';

    /** Recorded output is capped; the agent still gets the whole result. */
    private const int MAX_RECORDED_OUTPUT = 65536;

    /** @param list<array{command: string, name: string, description: ?string, readOnly: bool, destructive: bool, idempotent: bool, public: bool, role: ?string}> $tools */
    public function __construct(
        private readonly ConsoleCommandExecutor $executor,
        private readonly ?Security $security = null,
        private readonly array $tools = [],
        private readonly ?CommandProcessRecorder $recorder = null,
    ) {}

    public function load(RegistryInterface $registry): void
    {
        if (!$this->tools) {
            return;
        }
        $application = $this->executor->application();
        foreach ($this->tools as $tool) {
            $command = $application->find($tool['command']);
            $registry->registerTool(
                new Tool(
                    name: $tool['name'],
                    title: null,
                    inputSchema: $this->schema($command->getNativeDefinition()),
                    description: $tool['description'] ?? $this->describe($command),
                    annotations: new ToolAnnotations(
                        readOnlyHint: $tool['readOnly'],
                        destructiveHint: $tool['readOnly'] ? null : $tool['destructive'],
                        idempotentHint: $tool['idempotent'],
                        openWorldHint: false,
                    ),
                ),
                fn (RequestContext $context): array => $this->call($tool, $context),
            );
        }
    }

    /** Description + processed help (%command.name% filled in), without console markup like <info>. */
    private function describe(Command $command): string
    {
        $help = $command->getHelp() ? $command->getProcessedHelp() : '';

        return trim(Helper::removeDecoration(new OutputFormatter(), trim($command->getDescription()."\n\n".$help)));
    }

    /** @return array<string, mixed> */
    private function schema(InputDefinition $definition): array
    {
        $properties = $required = [];
        foreach ($definition->getArguments() as $name => $argument) {
            $properties[$name] = $this->property($argument->isArray(), true, $argument->getDescription(), $argument->getDefault(), $this->suggestions($argument));
            if ($argument->isRequired()) {
                $required[] = $name;
            }
        }
        foreach ($definition->getOptions() as $name => $option) {
            if (!in_array($name, self::RESERVED, true)) {
                $properties[$name] = $this->property($option->isArray(), $option->acceptValue(), $option->getDescription(), $option->getDefault(), $this->suggestions($option));
            }
        }

        return array_filter([
            'type' => 'object',
            'properties' => $properties ?: new \stdClass(),
            'required' => $required,
        ]);
    }

    /**
     * @param list<string> $enum
     *
     * @return array<string, mixed>
     */
    private function property(bool $isArray, bool $acceptsValue, string $description, mixed $default, array $enum): array
    {
        $item = $acceptsValue ? ['type' => 'string'] + ($enum ? ['enum' => $enum] : []) : ['type' => 'boolean'];
        $property = $isArray ? ['type' => 'array', 'items' => $item] : $item;
        if ('' !== $description) {
            $property['description'] = $description;
        }
        if (null !== $default && [] !== $default && false !== $default) {
            $property['default'] = $default;
        }

        return $property;
    }

    /** @return list<string> completion values — for a BackedEnum argument/option, its cases */
    private function suggestions(InputArgument|InputOption $input): array
    {
        if (!$input->hasCompletion()) {
            return [];
        }
        $suggestions = new CompletionSuggestions();
        $input->complete(new CompletionInput(), $suggestions);

        return array_map(fn (Suggestion $s) => $s->getValue(), $suggestions->getValueSuggestions());
    }

    /**
     * @param array{command: string, public: bool, role: ?string} $tool
     *
     * @return array<string, mixed>
     */
    private function call(array $tool, RequestContext $context): array
    {
        // Closed by default: only tools explicitly marked public skip the role check. Fail closed:
        // a role that can't be checked (no security-bundle) is a denial, not a pass.
        $role = $tool['public'] ? null : ($tool['role'] ?? self::DEFAULT_ROLE);
        if (null !== $role && !$this->security?->isGranted($role)) {
            $denied = sprintf('Access denied: %s requires %s.', $tool['command'], $role);
            $this->record($tool['command'], $tool['command'], static fn (CommandProcess $p) => $p->failureMessage = $denied);
            throw new ToolCallException($denied);
        }

        $request = $context->getRequest();
        $arguments = $request instanceof CallToolRequest ? $request->arguments : [];
        $definition = $this->executor->application()->find($tool['command'])->getNativeDefinition();

        $payload = ['command' => $tool['command']];
        if ($definition->hasOption('format')) {
            $payload['--format'] = 'json';
        }
        foreach ($arguments as $name => $value) {
            if ($definition->hasArgument($name)) {
                $payload[$name] = $value;
            } elseif ($definition->hasOption($name) && !in_array($name, self::RESERVED, true)) {
                $option = $definition->getOption($name);
                if ($option->acceptValue()) {
                    $payload['--'.$name] = $value;
                } elseif ($value) {
                    $payload['--'.$name] = true;
                } elseif ($option->isNegatable()) {
                    $payload['--no-'.$name] = true;
                }
            } else {
                throw new ToolCallException(sprintf('Unknown parameter "%s".', $name));
            }
        }

        $process = $this->record($tool['command'], self::cli($payload));
        try {
            $result = $this->executor->runPayload($payload, rethrow: true);
        } catch (\Throwable $e) {
            $this->finish($process, 1, $e);
            throw new ToolCallException($e->getMessage(), previous: $e);
        }
        $this->finish($process, $result['exitCode'], null, $result['output']);
        $text = trim($result['output']);
        if (0 !== $result['exitCode']) {
            throw new ToolCallException('' !== $text ? $text : sprintf('%s exited with code %d.', $tool['command'], $result['exitCode']));
        }

        $data = json_decode($text, true);

        return is_array($data) ? $data : ['output' => $text];
    }

    /**
     * Opens the audit row for a call. Recording never breaks the call itself: if the entity
     * manager is unusable (closed by the command's own failure, no table yet), the call goes on.
     *
     * @param (callable(CommandProcess): void)|null $refused set for a refused call: the row is closed as failed at once
     */
    private function record(string $command, string $cli, ?callable $refused = null): ?CommandProcess
    {
        if (null === $this->recorder) {
            return null;
        }
        try {
            $process = $this->recorder->start($command, $cli, RunMode::Agent);
            $process->caller = $this->security?->getUser()?->getUserIdentifier() ?? 'anonymous';
            if (null !== $refused) {
                $refused($process);
                $this->recorder->finish(1);
            }

            return $process;
        } catch (\Throwable) {
            return null;
        }
    }

    private function finish(?CommandProcess $process, int $exitCode, ?\Throwable $error = null, ?string $output = null): void
    {
        if (null === $process) {
            return;
        }
        try {
            $process->output = null === $output ? null : mb_strcut($output, 0, self::MAX_RECORDED_OUTPUT);
            if (null !== $error) {
                $process->failureMessage = $error::class.': '.$error->getMessage();
            }
            $this->recorder->finish($exitCode);
        } catch (\Throwable) {
        }
    }

    /** The equivalent command line, so a recorded call can be re-run by hand. */
    private static function cli(array $payload): string
    {
        $parts = [];
        foreach ($payload as $key => $value) {
            if ('command' === $key) {
                $parts[] = $value;
            } elseif (!str_starts_with((string) $key, '--')) {
                foreach ((array) $value as $v) {
                    $parts[] = escapeshellarg((string) $v);
                }
            } elseif (true === $value) {
                $parts[] = $key;
            } else {
                foreach ((array) $value as $v) {
                    $parts[] = $key.'='.escapeshellarg((string) $v);
                }
            }
        }

        return implode(' ', $parts);
    }
}
