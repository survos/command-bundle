<?php

namespace Survos\CommandBundle;

use Survos\CommandBundle\Command\DumpTranslationsCommand;
use Survos\CommandBundle\Controller\CommandController;
use Survos\CommandBundle\Service\ConsoleCommandExecutor;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SurvosCommandBundle extends AbstractBundle
{
    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->autowire(ConsoleCommandExecutor::class)
            ->setPublic(false);

        $builder->autowire(CommandController::class)
            ->setPublic(true)
            ->setArgument('$bus', new Reference('messenger.default_bus', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$namespaces', $config['namespaces'])
            ->setArgument('$config', $config)
            ->addTag('container.service_subscriber')
            ->addTag('controller.service_arguments');

        // Keep for now
        $builder->autowire(DumpTranslationsCommand::class)
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->addTag('console.command')
            ->setArgument('$namespaces', $config['namespaces'])
            ->setArgument('$kernel', new Reference('kernel'));
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('base_layout')->defaultNull()->end()
                ->scalarNode('subdomain_variable')->defaultValue('subdomain')->end()
                ->arrayNode('namespaces')
                    ->scalarPrototype()->end()
                ->end()
            ->end()
        ;
    }
}
