<?php

namespace Survos\CommandBundle;

use Survos\CommandBundle\Controller\CommandController;
use Survos\CommandBundle\EventListener\BackgroundRunListener;
use Survos\CommandBundle\Menu\CommandBundleMenuSubscriber;
use Survos\CommandBundle\Monolog\CommandSlotLogHandler;
use Survos\CommandBundle\Repository\CommandProcessRepository;
use Survos\CommandBundle\Service\CommandProcessRecorder;
use Survos\CommandBundle\Service\ConsoleCommandExecutor;
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\Traits\HasConfigurableRoutes;
use Survos\Kit\Traits\HasDoctrineEntities;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;

// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
class SurvosCommandBundle extends AbstractSurvosBundle
{
    use HasConfigurableRoutes;
    use HasDoctrineEntities;

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $this->addRouteLoaderCompilerPass($container);
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);
        $this->captureRouteConfig($config);
        $builder->setParameter('survos_command.namespaces', $config['namespaces']);

        $builder->autowire(ConsoleCommandExecutor::class)
            ->setPublic(false);

        // Process registry: the recorder owns CommandProcess rows; the subscriber drives their
        // lifecycle off the console events fired by every run (web, worker, CLI). Tracking is
        // gated by `track` + the `namespaces` allow-list so housekeeping commands don't flood it.
        $builder->autowire(CommandProcessRecorder::class)
            ->setPublic(false);

        $builder->autowire(CommandProcessRepository::class)
            ->setPublic(false)
            ->addTag('doctrine.repository_service');

        // Record ONLY bg:run-launched commands: a RunCommandMessage executed by a Messenger worker
        // (lifecycle via worker events, output from the handler result). Plain CLI/web runs are not
        // recorded for now. The Monolog handler turns `['tui.slot' => 'header']` context into a slot
        // on whatever process is currently being recorded.
        if ($config['track']) {
            $builder->autowire(BackgroundRunListener::class)
                ->setAutoconfigured(true)
                ->setPublic(false);

            $builder->autowire(CommandSlotLogHandler::class)
                ->setPublic(false);
        }

        $builder->autowire(CommandController::class)
            ->setPublic(true)
            ->setArgument('$bus', new Reference('messenger.default_bus', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$namespaces', $config['namespaces'])
            ->setArgument('$config', $config)
            ->addTag('container.service_subscriber')
            ->addTag('controller.service_arguments');

        // Menu subscriber (only works when tabler-bundle is installed)
        $builder->autowire(CommandBundleMenuSubscriber::class)
            ->setAutoconfigured(true)
            ->setPublic(false);

        $this->registerRouteLoader($builder);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::prependExtension($container, $builder);

        // Add the slot handler to the app's Monolog stack so plain `$logger->info($x, ['tui.slot' => …])`
        // calls land on the current process. Gated by `track` and only when monolog is installed.
        if ($this->isTrackEnabled($builder) && $builder->hasExtension('monolog')) {
            $builder->prependExtensionConfig('monolog', [
                'handlers' => [
                    'survos_command_slots' => [
                        'type' => 'service',
                        'id' => CommandSlotLogHandler::class,
                    ],
                ],
            ]);
        }
    }

    private function isTrackEnabled(ContainerBuilder $builder): bool
    {
        $enabled = true; // matches the `track` node default
        foreach ($builder->getExtensionConfig('survos_command') as $cfg) {
            if (\is_array($cfg) && \array_key_exists('track', $cfg)) {
                $enabled = (bool) $cfg['track'];
            }
        }

        return $enabled;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $children = $definition->rootNode()->children();
        // Routes are OFF by default: this bundle exposes routes that RUN console commands, so an
        // app must opt in explicitly (survos_command.routes_enabled: true) and keep them behind a
        // secured prefix — otherwise something like doctrine:database:drop is a click away. Foot-canon.
        $this->addRouteOptions($children, '/admin/commands', defaultEnabled: false);

        $children
            ->scalarNode('base_layout')->defaultNull()->end()
            ->scalarNode('subdomain_variable')->defaultValue('subdomain')->end()
            ->booleanNode('track')->defaultTrue()
                ->info('Record each (namespaced) command run as a CommandProcess row for monitoring.')
            ->end()
            ->arrayNode('namespaces')
                ->scalarPrototype()->end()
            ->end()
        ->end();
    }
}
