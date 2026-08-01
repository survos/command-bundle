<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application as FrameworkConsoleApplication;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Human/agent-readable outline of every console command in this app --
 * name, description, arguments, options -- grouped by namespace prefix.
 *
 * The sibling of field-bundle's field:routes:sitemap, but for the console
 * surface instead of the web surface. Meant to be read by an AI agent
 * before starting work in an unfamiliar app ("what commands already exist
 * -- especially data import tools -- before I write a new one?"), so it's
 * unfiltered by CommandController's namespaces allow-list (that config
 * exists to keep the secured *web runner* from exposing dangerous
 * commands; this is a read-only report, nothing here can be triggered
 * from it).
 */
#[AsCommand('commands:sitemap', 'Render a human/agent-readable outline of every console command (description, args, options), grouped by namespace.')]
final class CommandSitemapCommand
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Only include commands whose name starts with this namespace prefix, e.g. dataset')] ?string $namespace = null,
        #[Option('Write markdown to a file instead of stdout')] ?string $output = null,
        #[Option('Include full argument/option definitions, not just the one-line description')] bool $detailed = false,
    ): int {
        $application = new FrameworkConsoleApplication($this->kernel);
        $application->setAutoExit(false);

        // Application::all() keys by every name a command answers to
        // (primary name + aliases), so the same Command object shows up
        // more than once -- dedupe by object identity, keyed by its
        // canonical name so aliases don't produce duplicate rows.
        $seen = [];
        foreach ($application->all() as $command) {
            $seen[spl_object_id($command)] = $command;
        }

        $groups = [];
        foreach ($seen as $command) {
            if ($command->isHidden()) {
                continue;
            }

            $name = (string) $command->getName();
            if ($name === '' || $name === 'completion' || $name === 'help' || $name === 'list') {
                continue;
            }

            $prefix = str_contains($name, ':') ? substr($name, 0, strpos($name, ':')) : 'other';
            if ($namespace !== null && $prefix !== $namespace) {
                continue;
            }

            $groups[$prefix][] = $command;
        }

        ksort($groups);
        foreach ($groups as $prefix => $commands) {
            usort($commands, static fn(Command $a, Command $b) => (string) $a->getName() <=> (string) $b->getName());
            $groups[$prefix] = $commands;
        }

        $total = array_sum(array_map('count', $groups));

        $lines = [];
        $lines[] = '# Command Sitemap';
        $lines[] = '';
        $lines[] = sprintf('%d commands across %d namespace(s).', $total, \count($groups));
        $lines[] = '';

        foreach ($groups as $prefix => $commands) {
            $lines[] = "## {$prefix}";
            $lines[] = '';
            foreach ($commands as $command) {
                $lines[] = self::renderCommand($command, $detailed);
            }
            $lines[] = '';
        }

        $markdown = implode("\n", $lines);

        if ($output !== null && $output !== '') {
            file_put_contents($output, $markdown);
            $io->success("Wrote command sitemap to {$output}");
            return Command::SUCCESS;
        }

        $io->writeln($markdown);
        return Command::SUCCESS;
    }

    private static function renderCommand(Command $command, bool $detailed): string
    {
        $name = (string) $command->getName();
        $description = $command->getDescription() ?: '(no description set)';

        if (!$detailed) {
            return "- **{$name}** — {$description}";
        }

        $lines = ["- **{$name}** — {$description}"];
        $definition = $command->getDefinition();

        foreach ($definition->getArguments() as $arg) {
            $req = $arg->isRequired() ? 'required' : 'optional';
            $lines[] = sprintf('  - arg `%s` (%s) — %s', $arg->getName(), $req, $arg->getDescription() ?: '—');
        }

        foreach ($definition->getOptions() as $opt) {
            $lines[] = sprintf('  - `--%s` — %s', $opt->getName(), $opt->getDescription() ?: '—');
        }

        return implode("\n", $lines);
    }
}
