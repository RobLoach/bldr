<?php

/**
 * This file is part of Bldr.io
 *
 * (c) Aaron Scherer <aequasi@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE
 */

namespace Bldr\Command;

use Bldr\Application;
use Bldr\Call\CallInterface;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Aaron Scherer <aequasi@gmail.com>
 */
class BuildCommand extends AbstractCommand
{
    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $config = Application::$CONFIG;

        $this->setName('build')
            ->setDescription("Builds the project for the directory you are in. Must contain a {$config} file.")
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Profile to run', 'default')
            ->addOption('tasks', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Tasks to run')
            ->setHelp(
                <<<EOF

The <info>%command.name%</info> builds the current project, using the {$config} file in the root directory.

To use:

    <info>$ bldr %command.full_name% </info>

To specify a profile:

    <info>$ bldr %command.full_name% profile_name</info>

To specify tasks to run:

    <info>$ bldr %command.full_name% --tasks=task_name</info>
    <info>$ bldr %command.full_name% --tasks=task_name -t second_task</info>
    <info>$ bldr %command.full_name% --tasks=task_name,second_task</info>

EOF
            );
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln(["\n", Application::$logo, "\n"]);

        $config =
            $this->getApplication()
                ->getConfig();
        if ([] === $tasks = $input->getOption('tasks')) {

            $profileName = $input->getOption('profile');
            $profile     = $config->get('profiles')[$profileName];
            $tasks       = $profile['tasks'];

            /** @var FormatterHelper $formatter */
            $formatter = $this->getHelper('formatter');

            $projectFormat = [
                sprintf("Building the '%s' project", $config->get('name'))
            ];
            if ($config->has('description')) {
                $projectFormat[] = sprintf(" - %s - ", $config->get('description'));
            }

            $profileFormat = [
                sprintf("Using the '%s' profile", $profileName)
            ];
            if (isset($profile['description'])) {
                $profileFormat[] = sprintf(" - %s - ", $profile['description']);
            }

            $output->writeln(
                [
                    "",
                    $formatter->formatBlock($projectFormat, 'bg=blue;fg=black'),
                    "",
                    $formatter->formatBlock($profileFormat, 'bg=green;fg=white'),
                    ""
                ]
            );
        } else {
            if (sizeof($tasks) === 1 && strpos($tasks[0], ',') !== false) {
                $tasks = explode(',', $tasks[0]);
            }
        }

        try {
            $this->runTasks($input, $output, $tasks);
        } catch (\Exception $e) {
            throw $e;
        }

        return $this->succeedBuild($output);
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param array           $tasks
     */
    private function runTasks(InputInterface $input, OutputInterface $output, array $tasks)
    {
        foreach ($tasks as $task) {
            $this->runTask($input, $output, $task);
        }
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param string          $taskName
     *
     * @throws \Exception
     */
    private function runTask(InputInterface $input, OutputInterface $output, $taskName)
    {
        $config = $this->getApplication()->getConfig();
        $task   = $config->get('tasks')[$taskName];

        $output->writeln(
            [
                "",
                sprintf(
                    "<info>Running the %s task</info>\n<comment>%s</comment>",
                    $taskName,
                    isset($task['description']) ? '> ' . $task['description'] : ''
                ),
                ""
            ]
        );

        foreach ($task['calls'] as $call) {
            $this->runCall($input, $output, $call, $taskName, $task);
        }
        $output->writeln("");
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param array           $call
     * @param string          $taskName
     * @param array           $task
     */
    private function runCall(InputInterface $input, OutputInterface $output, array $call, $taskName, array $task)
    {

        $config = $this->getApplication()->getConfig();

        $service = $this->fetchServiceForCall($call['type']);

        $service->initialize($input, $output, $this->getHelperSet(), $config);
        $service->setTask($taskName, $task);
        $service->setFailOnError(isset($call['failOnError']) ? $call['failOnError'] : false);
        $service->setSuccessStatusCodes(isset($call['successCodes']) ? $call['successCodes'] : [0]);

        if (method_exists($service, 'setFileset') && isset($call['fileset'])) {
            $service->setFileset($call['fileset']);
        }

        $service->run($call['arguments']);
        $output->writeln("");
    }

    /**
     * @param string $type
     *
     * @return CallInterface
     * @throws \Exception
     */
    private function fetchServiceForCall($type)
    {
        $services = array_keys($this->container->findTaggedServiceIds($type));

        if (sizeof($services) > 1) {
            throw new \Exception("Multiple calls exist with the 'exec' tag.");
        }
        if (sizeof($services) === 0) {
            throw new \Exception("No task type found for {$type}.");
        }

        return $this->container->get($services[0]);
    }

    /**
     * @param OutputInterface $output
     *
     * @return int
     */
    public function succeedBuild(OutputInterface $output)
    {
        /** @var FormatterHelper $formatter */
        $formatter = $this->getHelper('formatter');

        $output->writeln(
            [
                "",
                $formatter->formatBlock(
                    [
                        "Build Success!",
                    ],
                    'bg=green;fg=white'
                ),
                ""
            ]
        );

        return 0;
    }
}
