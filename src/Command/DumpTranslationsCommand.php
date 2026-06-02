<?php

namespace Survos\CommandBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Bundle\FrameworkBundle\Console\Application;

#[AsCommand('survos:command:dump-as-messages', 'Dump the command descriptions as a message for file translations')]
final class DumpTranslationsCommand
{
    private Application $application;

    public function __construct(
        private KernelInterface $kernel,
        private array $namespaces, // injected from the bundle config
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
        string|null $name = null,
    ) {
        $this->application = new Application($this->kernel);
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'The namespace(s) to dump (defaults to config value)')]
        string $namespace = 'app',
    ): int {

        $commands = [];
        $messages = [];
        $namespaces = $namespace ? [$namespace] : $this->namespaces;
        foreach ($namespaces as $namespace) {
            $commands[$namespace] = $this->application->all($namespace);
            foreach ($commands[$namespace] as $command) {
                $messages[$command->getName()] = [
                    'description' => $command->getDescription(),
                    'help' => $command->getHelp()
                ];
            }
        }

        file_put_contents($fn = $this->projectDir . sprintf('/translations/commands.%s.yaml', 'en'), Yaml::dump($messages));
        $io->success(sprintf('File %s written', $fn));

        return Command::SUCCESS;
    }
}
