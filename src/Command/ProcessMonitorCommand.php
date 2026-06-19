<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\CommandBundle\Entity\CommandProcess;
use Survos\CommandBundle\Enum\RunStatus;
use Survos\CommandBundle\Repository\CommandProcessRepository;
use Survos\TuiExtrasBundle\Event\TreeNodeChangeEvent;
use Survos\TuiExtrasBundle\Model\TreeNode;
use Survos\TuiExtrasBundle\Widget\DetailPanelWidget;
use Survos\TuiExtrasBundle\Widget\TreeWidget;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Live TUI of recorded command processes — the place to watch long, slow background jobs.
 *
 * A collapsible tree groups runs by command name; each child is one run, labelled with its options
 * and a status glyph, ordered most-recent-first. The right pane shows the selected run's detail
 * (cli, status, timing, slots, captured output). It re-queries on an interval but only rebuilds the
 * tree when something actually changed (a status flip / new run), so the cursor stays put while you
 * watch. Requires survos/tui-extras-bundle (symfony/tui); degrades with a clear message otherwise.
 *
 * Keys: ↑↓/jk navigate · →/← expand/collapse · q quit.
 */
final class ProcessMonitorCommand
{
    public function __construct(
        private readonly CommandProcessRepository $processes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[AsCommand('command:monitor', 'Live TUI of command processes, grouped by command', aliases: ['monitor'])]
    public function __invoke(
        OutputInterface $output,
        #[Option('Refresh interval in seconds')] float $interval = 2.0,
        #[Option('Max processes to load')] int $limit = 200,
    ): int {
        if (!class_exists(Tui::class) || !class_exists(TreeWidget::class)) {
            $output->writeln('<error>command:monitor needs the TUI widgets — composer require survos/tui-extras-bundle.</error>');

            return Command::FAILURE;
        }

        /** @var array<string,CommandProcess> $byId */
        $byId = [];

        $stylesheet = new StyleSheet([
            TreeWidget::class.'::branch-expanded'  => new Style(bold: true, color: 'cyan'),
            TreeWidget::class.'::branch-collapsed' => new Style(color: 'cyan'),
            TreeWidget::class.'::leaf'             => new Style(),
            TreeWidget::class.'::selected'         => new Style(reverse: true, bold: true),
            DetailPanelWidget::class.'::title'     => new Style(bold: true, color: 'cyan'),
            DetailPanelWidget::class.'::separator' => new Style(dim: true),
        ]);

        $detail = new DetailPanelWidget();
        $detail->setContent('Select a run to see its detail.', 'Processes');

        $updateDetail = static function (?TreeNode $node) use ($detail, &$byId): void {
            $process = ($node !== null && $node->isLeaf()) ? ($byId[$node->data] ?? null) : null;
            if ($process instanceof CommandProcess) {
                $detail->setContent(self::detailText($process), $process->command);
            }
        };

        $tree = new TreeWidget();
        $tree->onCursorChange(static function (TreeNodeChangeEvent $event) use ($updateDetail): void {
            $updateDetail($event->node);
        });

        $snap = $this->snapshot($limit);
        $byId = $snap['byId'];
        $lastSig = $snap['sig'];
        $tree->setRoots($snap['roots']);

        $split = (new ContainerWidget())->setStyle(new Style(direction: Direction::Horizontal, gap: 1));
        $split->add($tree->setStyle(new Style(maxColumns: 52)));
        $split->add($detail);

        $tui = new Tui($stylesheet);
        $tui->add($split);
        $tui->setFocus($tree);

        // Live refresh: only rebuild (which resets the cursor) when the data actually changed.
        $tui->scheduleInterval(function () use ($tree, $limit, &$byId, &$lastSig, $updateDetail): void {
            $snap = $this->snapshot($limit);
            if ($snap['sig'] === $lastSig) {
                return;
            }
            $lastSig = $snap['sig'];
            $byId = $snap['byId'];
            $tree->setRoots($snap['roots']);
            $updateDetail($tree->getCursorNode());
        }, max(0.5, $interval));

        $tui->run();

        return Command::SUCCESS;
    }

    /**
     * @return array{roots: list<TreeNode>, byId: array<string,CommandProcess>, sig: string}
     */
    private function snapshot(int $limit): array
    {
        // Long-running process: drop the identity map so re-queries reflect worker writes, not
        // stale first-load state.
        $this->em->clear();

        $byId = [];
        $groups = [];
        $sigParts = [];
        foreach ($this->processes->findRecent(null, $limit) as $p) {
            $byId[$p->id] = $p;
            $groups[$p->command][] = $p;
            $sigParts[] = $p->id.':'.$p->status->value;
        }

        $roots = [];
        foreach ($groups as $command => $runs) {
            $branch = TreeNode::branch(
                sprintf('%s %s  (%d)', self::glyph($runs[0]->status), $command, \count($runs)),
                $command,
                expanded: true,
            );
            foreach ($runs as $p) {
                $branch->addChild(TreeNode::leaf(self::runLabel($p), $p->id));
            }
            $roots[] = $branch;
        }

        return ['roots' => $roots, 'byId' => $byId, 'sig' => implode('|', $sigParts)];
    }

    private static function runLabel(CommandProcess $p): string
    {
        $cli  = (string) ($p->cli ?? $p->command);
        $args = trim(str_starts_with($cli, $p->command) ? substr($cli, \strlen($p->command)) : $cli);
        $when = $p->durationMs !== null
            ? self::humanMs($p->durationMs)
            : ($p->startedAt?->format('H:i:s') ?? 'queued');

        return trim(sprintf('%s %s  %s', self::glyph($p->status), $args !== '' ? $args : '(no args)', $when));
    }

    private static function detailText(CommandProcess $p): string
    {
        $lines = [
            sprintf('%s %s', self::glyph($p->status), $p->status->value),
            sprintf('cli       bin/console %s', $p->cli ?? $p->command),
            sprintf('mode      %s', $p->mode->value),
            sprintf('exit      %s', $p->exitCode ?? '—'),
            sprintf('duration  %s', $p->durationMs !== null ? self::humanMs($p->durationMs) : '—'),
            sprintf('started   %s', $p->startedAt?->format('Y-m-d H:i:s') ?? '—'),
            sprintf('finished  %s', $p->finishedAt?->format('Y-m-d H:i:s') ?? '—'),
            sprintf('host/pid  %s / %s', $p->host ?? '—', $p->pid ?? '—'),
        ];

        if ($p->slots) {
            $lines[] = '';
            foreach ($p->slots as $name => $value) {
                $lines[] = sprintf('[%s] %s', $name, $value);
            }
        }

        if ($p->failureMessage) {
            $lines[] = '';
            $lines[] = $p->failureMessage;
        }

        $lines[] = '';
        $lines[] = '── output ──';
        $lines[] = $p->output ?: '(no captured output)';

        return implode("\n", $lines);
    }

    private static function glyph(RunStatus $status): string
    {
        return match ($status) {
            RunStatus::Succeeded => '✓',
            RunStatus::Failed    => '✗',
            RunStatus::Running   => '▶',
            RunStatus::Pending   => '○',
            RunStatus::Canceled  => '⊘',
        };
    }

    private static function humanMs(int $ms): string
    {
        if ($ms < 1000) {
            return $ms.'ms';
        }
        $seconds = $ms / 1000;
        if ($seconds < 60) {
            return round($seconds, 1).'s';
        }

        return floor($seconds / 60).'m'.str_pad((string) ((int) $seconds % 60), 2, '0', \STR_PAD_LEFT).'s';
    }
}
