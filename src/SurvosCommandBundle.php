<?php

namespace Survos\CommandBundle;

use Survos\CommandBundle\Command\DumpTranslationsCommand;
use Survos\CommandBundle\Controller\CommandController;
use Survos\CommandBundle\Twig\TwigExtension;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SurvosCommandBundle extends AbstractBundle
{
//    protected string $extensionAlias = 'survos_command';

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder
            ->autowire('survos_command.twig', TwigExtension::class)
            ->addTag('twig.extension')
        ;
        $builder->autowire(CommandController::class)
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->setArgument('$bus', new Reference('messenger.default_bus', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$namespaces', $config['namespaces'])
            ->setArgument('$config', $config)
        ;

//        $builder->autowire(CommandRunner::class)
//            ->setPublic(true)
//            ->setAutoconfigured(true)
//            ->setAutowired(true);

        $builder->autowire(DumpTranslationsCommand::class)
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->addTag('console.command')
            ->setArgument('$namespaces', $config['namespaces'])
            ->setArgument('$kernel', new Reference('kernel'))
        ;



//        $serivceId = 'survos_command.command_controller';
//        $container->services()->alias(CommandController::class, $serivceId);
//        $builder->autowire(CommandController::class)
//            ->setArgument('$kernel', new Reference('kernel'))
//            ->addTag('container.service_subscriber')
//            ->addTag('controller.service_arguments')
//            ->setPublic(true)
//            ->setAutoconfigured(true)
//        ;

    }

    public function configure(DefinitionConfigurator $definition): void
    {
        // since the configuration is short, we can add it here
        $definition->rootNode()
            ->children()
            ->scalarNode('base_layout')->defaultValue('base.html.twig')->end()
            ->scalarNode('subdomain_variable')->defaultValue('subdomain')->end()
            ->arrayNode('namespaces')
            ->scalarPrototype()->end()
            ->end()
            ->end()
//            ->arrayNode('namespaces')
//                // ->addDefaultsIfNotSet()
//                ->arrayPrototype()
////                    ->addDefaultsIfNotSet()
////                    ->children()
////                        ->scalarNode('app')->end()
//                ->end()
//            ->end()
        ;
    }
}
