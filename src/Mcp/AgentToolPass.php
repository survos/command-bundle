<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Mcp;

use Survos\CommandBundle\Attribute\AsAgentTool;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Collects the opted-in commands — #[AsAgentTool] methods plus survos_command.agent_tools —
 * into CommandToolLoader's $tools argument.
 */
final class AgentToolPass implements CompilerPassInterface
{
    private const string TAG = 'survos_command.agent_tool';

    public static function register(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(
            AsAgentTool::class,
            static function (ChildDefinition $definition, AsAgentTool $tool, \ReflectionMethod $method): void {
                $command = ($method->getAttributes(AsCommand::class)[0] ?? null)?->newInstance()
                    ?? throw new \LogicException(sprintf('#[AsAgentTool] on %s::%s() needs #[AsCommand] on the same method.', $method->class, $method->name));
                // Unset options stay out of the tag: a null attribute cannot be dumped to XML, which breaks the debug container.
                $definition->addTag(self::TAG, ['command' => $command->name, 'types' => json_encode(self::types($method))] + array_filter($tool->toArray(), static fn (mixed $v): bool => null !== $v));
            },
        );
        $container->addCompilerPass(new self());
    }

    /**
     * JSON-schema types for the command's inputs, from the PHP signature: the console definition
     * only knows strings, but `int $limit` is an integer to an agent. Looks through #[MapInput] DTOs.
     *
     * @return array<string, string> input name (as the console spells it) => "integer" | "number"
     */
    private static function types(\ReflectionMethod $method): array
    {
        $types = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($parameter->getAttributes(MapInput::class) && $type instanceof \ReflectionNamedType && class_exists($type->getName())) {
                foreach ((new \ReflectionClass($type->getName()))->getProperties() as $property) {
                    self::collectType($property, $types);
                }
            } else {
                self::collectType($parameter, $types);
            }
        }

        return $types;
    }

    /** @param array<string, string> $types */
    private static function collectType(\ReflectionParameter|\ReflectionProperty $input, array &$types): void
    {
        $attribute = ($input->getAttributes(Argument::class)[0] ?? $input->getAttributes(Option::class)[0] ?? null)?->newInstance();
        $type = $input->getType();
        if (null === $attribute || !$type instanceof \ReflectionNamedType) {
            return;
        }
        $jsonType = match ($type->getName()) {
            'int' => 'integer',
            'float' => 'number',
            default => null,  // strings, enums (already an enum of strings), bools (flags) and arrays need no override
        };
        if (null !== $jsonType) {
            // Same spelling the console derives: camelCase → kebab-case unless the attribute names it.
            $name = $attribute->name ?: strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $input->getName()));
            $types[$name] = $jsonType;
        }
    }

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(CommandToolLoader::class)) {
            return;
        }
        $tools = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $tags) {
            foreach ($tags as $tag) {
                $tools[$tag['command']] = $tag;
            }
        }
        foreach ($tools as $command => $tool) {
            $tools[$command]['types'] = json_decode((string) ($tool['types'] ?? '{}'), true) ?: [];
        }
        foreach ($container->getParameter('survos_command.agent_tools') as $tool) {
            $tools[$tool['command']] ??= $tool + ['types' => []];  // an attribute on the command itself wins
        }
        foreach ($tools as $command => $tool) {
            if ($tool['public'] && !$tool['readOnly']) {
                throw new \LogicException(sprintf('Agent tool "%s" is public but not readOnly: only read-only tools can skip sign-in.', $command));
            }
            $tools[$command]['name'] ??= str_replace([':', '-'], '_', $command);
        }

        $container->getDefinition(CommandToolLoader::class)->setArgument('$tools', array_values($tools));
    }
}
