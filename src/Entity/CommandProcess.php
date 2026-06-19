<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\CommandBundle\Enum\RunMode;
use Survos\CommandBundle\Enum\RunStatus;
use Survos\CommandBundle\Repository\CommandProcessRepository;
use Survos\FieldBundle\Attribute\EntityMeta;
use Symfony\Component\Uid\Ulid;

/**
 * Registry record for ONE command invocation — a "process" in the run table.
 *
 * Persisted (rather than file-logged) so runs can be monitored remotely: list/filter by status,
 * drill into captured output, see exit code and timing. Identity (command + how it was launched)
 * is set at construction; the lifecycle fields (status, timings, output, exit code) are filled in
 * as the process runs by the recorder — wiring of WHEN/HOW that happens is deliberately deferred.
 */
#[EntityMeta(icon: 'tabler:terminal-2', group: 'Commands')]
#[ORM\Entity(repositoryClass: CommandProcessRepository::class)]
#[ORM\Table(name: 'command_process')]
#[ORM\Index(fields: ['status'],    name: 'idx_command_process_status')]
#[ORM\Index(fields: ['command'],   name: 'idx_command_process_command')]
#[ORM\Index(fields: ['createdAt'], name: 'idx_command_process_created')]
class CommandProcess
{
    #[ORM\Id]
    #[ORM\Column(length: 26)]
    public private(set) string $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) \DateTimeImmutable $createdAt;

    public function __construct(
        /** Command name, e.g. 'load:Glam'. */
        #[ORM\Column(length: 255)]
        public private(set) string $command,

        /** Full CLI string as launched, e.g. 'load:Glam --limit=5' — for display and re-run. */
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        public private(set) ?string $cli = null,

        /** How the process was launched. */
        #[ORM\Column(length: 16, enumType: RunMode::class)]
        public private(set) RunMode $mode = RunMode::Sync,

        /** Where it ran — hostname / dokku container. */
        #[ORM\Column(length: 128, nullable: true)]
        public private(set) ?string $host = null,

        /** OS process id, when run as a detached background process. */
        #[ORM\Column(nullable: true)]
        public ?int $pid = null,

        /** Optional ULID to pin the id; when null a fresh one is generated. */
        ?string $id = null,
    ) {
        $this->id        = $id ?? (string) new Ulid();
        $this->createdAt = new \DateTimeImmutable();
    }

    // ── Lifecycle state (mutated by the recorder as the process runs) ────────────

    #[ORM\Column(length: 16, enumType: RunStatus::class)]
    public RunStatus $status = RunStatus::Pending;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(nullable: true)]
    public ?int $exitCode = null;

    /** Peak memory in bytes (format for display with zenstruck/bytes). */
    #[ORM\Column(nullable: true)]
    public ?int $memoryBytes = null;

    /** Captured console output, carriage-return collapsed (progress bars → final line). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $output = null;

    /** Exception class + message when the process failed. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $failureMessage = null;

    /**
     * Named status fragments the running command pushes via logger context, e.g.
     * `$logger->info($url, ['tui.slot' => 'header'])` sets slots['header'] = $url. The monitor
     * (web pane today, symfony/tui tomorrow) shows these prominently so you see the current step
     * without scrolling the log. Rendering/styling is the app's job (per-slot CSS hooks).
     *
     * @var array<string,string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $slots = null;

    // ── Computed (virtual — not mapped) ──────────────────────────────────────────

    /** Wall-clock duration in milliseconds, once both ends are known. */
    public ?int $durationMs {
        get => $this->startedAt !== null && $this->finishedAt !== null
            ? (int) ($this->finishedAt->format('Uv') - $this->startedAt->format('Uv'))
            : null;
    }
}
