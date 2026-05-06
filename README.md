# Command Bundle

Run Symfony command line programs from a web interface, for easier debugging.

## Requirements

- PHP 8.4+
- Symfony 8.0+

Long-running commands: see https://github.com/symfony/symfony/discussions/59696. The bundle also has a "Dispatch via Messenger (async)" checkbox on the run form, which sends a `CommandMessage` so the command runs out of the request cycle.


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

