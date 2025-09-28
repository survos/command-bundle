<?php

namespace Survos\CommandBundle\Controller;

use Survos\CommandBundle\Form\CommandFormType;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Survos\CommandBundle\CommandRunner;
class CommandController extends AbstractController
{

    private readonly Application $application;

    public function __construct(private readonly KernelInterface $kernel,
                                private readonly ?MessageBusInterface $bus=null,
                                private readonly array $namespaces=[],
                                private array $config=[]
    )
    {
        $this->application = new Application($this->kernel);
    }

    #[Route('/', name: 'survos_commands')]
    public function commands(): Response
    {

        $commands = [];
        foreach ($this->namespaces as $namespace) {
            $commands[$namespace] = $this->application->all($namespace);
        }
        // from the bundle get the regex of allowable commands?
        return $this->render('@SurvosCommand/index.html.twig', [
            'commands' => $commands
        ]);
    }

    #[Route(path: '/run/{commandName}', name: 'survos_command')]
    public function runCommand(Request $request, KernelInterface $kernel, string $commandName): Response|array
    {
//        $commandName = $request->get('commandName');
        $application = new Application($kernel);
        $command = $application->get($commandName);

        chdir($kernel->getProjectDir());

        /** @var InputDefinition $definition */
        $definition = $command->getDefinition();

        // so we can preset some options in the querystring
        $defaults = $request->query->all();

//        $option = $definition->getOption('createProjects');
//        assert($option->getDefault() === true);
//        dd($command::class, $definition::class);
        if(isset($defaults['reset'])) {
            $defaults['reset'] = filter_var($defaults['reset'], FILTER_VALIDATE_BOOLEAN);
        }
        if(isset($defaults['dryRun'])) {
            $defaults['dryRun'] = filter_var($defaults['dryRun'], FILTER_VALIDATE_BOOLEAN);
        }
        if(isset($defaults['asMessage'])) {
            $defaults['asMessage'] = filter_var($defaults['asMessage'], FILTER_VALIDATE_BOOLEAN);
        }
        // load from request? for command?
        foreach (array_merge($definition->getArguments(), $definition->getOptions()) as $cliArgument) {
            $value = $defaults[$cliArgument->getName()] ?? null;
            if (!$value) {
                $defaults[$cliArgument->getName()] = $cliArgument->getDefault();
            }
        }

//        dd($request);

        $cliString = $commandName;

        $form = $this->createForm(CommandFormType::class, $defaults, ['command' => $command, 'hasBus' => (bool)$this->bus]);
        $form->handleRequest($request);
        $result = '';
        if ($form->isSubmitted() && $form->isValid()) {
            $output = new BufferedOutput();

            $settings = $form->getData();
            $cli[] = $commandName;
            foreach ($definition->getArguments() as $cliArgument) {
                $cli[] = $this->quotify($settings[$cliArgument->getName()]);
            }
            foreach ($definition->getOptions() as $cliOption) {
                $optionName = $cliOption->getName();
                $value = $settings[$optionName]; // @todo: arrays
                if ($cliOption->isValueOptional()) {
                    if ($value) {
                        $cli[] = '--' . $optionName . ' ' . $this->quotify($value);
                    }
                } elseif ($cliOption->isNegatable()) {
                    if ($value === true) {
                        $cli[] = '--' . $optionName;
                    } elseif ($value === false) {
                        $cli[] = '--no-' . $optionName;
                    }
                } else {
                    if ($value <> '' && !is_bool($value)) {
                        if (is_array($value)) {
                            foreach ($value as $valueItem) {
                                $cli[] = '--' . $optionName . ' ' . $valueItem;
                            }
                        } else {
                            $cli[] = '--' . $optionName . ' ' . $this->quotify($value);
                        }
                    } elseif ($value)  {
                        $cli[] = '--' . $optionName;
                    }
                }
            }

            $cliString = implode(' ', $cli);
            if ($form->has('asMessage') && $form->get('asMessage')->getData()) {
                $envelope = $this->bus->dispatch(new RunCommandMessage($cliString));
//                dump($envelope);
                $result = "$cliString dispatched ";
            } else {
                    CommandRunner::from($application, $cliString)
                        ->withOutput($output) // any OutputInterface
                        ->run();
//                    dump($output);
//                try {
//                } catch (\Exception $exception) {
////                    dd($cliString, $exception->getMessage());
//                }
                $result = $output->fetch();
            }
//            try {
//            } catch (\Exception $exception) {
////                dd($cliString, $command, $application, $exception->getMessage());
//            }

//                CommandRunner::for($command, 'Bob p@ssw0rd --role ROLE_ADMIN')->run(); // works great
        }

//        CommandRunner::from($application, 'my:command --help')
//            ->run();
//
//        CommandRunner::for($command, '--help')->run(); // fails, says --help isn't defined

        return $this->render('@SurvosCommand/run.html.twig', [
            'base' => $this->config['base_layout'],
            'cliString' => $cliString,
            'form' => $form->createView(),
            'result' => $result,
            'command' => $command
        ]);
    }

    private function quotify(string|int|null $value): string

    {
        $value = (string)$value;
        return str_contains($value, ' ') ? sprintf('"%s"', $value) : (string)$value;
    }
}
