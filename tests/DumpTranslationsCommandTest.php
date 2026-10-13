<?php

declare(strict_types=1);

namespace Survos\CommandBundle\Tests;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class DumpTranslationsCommandTest extends KernelTestCase
{
    public function testExecute(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('survos:command:dump-as-messages');
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            '--namespace' => 'survos',
        ]);

        $commandTester->assertCommandIsSuccessful();
    }
}
