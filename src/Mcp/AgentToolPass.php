<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Mcp;

use Survos\CommandBundle\Attribute\AsAgentTool;
use Symfony\Component\Console\Attribute\AsCommand;
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
                $definition->addTag(self::TAG, ['command' => $command->name] + $tool->toArray());
            },
        );
        $container->addCompilerPass(new self());
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
        foreach ($container->getParameter('survos_command.agent_tools') as $tool) {
            $tools[$tool['command']] ??= $tool;  // an attribute on the command itself wins
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
