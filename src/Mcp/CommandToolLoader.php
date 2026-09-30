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
use Survos\CommandBundle\Service\ConsoleCommandExecutor;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Completion\Suggestion;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * Registers each opted-in console command as an MCP tool (symfony/mcp-bundle's `mcp.loader`).
 * The input schema is derived from the command's native InputDefinition, so the console
 * attributes are the single declaration; a call runs the command in-process with
 * --format=json and returns the decoded JSON (or {output: "…"} when it isn't JSON).
 */
final class CommandToolLoader implements LoaderInterface
{
    /** Options the bridge owns; never part of a tool's schema. */
    private const array RESERVED = ['format'];

    private const string DEFAULT_ROLE = 'ROLE_ADMIN';

    /** @param list<array{command: string, name: string, description: ?string, readOnly: bool, destructive: bool, idempotent: bool, public: bool, role: ?string}> $tools */
    public function __construct(
        private readonly ConsoleCommandExecutor $executor,
        private readonly ?Security $security = null,
        private readonly array $tools = [],
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
                    description: $tool['description'] ?? trim($command->getDescription()."\n\n".$command->getHelp()),
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
            throw new ToolCallException(sprintf('Access denied: %s requires %s.', $tool['command'], $role));
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

        try {
            $result = $this->executor->runPayload($payload, rethrow: true);
        } catch (\Throwable $e) {
            throw new ToolCallException($e->getMessage(), previous: $e);
        }
        $text = trim($result['output']);
        if (0 !== $result['exitCode']) {
            throw new ToolCallException('' !== $text ? $text : sprintf('%s exited with code %d.', $tool['command'], $result['exitCode']));
        }

        $data = json_decode($text, true);

        return is_array($data) ? $data : ['output' => $text];
    }
}
