# Command Bundle

Run, background, and monitor Symfony console commands. Four things:

1. **Web command runner** — run any `#[AsCommand]` from a web page (with the Symfony profiler available), for easier debugging.
2. **Background runner** — `bg:run "<cmd>"` dispatches a command to an always-on Messenger worker so long jobs survive logout / container teardown.
3. **Process registry + monitor** — every background run is recorded as a `CommandProcess` (status, timing, output, named "slots"); watch them live in a TUI (`bg:monitor`) or the web list (`/…/processes`).
4. **Agent tools (MCP)** — opted-in commands become MCP tools on symfony/mcp-bundle's server, with the schema derived from the command's own `#[Argument]`/`#[Option]` attributes. See [Agent tools](#agent-tools-mcp).

## Requirements

- PHP 8.4+, Symfony 8.1+
- Doctrine ORM (ships the `CommandProcess` entity)
- `symfony/messenger` (for `bg:run` and background recording)
- `survos/tui-extras-bundle` — **optional**, only for the `bg:monitor` TUI (require-dev/suggest)

## Commands

| Command | What |
|---|---|
| `bg:run "<cmd>"` | Dispatch `<cmd>` to the `command` Messenger worker. One message per command (fan out). |
| `bg:monitor` (alias `monitor`) | Live TUI of background runs, grouped by command, status by glyph, most-recent-first. Needs `survos/tui-extras-bundle`. |

`bg:run` requires the app to route `RunCommandMessage` to an async transport and run a worker:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            command: 'doctrine://default?queue_name=command'
        routing:
            'Symfony\Component\Console\Messenger\RunCommandMessage': command
```

```bash
php bin/console messenger:consume command -vv     # local worker (normal verbosity — see note)
dokku ps:scale <app> command=1                    # prod worker
```

> **Verbosity note:** captured output inherits the worker's verbosity. `messenger:consume -q` makes the run's output QUIET (nothing captured); run at normal verbosity to record output.

## Process registry & slots

Background runs are recorded in the `command_process` table (only `bg:run` runs — plain CLI/web runs are not recorded). A command can push a styled, named status fragment to the monitor with plain PSR-3 logging:

```php
$logger->info($institution, ['tui.slot' => 'header']);   // → process.slots['header'], shown live
```

## Configuration

```yaml
survos_command:
    routes_enabled: false          # OFF by default — these routes RUN console commands (footgun)
    route_prefix: /admin/commands  # keep behind a secured prefix
    base_layout: ~                 # app layout for the web pages (must load importmap/Stimulus)
    track: true                    # record bg runs as CommandProcess + enable the tui.slot handler
    namespaces: []                 # web UI: only list commands in these namespaces ([] = all)
```

## Upgrading (process registry)

This bundle now ships the `CommandProcess` Doctrine entity and requires `doctrine/orm`, `survos/field-bundle`, `symfony/messenger`, and `symfony/uid`. After updating, create the table:

- **SQLite (dev):** `bin/console doctrine:schema:update --force`
- **PostgreSQL / shared:** `bin/console doctrine:migrations:diff` → review → migrate

Routes default to **off** — set `survos_command.routes_enabled: true` (under a secured prefix) to use the web UI/monitor.

---

## Agent tools (MCP)

Agents run commands you'd otherwise run over SSH. With [symfony/mcp-bundle](https://symfony.com/doc/current/ai/cookbook/build-an-mcp-server.html)
installed, **explicitly opted-in** commands become tools on its `/mcp` server. Nothing else is exposed.

**Opt in your own command** with `#[AsAgentTool]` next to `#[AsCommand]`:

```php
#[AsCommand('app:add-page', 'Add or update a headlines page to scan', help: '…')]
#[AsAgentTool('add_page', idempotent: true)]
public function addPage(SymfonyStyle $io, #[MapInput] PageInput $page,
                        #[Option('Output format: text or json')] string $format = 'text'): int
```

**Opt in a command you can't annotate** (vendor commands) in config:

```yaml
survos_command:
    agent_tools:
        - { command: 'messenger:stats', readOnly: true, public: true }   # tool "messenger_stats", open
        - { command: 'app:purge', destructive: true }                    # needs ROLE_ADMIN
```

How it works:

- The tool's **input schema is the command's native `InputDefinition`**: arguments (required ones stay required), options, repeatable options as arrays, flags as booleans, and `BackedEnum` options as a schema `enum`. The description is the command's description + help. `#[MapInput]` DTOs work, so inputs are declared once.
- A call runs the command **in-process** (`ConsoleCommandExecutor`) with `--format=json` when the command has a `--format` option, and returns the decoded JSON, or `{output: "…"}` when the output isn't JSON. `--format=json` is also how you debug the same call from the CLI: same command, different caller.
- Errors (exceptions, non-zero exit, unknown parameters) come back to the agent as readable tool errors.

### Security

Access is decided **per tool, closed by default**: every tool requires `ROLE_ADMIN` (or the `role:` it names), checked on every call. A tool that exposes nothing sensitive opts out with `public: true`, and only a `readOnly` tool may (enforced at container compile):

```php
#[AsAgentTool(readOnly: true, public: true)]    // tags, media, queue counts: open
#[AsAgentTool(readOnly: true)]                  // a list of users: still ROLE_ADMIN
#[AsAgentTool(idempotent: true)]                // a write: ROLE_ADMIN
```

The check fails closed: without symfony/security-bundle, a non-public tool is denied. **Plain `#[McpTool]` methods are not gated by the bridge**, and with an open `/mcp` they're open to everyone, so on such a server write lookups as commands with `#[AsAgentTool]` too: one gate for every tool, and each one is a CLI command you can debug.

Signing in: a stateless `access_token` firewall on `/mcp`. `AgentTokenHandler` signs the bearer token in as a real user from your provider, so calls carry that user's roles. The endpoint itself stays open, so read tools work without a token:

```yaml
# config/packages/security.yaml
firewalls:
    mcp:
        pattern: ^/mcp
        stateless: true
        provider: app_user_provider
        access_token:
            token_handler: Survos\CommandBundle\Security\AgentTokenHandler
    # main: …
access_control:
    - { path: ^/mcp, roles: PUBLIC_ACCESS }   # each tool gates itself
```
```yaml
# config/packages/survos_command.yaml
survos_command:
    agent:
        token: '%env(default::AGENT_TOKEN)%'   # unset = no token accepted
        user: 'admin@example.com'
```
Connect: `claude mcp add --transport http myapp https://myapp.example/mcp --header "Authorization: Bearer $AGENT_TOKEN"`

### Seeing what agents can do, and did

- `bin/console debug:mcp` (symfony/mcp-bundle) lists every tool on the server; `debug:mcp <tool>` shows its description and input schema.
- `bin/console agent:calls` lists the calls agents made: caller, status, and the equivalent command line so any call can be re-run by hand. Options: `--command`, `--caller`, `--failed`, `--output`, `--limit`, `--format=json`. It is itself an admin-only tool (`agent_calls`).

With `survos_command.track` on (the default), every tool call is a `CommandProcess` row in mode `agent`, refused calls included (failed, "Access denied…", caller `anonymous` when no token was sent). After upgrading, add the new column: `command_process.caller` (`doctrine:migrations:diff`).

OAuth (for claude.ai web/mobile connectors) is not wired: mcp/sdk ships resource-server middleware (JWT validation, protected-resource metadata), but it needs an identity provider to issue the tokens.

---

## Web command runner

Long-running commands: see https://github.com/symfony/symfony/discussions/59696. The run form also has a "Dispatch via Messenger (async)" checkbox.


## Purpose

Use assert(), dump() and dd() are quick and easy debug tools when debugging a Symfony web page.  But it's often difficult to use within the console, since the formatting is for a web page.

For example, in the official Symfony Demo, there is a command to send the list of users to an email  address.

```bash
bin/console app:list-users --send-to=admin@example.com
```

Debugging this is much easier with Symfony's Debug Toolbar, this bundle wraps the console commands with a web interface so that the toolbar is available.

```bash
symfony local:new --demo  --dir=symfony-demo
cd symfony-demo
composer require survos/command-bundle
```

Now go to /admin/commands and see what's available

![img.png](img.png)

Select list-users, and fill in the email.

![img_1.png](img_1.png)

Submit the form and open the debug toolbar:

![img_2.png](img_2.png)

With dumps and asserts, this is even more helpful.



## Example with Symfony Demo

composer config extra.symfony.allow-contrib true
composer config extra.symfony.endpoint --json '["https://raw.githubusercontent.com/symfony/recipes-contrib/flex/pull-1708/index.json", "flex://defaults"]'


```bash
composer create-project symfony/symfony-demo command-demo 
cd command-demo
sed -i 's/"php": "8.2.0"//' composer.json 
composer config extra.symfony.allow-contrib true
composer req survos/command-bundle
bin/console --version

symfony server:start -d
symfony open:local  --path admin/commands
```

## Defining commands

Symfony 8.1+ lets you mark methods on any service with `#[AsCommand]`, with arguments and options described via attributes. The web form rendered by this bundle introspects the same metadata the CLI uses, so no separate command class is required.

```php
namespace App\Command;

use App\Repository\PostRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

final class PostCommands
{
    public function __construct(private PostRepository $posts) {}

    #[AsCommand('app:list-posts', 'List the posts')]
    public function list(
        SymfonyStyle $io,
        #[Option(description: 'Limit the number of posts')]
        int $limit = 50,
    ): void {
        $rows = array_map(
            fn ($p) => [$p->getId(), $p->getTitle(), $p->getAuthor()->getFullName()],
            $this->posts->findBy([], [], $limit),
        );
        $io->table(['id', 'title', 'author'], $rows);
    }
}
```

The same form supports a "Dispatch via Messenger (async)" checkbox for long-running work — wire up Messenger and the command runs in a worker rather than the request cycle.

## with castor

```bash
symfony new castor-command-demo --webapp && cd castor-command-demo
sed -i "s|# MAILER_DSN|MAILER_DSN|" .env
bin/console make:command app:castor-test
cat > castor.php <<'END'
<?php

use Castor\Attribute\AsTask;

use function Castor\io;
use function Castor\capture;
use function Castor\import;

import(__DIR__ . '/src/Command/CastorTestCommand.php');

#[AsTask(description: 'Welcome to Castor!')]
function hello(): void
{
    $currentUser = capture('whoami');

    io()->title(sprintf('Hello %s!', $currentUser));
}
END


```

Add attribute to AppCastorTest.php

```php
#[\Castor\Attribute\AsSymfonyTask()]
```

```bash
castor list
```

