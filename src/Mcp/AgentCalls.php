<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Mcp;

use Survos\CommandBundle\Attribute\AsAgentTool;
use Survos\CommandBundle\Entity\CommandProcess;
use Survos\CommandBundle\Repository\CommandProcessRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The audit trail of agent (MCP) calls: who ran which command, with what, and how it ended.
 * Rows are written by CommandToolLoader; this reads them back.
 */
final readonly class AgentCalls
{
    public function __construct(private CommandProcessRepository $processes) {}

    #[AsCommand('agent:calls', 'List recent agent (MCP) tool calls: caller, command line, status, timing')]
    #[AsAgentTool('agent_calls', readOnly: true)]
    public function list(
        SymfonyStyle $io,
        #[Option('Only calls of this command, e.g. "app:add-page"')] ?string $command = null,
        #[Option('Only calls by this caller (a user identifier, or "anonymous")')] ?string $caller = null,
        #[Option('Only failed or refused calls')] bool $failed = false,
        #[Option('Include each call\'s recorded output')] bool $output = false,
        #[Option('How many calls, newest first')] int $limit = 20,
        #[Option('Output format: text or json')] string $format = 'text',
    ): int {
        $calls = array_map(fn (CommandProcess $p) => array_filter([
            'id' => $p->id,
            'at' => $p->createdAt->format(\DATE_ATOM),
            'caller' => $p->caller,
            'command' => $p->command,
            'cli' => $p->cli,
            'status' => $p->status->value,
            'exitCode' => $p->exitCode,
            'durationMs' => $p->durationMs,
            'failure' => $p->failureMessage,
            'output' => $output ? $p->output : null,
        ], fn ($v) => null !== $v), $this->processes->findAgentCalls($command, $caller, $failed, max(1, min($limit, 500))));

        if ('json' === $format) {
            $io->writeln(json_encode(['calls' => $calls], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }
        if (!$calls) {
            $io->note('No agent calls recorded.');

            return Command::SUCCESS;
        }
        $io->table(['at', 'caller', 'status', 'ms', 'command line'], array_map(fn (array $c) => [
            substr($c['at'], 0, 19),
            $c['caller'] ?? '-',
            $c['status'].(isset($c['failure']) ? ': '.mb_strimwidth($c['failure'], 0, 50, '…') : ''),
            $c['durationMs'] ?? '-',
            mb_strimwidth($c['cli'] ?? $c['command'], 0, 90, '…'),
        ], $calls));
        if ($output) {
            foreach ($calls as $c) {
                $io->section(sprintf('%s %s', substr($c['at'], 0, 19), $c['command']));
                $io->writeln($c['output'] ?? '(no output)');
            }
        }

        return Command::SUCCESS;
    }
}
