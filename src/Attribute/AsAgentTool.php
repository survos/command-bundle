<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Attribute;

/**
 * Opts a console command in as an MCP tool. Put it next to #[AsCommand] on the command method.
 * The tool's input schema comes from the command's own #[Argument]/#[Option] definitions
 * (#[MapInput] DTOs included); calls run the command with --format=json.
 *
 * Commands that can't carry the attribute (vendor commands) opt in through
 * survos_command.agent_tools instead. Nothing else is ever exposed.
 *
 * Access is per tool and closed by default: every tool requires ROLE_ADMIN (or the role it names)
 * unless it is marked public — which only a read-only tool can be. Tags or media can be public;
 * a list of users stays admin simply by not saying otherwise.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class AsAgentTool
{
    public function __construct(
        public ?string $name = null,          // default: the command name, "app:add-page" → "app_add_page"
        public ?string $description = null,  // default: the command's description + help
        public bool $readOnly = false,
        public bool $destructive = false,
        public bool $idempotent = false,
        public bool $public = false,          // no sign-in needed; read-only tools only
        public ?string $role = null,          // checked on every call unless public; null = ROLE_ADMIN
    ) {}

    /** @return array{name: ?string, description: ?string, readOnly: bool, destructive: bool, idempotent: bool, public: bool, role: ?string} */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
